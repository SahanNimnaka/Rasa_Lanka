<?php

namespace App\Services\Annotation;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\AnnotationValidationIssue;
use App\Models\AnnotationValidationRun;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
use App\Services\Annotation\Storage\Exceptions\ArtifactHashMismatchException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AnnotationValidationPersistenceService
{
    public function __construct(

        private readonly B1ValidationAdapter $adapter,

        private readonly AnnotationArtifactStore $store,

    ) {}

    /**
     * Phase A (no DB tx): resolve/verify staged artifact, execute B1, capture/persist exact report bytes.
     * Phase B (short DB tx): persist validation run/issues/audit atomically.
     *
     * Transaction-safe if called inside an outer DB transaction (I6 composition).
     *
     * @param  (\Closure(AnnotationValidationRun, B1ValidationResult, AnnotationRecord): void)|null  $withinPhaseBTransaction
     *                                                                                                                         Optional I6 hook executed inside the Phase B DB transaction after validation run/issues/audit are inserted.
     *                                                                                                                         It may perform only database mutations that must be atomic with validation persistence (for example
     *                                                                                                                         submission_state/lock_version updates). It must not run B1, write/delete filesystem artifacts, perform
     *                                                                                                                         HTTP requests, start external processes, or create duplicate validation runs/audit events. Throwing rolls
     *                                                                                                                         back all Phase B database writes.
     * @return array{
     *   run:AnnotationValidationRun,
     *   b1_result:B1ValidationResult,
     *   report_json:array{relative_path:string,sha256:string,bytes:int,idempotent:bool}|null,
     *   report_csv:array{relative_path:string,sha256:string,bytes:int,idempotent:bool}|null
     * }
     */
    public function persistStagedSubmissionAttempt(
        AnnotationRecord $record,
        string $submissionIntentId,
        string $stagedRelativePath,
        string $stagedSha256,
        ?int $executedByUserId = null,
        string $actorType = 'user',
        ?string $b1Version = null,
        ?\Closure $withinPhaseBTransaction = null,
    ): array {

        $record = $this->requireRecord($record);

        $submissionIntentId = $this->requireIdentifier($submissionIntentId, 'submission_intent_id');

        $stagedSha256 = $this->requireSha256($stagedSha256);

        $actorType = $this->normalizeActorType($actorType, $executedByUserId);

        $stagedRelativePath = $this->normalizeRelativePath($stagedRelativePath);

        $existing = $this->findExistingRunForAttempt((int) $record->id, $submissionIntentId);

        if ($existing !== null) {

            throw new RuntimeException('A validation run already exists for this record and submission intent.');
        }

        // Phase A

        $stagedAbsolutePath = $this->store->resolveRelativePath($stagedRelativePath, true);

        $actualSha = $this->store->readArtifactSha256($stagedRelativePath);

        if (! hash_equals($stagedSha256, $actualSha)) {

            throw new ArtifactHashMismatchException('Staged artifact SHA-256 does not match the expected submission intent hash.');
        }

        $runId = $this->generateRunId();

        $execution = $this->adapter->validateStagedArtifactWithReport($stagedAbsolutePath, $runId);

        $b1Result = $execution['result'];

        $reportJson = $execution['report_json'] ?? null;

        $reportCsv = $execution['report_csv'] ?? null;

        $reportJsonMeta = null;

        $reportCsvMeta = null;

        if ($b1Result->ok && is_string($reportJson) && $reportJson !== '') {

            $reportJsonMeta = $this->store->writeValidationReport((string) $record->record_id, $runId, 'step8b_validation_report.json', $reportJson);

        }

        if ($b1Result->ok && is_string($reportCsv) && $reportCsv !== '') {

            $reportCsvMeta = $this->store->writeValidationReport((string) $record->record_id, $runId, 'step8b_validation_issues.csv', $reportCsv);

        }

        $payload = $this->buildPersistencePayload(

            $record,

            $runId,

            $submissionIntentId,

            $stagedRelativePath,

            $stagedSha256,

            $executedByUserId,

            $actorType,

            $b1Version,

            $b1Result,

            $reportJsonMeta,

            $reportCsvMeta

        );

        // Phase B

        try {
            $run = DB::transaction(function () use ($record, $submissionIntentId, $payload, $b1Result, $withinPhaseBTransaction): AnnotationValidationRun {
                $duplicate = $this->findExistingRunForAttempt((int) $record->id, $submissionIntentId);

                if ($duplicate !== null) {
                    throw new RuntimeException('A validation run already exists for this record and submission intent.');
                }

                $run = new AnnotationValidationRun;
                $run->forceFill($payload['run']);
                $run->save();

                foreach ($payload['issues'] as $issue) {
                    $issueModel = new AnnotationValidationIssue;
                    $issueModel->forceFill($issue + ['validation_run_id' => $run->id]);
                    $issueModel->save();
                }

                AnnotationReviewAction::query()->create($payload['audit'] + [
                    'annotation_record_id' => $record->id,
                    'created_at' => now(),
                ]);

                if ($withinPhaseBTransaction !== null) {
                    $withinPhaseBTransaction($run, $b1Result, $record);
                }

                return $run->fresh(['issues']);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {

            if ($this->isDuplicateAttemptConstraintViolation($e, (int) $record->id, $submissionIntentId)) {

                throw new RuntimeException('A validation run already exists for this record and submission intent.', 0, $e);
            }

            throw $e;
        }

        return [

            'run' => $run,

            'b1_result' => $b1Result,

            'report_json' => $reportJsonMeta,

            'report_csv' => $reportCsvMeta,

        ];

    }

    private function requireRecord(AnnotationRecord $record): AnnotationRecord
    {

        if (! $record->exists || $record->id === null || ! is_string($record->record_id) || $record->record_id === '') {

            throw new RuntimeException('A persisted annotation record is required.');
        }

        return $record;

    }

    private function requireIdentifier(string $value, string $name): string
    {

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', $value) !== 1) {

            throw new RuntimeException('Invalid '.$name.'.');
        }

        return $value;

    }

    private function requireSha256(string $value): string
    {

        $value = strtolower(trim($value));

        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {

            throw new RuntimeException('Invalid SHA-256 hash.');
        }

        return $value;

    }

    private function normalizeActorType(string $actorType, ?int $executedByUserId): string
    {

        $actorType = strtolower(trim($actorType));

        if ($actorType !== 'user' && $actorType !== 'system') {

            throw new RuntimeException('Invalid actor type.');
        }

        if ($actorType === 'user' && $executedByUserId === null) {

            throw new RuntimeException('User actor requires executed_by_user_id.');
        }

        if ($actorType === 'system' && $executedByUserId !== null) {

            throw new RuntimeException('System actor cannot have executed_by_user_id.');
        }

        return $actorType;

    }

    private function normalizeRelativePath(string $relativePath): string
    {

        $relativePath = str_replace('\\', '/', trim($relativePath));

        if ($relativePath === '' || str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:\//', $relativePath) === 1) {

            throw new RuntimeException('Invalid staged artifact path.');
        }

        return $relativePath;

    }

    private function findExistingRunForAttempt(int $recordId, string $submissionIntentId): ?AnnotationValidationRun
    {

        return AnnotationValidationRun::query()

            ->where('annotation_record_id', $recordId)

            ->where('submission_intent_id', $submissionIntentId)

            ->first();

    }

    private function isDuplicateAttemptConstraintViolation(QueryException $e, int $recordId, string $submissionIntentId): bool
    {

        if (! $this->isUniqueConstraintViolation($e)) {

            return false;

        }

        $message = strtolower($e->getMessage());

        $sql = strtolower($e->getSql());

        $indexName = 'annotation_validation_runs_record_intent_unique';

        // Never translate other unique constraints on this table (e.g. run_id).

        if (str_contains($message, 'run_id') || str_contains($message, 'annotation_validation_runs_run_id_unique')) {

            return false;

        }

        $mentionsAttemptIndex = str_contains($message, $indexName);

        $mentionsAttemptColumns = str_contains($message, 'annotation_record_id')

            && str_contains($message, 'submission_intent_id');

        $mentionsTable = str_contains($message, 'annotation_validation_runs');

        $sqlTargetsTable = str_contains($sql, 'annotation_validation_runs');

        $isAttemptConstraint = $mentionsAttemptIndex

            || ($mentionsTable && $mentionsAttemptColumns)

            || ($sqlTargetsTable && $mentionsAttemptColumns);

        if (! $isAttemptConstraint) {

            return false;

        }

        // Authoritative post-rollback guard: the same non-null attempt identity must already exist.

        // This SELECT runs after DB::transaction() has rolled back the failed insert/savepoint.

        return AnnotationValidationRun::query()

            ->where('annotation_record_id', $recordId)

            ->where('submission_intent_id', $submissionIntentId)

            ->exists();

    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {

        if ($e instanceof UniqueConstraintViolationException) {

            return true;

        }

        $sqlState = $this->queryExceptionSqlState($e);

        $driverCode = $this->queryExceptionDriverCode($e);

        $message = strtolower($e->getMessage());

        // PostgreSQL unique_violation

        if ($sqlState === '23505') {

            return true;

        }

        // MySQL/MariaDB duplicate entry; SQLite constraint (driver code varies)

        if ($sqlState === '23000' && in_array($driverCode, [1062, 19, 2067, 1555], true)) {

            return true;

        }

        // SQL Server unique index/constraint violation

        if (in_array($driverCode, [2601, 2627], true)) {

            return true;

        }

        // Conservative message fallback for drivers that surface unique violations inconsistently.

        if (str_contains($message, 'unique constraint') || str_contains($message, 'duplicate key') || str_contains($message, 'duplicate entry')) {

            return $sqlState === null || $sqlState === '23000' || str_starts_with($sqlState, '23');

        }

        return false;

    }

    private function queryExceptionSqlState(QueryException $e): ?string
    {

        $errorInfo = $e->errorInfo;

        if (is_array($errorInfo) && isset($errorInfo[0]) && is_string($errorInfo[0]) && $errorInfo[0] !== '') {

            return $errorInfo[0];

        }

        $previous = $e->getPrevious();

        if ($previous instanceof \PDOException && is_array($previous->errorInfo ?? null) && isset($previous->errorInfo[0]) && is_string($previous->errorInfo[0])) {

            return $previous->errorInfo[0];

        }

        $code = $e->getCode();

        if (is_string($code) && $code !== '' && $code !== '0') {

            return $code;

        }

        return null;

    }

    private function queryExceptionDriverCode(QueryException $e): ?int
    {

        $errorInfo = $e->errorInfo;

        if (is_array($errorInfo) && isset($errorInfo[1]) && is_numeric($errorInfo[1])) {

            return (int) $errorInfo[1];

        }

        $previous = $e->getPrevious();

        if ($previous instanceof \PDOException && is_array($previous->errorInfo ?? null) && isset($previous->errorInfo[1]) && is_numeric($previous->errorInfo[1])) {

            return (int) $previous->errorInfo[1];

        }

        return null;

    }

    private function generateRunId(): string
    {

        do {

            $runId = (string) Str::uuid();

        } while (AnnotationValidationRun::query()->where('run_id', $runId)->exists());

        return $runId;

    }

    /**
     * @param  array<string,mixed>|null  $reportJsonMeta
     * @param  array<string,mixed>|null  $reportCsvMeta
     * @return array{run:array<string,mixed>,issues:list<array<string,mixed>>,audit:array<string,mixed>}
     */
    private function buildPersistencePayload(

        AnnotationRecord $record,

        string $runId,

        string $submissionIntentId,

        string $stagedRelativePath,

        string $stagedSha256,

        ?int $executedByUserId,

        string $actorType,

        ?string $b1Version,

        B1ValidationResult $b1Result,

        ?array $reportJsonMeta,

        ?array $reportCsvMeta,

    ): array {

        $executedAt = now();

        $commonRun = [

            'run_id' => $runId,

            'annotation_record_id' => $record->id,

            'submission_intent_id' => $submissionIntentId,

            'b1_version' => $b1Version,

            'input_artifact_path' => $stagedRelativePath,

            'input_artifact_sha256' => $stagedSha256,

            'input_evidence_state' => 'staged_path',

            'durable_input_evidence_path' => $stagedRelativePath,

            'durable_input_evidence_sha256' => $stagedSha256,

            'executed_by_user_id' => $executedByUserId,

            'executed_at' => $executedAt,

        ];

        if (! $b1Result->ok) {

            $infra = is_array($b1Result->payload['infrastructure_error'] ?? null) ? $b1Result->payload['infrastructure_error'] : [];

            $infraCode = is_string($infra['code'] ?? null) ? $infra['code'] : 'B1_INFRA_UNKNOWN';

            $infraMessage = is_string($infra['message'] ?? null) ? $infra['message'] : 'The B1 validator could not be executed.';

            $timedOut = (bool) ($infra['timed_out'] ?? false);

            $exitCode = isset($infra['exit_code']) && is_numeric($infra['exit_code']) ? (int) $infra['exit_code'] : null;

            $run = $commonRun + [

                'status' => 'infra_failed',

                'infra_error_code' => $infraCode,

                'infra_error_message' => $infraMessage,

                'timed_out' => $timedOut,

                'exit_code' => $exitCode,

                'is_valid' => null,

                'is_export_eligible_canonical' => null,

                'error_count' => 0,

                'warning_count' => 0,

                'export_block_count' => 0,

                'report_json_path' => null,

                'report_json_sha256' => null,

                'report_csv_path' => null,

            ];

            return [

                'run' => $run,

                'issues' => [],

                'audit' => [

                    'actor_user_id' => $executedByUserId,

                    'actor_type' => $actorType,

                    'action' => 'validation_infra_failed',

                    'metadata_json' => [

                        'run_id' => $runId,

                        'submission_intent_id' => $submissionIntentId,

                        'input_artifact_path' => $stagedRelativePath,

                        'input_artifact_sha256' => $stagedSha256,

                        'infra_error_code' => $infraCode,

                        'timed_out' => $timedOut,

                        'exit_code' => $exitCode,

                    ],

                ],

            ];

        }

        $b1 = is_array($b1Result->payload['b1'] ?? null) ? $b1Result->payload['b1'] : [];

        $isValid = (bool) ($b1['valid'] ?? false);

        $issues = $this->normalizeIssuesForPersistence($b1['issues'] ?? [], (string) $record->record_id);

        $reportJsonPath = is_array($reportJsonMeta) ? (string) $reportJsonMeta['relative_path'] : null;

        $reportJsonSha = is_array($reportJsonMeta) ? (string) $reportJsonMeta['sha256'] : null;

        $reportCsvPath = is_array($reportCsvMeta) ? (string) $reportCsvMeta['relative_path'] : null;

        if ($reportJsonPath === null || $reportJsonSha === null) {

            throw new RuntimeException('Completed B1 validation requires a persisted JSON report artifact.');
        }

        $run = $commonRun + [

            'status' => 'completed',

            'infra_error_code' => null,

            'infra_error_message' => null,

            'timed_out' => false,

            'exit_code' => isset($b1['exit_code']) && is_numeric($b1['exit_code']) ? (int) $b1['exit_code'] : null,

            'is_valid' => $isValid,

            'is_export_eligible_canonical' => (bool) ($b1['export_eligible_canonical'] ?? false),

            'error_count' => (int) ($b1['error_count'] ?? 0),

            'warning_count' => (int) ($b1['warning_count'] ?? 0),

            'export_block_count' => (int) ($b1['export_block_count'] ?? 0),

            'report_json_path' => $reportJsonPath,

            'report_json_sha256' => $reportJsonSha,

            'report_csv_path' => $reportCsvPath,

        ];

        return [

            'run' => $run,

            'issues' => $issues,

            'audit' => [

                'actor_user_id' => $executedByUserId,

                'actor_type' => $actorType,

                'action' => $isValid ? 'validation_completed' : 'validation_failed',

                'metadata_json' => [

                    'run_id' => $runId,

                    'submission_intent_id' => $submissionIntentId,

                    'input_artifact_path' => $stagedRelativePath,

                    'input_artifact_sha256' => $stagedSha256,

                    'report_json_path' => $reportJsonPath,

                    'report_json_sha256' => $reportJsonSha,

                    'is_valid' => $isValid,

                    'error_count' => $run['error_count'],

                    'warning_count' => $run['warning_count'],

                    'export_block_count' => $run['export_block_count'],

                ],

            ],

        ];

    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeIssuesForPersistence(mixed $issues, string $fallbackRecordId): array
    {

        if (! is_array($issues)) {

            return [];

        }

        $normalized = [];

        foreach ($issues as $issue) {

            if (! is_array($issue)) {

                continue;

            }

            $code = $issue['code'] ?? null;

            $severity = $issue['severity'] ?? null;

            $message = $issue['message'] ?? null;

            if (! is_string($code) || $code === '' || ! is_string($severity) || $severity === '' || ! is_string($message) || $message === '') {

                continue;

            }

            $normalized[] = [

                'record_id' => is_string($issue['record_id'] ?? null) && $issue['record_id'] !== '' ? $issue['record_id'] : $fallbackRecordId,

                'severity' => $severity,

                'code' => $code,

                'field' => is_string($issue['field'] ?? null) ? $issue['field'] : null,

                'message' => $message,

                'details_json' => is_array($issue['details'] ?? null) ? $issue['details'] : null,

            ];

        }

        return $normalized;

    }
}
