<?php

namespace App\Services\Annotation;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\AnnotationValidationRun;
use App\Models\User;
use App\Services\Annotation\Exceptions\AnnotationLifecycleAuthorizationException;
use App\Services\Annotation\Exceptions\AnnotationLifecycleConflictException;
use App\Services\Annotation\Exceptions\AnnotationLifecycleValidationException;
use App\Services\Annotation\Reconciliation\TrustedReconciliationPrincipal;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnnotationRecordLifecycleService
{
    public function __construct(
        private readonly AnnotationArtifactStore $store,
    ) {}

    /**
     * Create a new draft annotation record with an initial versioned draft artifact.
     *
     * @return array{record:AnnotationRecord,artifact:array{record_id:string,draft_version_id:string,relative_path:string,sha256:string,bytes:int}}
     */
    public function createDraft(User $actor, array $draftPayload, ?string $recordId = null, ?string $verificationStatus = 'pending_confirmation', string $recognitionMethod = 'individual'): array
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();

        $recordId = $this->normalizeIdentifier($recordId ?? $this->generateRecordId(), 'record_id');
        $verificationStatus = $this->normalizeVerificationStatus($verificationStatus);
        $recognitionMethod = $this->normalizeRecognitionMethod($recognitionMethod);
        $workflowStatus = 'draft';
        $this->assertWorkflowVerificationAllowed($workflowStatus, $verificationStatus);

        if (AnnotationRecord::query()->where('record_id', $recordId)->exists()) {
            throw new AnnotationLifecycleConflictException('Annotation record_id already exists.');
        }

        $content = $this->encodeDraftPayload($draftPayload, $recordId);

        $artifact = $this->store->writeDraftVersion($recordId, $content);
        $verified = $this->store->verifyStoredArtifact($artifact['relative_path'], $artifact['sha256']);

        try {
            $record = DB::transaction(function () use ($actor, $recordId, $verificationStatus, $recognitionMethod, $artifact, $verified): AnnotationRecord {
                if (AnnotationRecord::query()->where('record_id', $recordId)->exists()) {
                    throw new AnnotationLifecycleConflictException('Annotation record_id already exists.');
                }

                $record = new AnnotationRecord;
                $record->record_id = $recordId;
                $record->forceFill([
                    'schema_version' => 'human-recognition-v1',
                    'recognition_method' => $recognitionMethod,
                    'verification_status' => $verificationStatus,
                    'workflow_status' => 'draft',
                    'artifact_state' => 'draft',
                    'current_draft_version_id' => $artifact['draft_version_id'],
                    'current_draft_path' => $artifact['relative_path'],
                    'current_draft_sha256' => $verified['sha256'],
                    'submission_state' => 'none',
                    'created_by_user_id' => $actor->id,
                    'is_export_eligible_canonical' => false,
                    'latest_b1_valid' => null,
                    'lock_version' => 1,
                ]);
                $record->save();

                $this->appendAudit(
                    record: $record,
                    actor: $actor,
                    actorType: 'user',
                    action: 'created',
                    fromWorkflowStatus: null,
                    toWorkflowStatus: 'draft',
                    comment: null,
                    metadata: [
                        'lock_version' => 1,
                        'draft_version_id' => $artifact['draft_version_id'],
                        'draft_sha256' => $verified['sha256'],
                        'draft_path' => $artifact['relative_path'],
                    ],
                );

                return $record->fresh();
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if ($this->isRecordIdUniqueViolation($e, $recordId)) {
                // Concurrent collision: artifact may be an orphan in the record namespace; reconciliation can clean it.
                throw new AnnotationLifecycleConflictException('Annotation record_id already exists.', 0, $e);
            }
            throw $e;
        } catch (\Throwable $e) {
            // Draft artifact may remain as an orphan; reconciliation can clean it later.
            throw $e;
        }

        return ['record' => $record, 'artifact' => $artifact];
    }

    /**
     * Save an existing draft as a new versioned draft artifact.
     *
     * @return array{record:AnnotationRecord,artifact:array{record_id:string,draft_version_id:string,relative_path:string,sha256:string,bytes:int}}
     */
    public function saveDraft(AnnotationRecord $record, User $actor, int $expectedLockVersion, array $draftPayload, ?string $verificationStatus = null): array
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();
        $this->assertCanEditDraft($record, $actor, $expectedLockVersion);

        $nextVerificationStatus = $verificationStatus !== null
            ? $this->normalizeVerificationStatus($verificationStatus)
            : (string) $record->verification_status;
        $this->assertWorkflowVerificationAllowed('draft', $nextVerificationStatus);

        $content = $this->encodeDraftPayload($draftPayload, (string) $record->record_id);

        $artifact = $this->store->writeDraftVersion((string) $record->record_id, $content);
        $verified = $this->store->verifyStoredArtifact($artifact['relative_path'], $artifact['sha256']);

        $updated = DB::transaction(function () use ($record, $actor, $expectedLockVersion, $artifact, $verified, $nextVerificationStatus): AnnotationRecord {
            $locked = $this->lockRecord($record->id);
            $this->assertCanEditDraft($locked, $actor, $expectedLockVersion);
            $this->assertWorkflowVerificationAllowed('draft', $nextVerificationStatus);

            $previousVersionId = $locked->current_draft_version_id;
            $previousPath = $locked->current_draft_path;
            $previousSha = $locked->current_draft_sha256;
            $nextLockVersion = (int) $locked->lock_version + 1;

            $updatedRows = AnnotationRecord::query()
                ->whereKey($locked->id)
                ->where('lock_version', $expectedLockVersion)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereIn('submission_state', ['none', 'b1_infra_failed'])
                ->update([
                    'verification_status' => $nextVerificationStatus,
                    'current_draft_version_id' => $artifact['draft_version_id'],
                    'current_draft_path' => $artifact['relative_path'],
                    'current_draft_sha256' => $verified['sha256'],
                    'lock_version' => $nextLockVersion,
                    'updated_at' => now(),
                ]);

            if ($updatedRows !== 1) {
                $fresh = $this->lockRecord($locked->id);
                $this->assertCanEditDraft($fresh, $actor, $expectedLockVersion);
                throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
            }

            $locked = $this->lockRecord($locked->id);

            $this->appendAudit(
                record: $locked,
                actor: $actor,
                actorType: 'user',
                action: 'draft_saved',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: 'draft',
                comment: null,
                metadata: [
                    'lock_version' => $locked->lock_version,
                    'previous_lock_version' => $expectedLockVersion,
                    'previous_draft_version_id' => $previousVersionId,
                    'previous_draft_path' => $previousPath,
                    'previous_draft_sha256' => $previousSha,
                    'draft_version_id' => $artifact['draft_version_id'],
                    'draft_path' => $artifact['relative_path'],
                    'draft_sha256' => $verified['sha256'],
                ],
            );

            return $locked->fresh();
        });

        return ['record' => $updated, 'artifact' => $artifact];
    }

    public function discardDraft(AnnotationRecord $record, User $actor, int $expectedLockVersion, string $reason): AnnotationRecord
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();
        $reason = trim($reason);
        if ($reason === '') {
            throw new AnnotationLifecycleValidationException('Discard reason is required.');
        }

        return DB::transaction(function () use ($record, $actor, $expectedLockVersion, $reason): AnnotationRecord {
            $locked = $this->lockRecord($record->id);
            $this->assertDiscardAuthorized($locked, $actor);
            $this->assertDraftDiscardable($locked, $expectedLockVersion);

            $nextLockVersion = (int) $locked->lock_version + 1;
            $discardedAt = now();

            $updatedRows = AnnotationRecord::query()
                ->whereKey($locked->id)
                ->where('lock_version', $expectedLockVersion)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereIn('submission_state', ['none', 'b1_infra_failed'])
                ->update([
                    'workflow_status' => 'discarded',
                    'discarded_at' => $discardedAt,
                    'discard_reason' => $reason,
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $discardedAt,
                ]);

            if ($updatedRows !== 1) {
                $fresh = $this->lockRecord($locked->id);
                $this->assertDiscardAuthorized($fresh, $actor);
                $this->assertDraftDiscardable($fresh, $expectedLockVersion);
                throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
            }

            $locked = $this->lockRecord($locked->id);

            $this->appendAudit(
                record: $locked,
                actor: $actor,
                actorType: 'user',
                action: 'draft_discarded',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: 'discarded',
                comment: $reason,
                metadata: [
                    'lock_version' => $locked->lock_version,
                    'previous_lock_version' => $expectedLockVersion,
                    'discard_reason' => $reason,
                ],
            );

            return $locked->fresh();
        });
    }

    private function lockRecord(int $id): AnnotationRecord
    {
        $query = AnnotationRecord::query()->whereKey($id);
        if ($this->supportsRowLock()) {
            $query->lockForUpdate();
        }
        $record = $query->first();
        if (! $record instanceof AnnotationRecord) {
            throw new AnnotationLifecycleConflictException('Annotation record not found.');
        }

        return $record;
    }

    private function supportsRowLock(): bool
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'pgsql'], true);
    }

    private function assertSupportedDriver(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new AnnotationLifecycleConflictException('Unsupported database driver for annotation lifecycle: '.$driver);
        }
    }

    private function requireAnnotationRole(User $actor): void
    {
        if (! $actor->hasAnnotationRole()) {
            throw new AnnotationLifecycleAuthorizationException('User is not authorized for annotation lifecycle operations.');
        }
    }

    private function isRecordIdUniqueViolation(QueryException $e, string $recordId): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());

        $unique = $sqlState === '23505' || $sqlState === '23000' || $driverCode === 1062 || $driverCode === 19 || $driverCode === 2067 || $driverCode === 1555;
        if (! $unique) {
            return false;
        }

        if (str_contains($message, 'record_id')) {
            return true;
        }

        return AnnotationRecord::query()->where('record_id', $recordId)->exists();
    }

    private function assertCanEditDraft(AnnotationRecord $record, User $actor, int $expectedLockVersion): void
    {
        if ((int) $record->created_by_user_id !== (int) $actor->id) {
            throw new AnnotationLifecycleAuthorizationException('Only the draft owner may edit this draft.');
        }

        $this->assertDraftMutable($record, $expectedLockVersion);
    }

    private function assertDraftDiscardable(AnnotationRecord $record, int $expectedLockVersion): void
    {
        $this->assertDraftMutable($record, $expectedLockVersion);
    }

    private function assertDraftMutable(AnnotationRecord $record, int $expectedLockVersion): void
    {
        if ((string) $record->workflow_status !== 'draft') {
            throw new AnnotationLifecycleConflictException('Only draft records can be modified by this operation.');
        }
        if ((string) $record->artifact_state !== 'draft') {
            throw new AnnotationLifecycleConflictException('Finalized records cannot be modified as drafts.');
        }
        if ($record->discarded_at !== null || (string) $record->submission_state === 'discarded') {
            throw new AnnotationLifecycleConflictException('Discarded records cannot be modified.');
        }
        if (! in_array((string) $record->submission_state, ['none', 'b1_infra_failed'], true)) {
            throw new AnnotationLifecycleConflictException('Draft cannot be modified while a submission intent is in progress.');
        }
        if ($expectedLockVersion < 1 || (int) $record->lock_version !== $expectedLockVersion) {
            throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
        }
    }

    private function assertDiscardAuthorized(AnnotationRecord $record, User $actor): void
    {
        $isOwner = (int) $record->created_by_user_id === (int) $actor->id;
        $isResearchAdmin = $actor->isResearchAdmin();
        if (! $isOwner && ! $isResearchAdmin) {
            throw new AnnotationLifecycleAuthorizationException('Only the draft owner or research_admin may discard this draft.');
        }
    }

    private function assertCanSubmitDraft(AnnotationRecord $record, User $actor, int $expectedLockVersion): void
    {
        $isOwner = (int) $record->created_by_user_id === (int) $actor->id;
        $canSubmitByRole = $actor->hasAnnotationRole();
        if (! $isOwner || ! $canSubmitByRole) {
            throw new AnnotationLifecycleAuthorizationException('Only the draft owner with an authorized annotation role may submit this draft.');
        }

        if ($expectedLockVersion < 1 || (int) $record->lock_version !== $expectedLockVersion) {
            throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
        }

        $state = (string) $record->submission_state;
        if (in_array($state, ['staging_written', 'b1_completed', 'promotion_pending'], true)) {
            throw new AnnotationLifecycleConflictException('A submission intent is already in progress for this draft.');
        }

        $this->assertDraftMutable($record, $expectedLockVersion);

        if (! in_array($state, ['none', 'b1_infra_failed'], true)) {
            throw new AnnotationLifecycleConflictException('Draft submission is not allowed from the current submission state.');
        }
        if ($record->submitted_json_path !== null || $record->submitted_artifact_sha256 !== null || $record->submission_finalized_at !== null) {
            throw new AnnotationLifecycleConflictException('Finalized records cannot be submitted.');
        }
    }

    private function requireCurrentDraftVersionId(AnnotationRecord $record): string
    {
        $versionId = $record->current_draft_version_id;
        $path = $record->current_draft_path;
        $sha = $record->current_draft_sha256;
        if (! is_string($versionId) || trim($versionId) === '' || ! is_string($path) || trim($path) === '' || ! is_string($sha) || trim($sha) === '') {
            throw new AnnotationLifecycleValidationException('Draft content is missing or invalid for submission.');
        }

        return $this->normalizeIdentifier($versionId, 'current_draft_version_id');
    }

    private function assertCurrentDraftVersionUnchanged(AnnotationRecord $record, string $expectedDraftVersionId): void
    {
        $current = $this->requireCurrentDraftVersionId($record);
        if ($current !== $expectedDraftVersionId) {
            throw new AnnotationLifecycleConflictException('Draft changed since candidate submission was prepared; reload and retry.');
        }
    }

    private function assertWorkflowVerificationAllowed(string $workflowStatus, string $verificationStatus): void
    {
        $allowed = [
            'draft' => ['confirmed', 'pending_confirmation', 'uncertain', 'rejected', 'not_inspected'],
            'discarded' => ['confirmed', 'pending_confirmation', 'uncertain', 'rejected', 'not_inspected'],
        ];

        $allowedForWorkflow = $allowed[$workflowStatus] ?? null;
        if ($allowedForWorkflow === null || ! in_array($verificationStatus, $allowedForWorkflow, true)) {
            throw new AnnotationLifecycleValidationException('Invalid workflow_status/verification_status combination.');
        }
    }

    private function normalizeVerificationStatus(string $status): string
    {
        $status = trim($status);
        $allowed = ['confirmed', 'pending_confirmation', 'uncertain', 'rejected', 'not_inspected'];
        if (! in_array($status, $allowed, true)) {
            throw new AnnotationLifecycleValidationException('Invalid verification_status.');
        }

        return $status;
    }

    private function normalizeRecognitionMethod(string $method): string
    {
        $method = trim($method);
        $allowed = ['individual', 'group'];
        if (! in_array($method, $allowed, true)) {
            throw new AnnotationLifecycleValidationException('Invalid recognition_method.');
        }

        return $method;
    }

    private function normalizeIdentifier(string $value, string $field): string
    {
        $value = trim($value);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', $value) !== 1) {
            throw new AnnotationLifecycleValidationException('Invalid '.$field.'.');
        }

        return $value;
    }

    private function generateSubmissionIntentId(): string
    {
        return 'si-'.Str::lower((string) Str::uuid());
    }

    private function generateRecordId(): string
    {
        return 'ar-'.Str::lower((string) Str::uuid());
    }

    /**
     * Create a durable submission intent and exact staged candidate artifact (S2-I6-C1, §6.3 steps 1-5).
     *
     * B1 execution, OD-01 lifecycle integration, promotion, and finalization are intentionally out of scope.
     *
     * @return array{record:AnnotationRecord,submission_intent_id:string,staging:array{record_id:string,submission_intent_id:string,relative_path:string,sha256:string,bytes:int,idempotent:bool},candidate_sha256:string}
     */
    public function createSubmissionIntent(AnnotationRecord $record, User $actor, int $expectedLockVersion): array
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();

        $locked = $this->lockRecord($record->id);
        $this->assertCanSubmitDraft($locked, $actor, $expectedLockVersion);
        $currentDraftVersionId = $this->requireCurrentDraftVersionId($locked);

        $candidateJson = $this->buildCandidateSubmittedJson($locked);
        $submissionIntentId = $this->generateSubmissionIntentId();

        $staging = $this->store->writeSubmissionStaging((string) $locked->record_id, $submissionIntentId, $candidateJson);
        $verifiedStaging = $this->store->verifyStoredArtifact($staging['relative_path'], $staging['sha256']);

        if (! hash_equals((string) $verifiedStaging['sha256'], (string) $staging['sha256'])) {
            throw new AnnotationLifecycleConflictException('Staged submission artifact hash verification failed.');
        }

        $updated = DB::transaction(function () use ($record, $actor, $expectedLockVersion, $submissionIntentId, $staging, $verifiedStaging, $currentDraftVersionId): AnnotationRecord {
            $txLocked = $this->lockRecord($record->id);
            $this->assertCanSubmitDraft($txLocked, $actor, $expectedLockVersion);
            $this->assertCurrentDraftVersionUnchanged($txLocked, $currentDraftVersionId);

            $nextLockVersion = (int) $txLocked->lock_version + 1;
            $submissionStartedAt = now();

            $updatedRows = AnnotationRecord::query()
                ->whereKey($txLocked->id)
                ->where('lock_version', $expectedLockVersion)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereNull('discarded_at')
                ->whereIn('submission_state', ['none', 'b1_infra_failed'])
                ->where('current_draft_version_id', $currentDraftVersionId)
                ->update([
                    'submission_intent_id' => $submissionIntentId,
                    'submission_state' => 'staging_written',
                    'submission_staging_path' => $staging['relative_path'],
                    'submission_staging_sha256' => $verifiedStaging['sha256'],
                    'submission_started_at' => $submissionStartedAt,
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $submissionStartedAt,
                ]);

            if ($updatedRows !== 1) {
                $fresh = $this->lockRecord($txLocked->id);
                $this->assertCanSubmitDraft($fresh, $actor, $expectedLockVersion);
                throw new AnnotationLifecycleConflictException('Stale lock_version or submission state changed; reload the record and retry.');
            }

            $txLocked = $this->lockRecord($txLocked->id);

            $this->appendAudit(
                record: $txLocked,
                actor: $actor,
                actorType: 'user',
                action: 'submission_intent_created',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: 'draft',
                comment: null,
                metadata: [
                    'submission_intent_id' => $submissionIntentId,
                    'submission_state' => 'staging_written',
                    'submission_staging_path' => $staging['relative_path'],
                    'submission_staging_sha256' => $verifiedStaging['sha256'],
                    'lock_version' => $txLocked->lock_version,
                    'previous_lock_version' => $expectedLockVersion,
                    'current_draft_version_id' => $txLocked->current_draft_version_id,
                    'current_draft_path' => $txLocked->current_draft_path,
                    'current_draft_sha256' => $txLocked->current_draft_sha256,
                ],
            );

            return $txLocked->fresh();
        });

        return [
            'record' => $updated,
            'submission_intent_id' => $submissionIntentId,
            'staging' => $staging,
            'candidate_sha256' => $verifiedStaging['sha256'],
        ];
    }

    /**
     * Execute B1 validation for an active staging submission intent and persist OD-01/I5 outcomes (S2-I6-C2, ?6.3 steps 6-7).
     *
     * Promotion/finalization/reconciliation are intentionally out of scope.
     *
     * @return array{record:AnnotationRecord,run:AnnotationValidationRun,b1_result:B1ValidationResult,report_json:array<string,mixed>|null,report_csv:array<string,mixed>|null}
     */
    public function executeSubmissionIntentValidation(AnnotationRecord $record, User $actor, int $expectedLockVersion, ?string $b1Version = null): array
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();

        $locked = $this->lockRecord($record->id);
        $this->assertCanExecuteStagingIntent($locked, $actor, $expectedLockVersion);

        $snapshot = $this->captureStagingIntentSnapshot($locked, $expectedLockVersion);
        $validationPersistence = $this->validationPersistence();

        $result = $validationPersistence->persistStagedSubmissionAttempt(
            $locked,
            $snapshot['submission_intent_id'],
            $snapshot['submission_staging_path'],
            $snapshot['submission_staging_sha256'],
            (int) $actor->id,
            'user',
            $b1Version,
            function (AnnotationValidationRun $run, B1ValidationResult $b1Result) use ($snapshot, $actor): void {
                $this->applySubmissionIntentValidationTransition($snapshot, $run, $b1Result, $actor);
            }
        );

        return [
            'record' => $this->lockRecord($record->id),
            'run' => $result['run'],
            'b1_result' => $result['b1_result'],
            'report_json' => $result['report_json'],
            'report_csv' => $result['report_csv'],
        ];
    }

    /**
     * @param  array<string,mixed>  $draftPayload
     */
    private function encodeDraftPayload(array $draftPayload, string $recordId): string
    {
        $payload = $draftPayload;
        $payload['record_id'] = $recordId;

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new AnnotationLifecycleValidationException('Draft payload could not be encoded as JSON.');
        }

        return $json.PHP_EOL;
    }

    /**
     * Build the exact candidate submitted JSON artifact from the current committed draft version.
     *
     * Spec evidence: ?6.3 step 3 requires "generate the final v1-compatible candidate record JSON once";
     * ?9.2/AC-S2-13 require B1 to validate the exact staged submitted artifact bytes with no regeneration.
     * The v1 structure is grounded in the Step 8A schema (research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json)
     * already used by B1 validation: schema_version, record_id, recognition_method, verification_status,
     * ingredient_labels, uncertain_labels, out_of_scope_labels, reviewer_id, recognition_date, notes, provenance,
     * and exactly one of individual/group.
     *
     * Candidate integrity policy: reject invalid/unsupported draft content with clear validation errors.
     * Do not silently dedupe, trim, coerce, or discard meaningful draft fields. Only checks required by
     * the authoritative Step 8A schema/contract are applied: schema_version const, required field presence/shape,
     * enum/pattern/type constraints, recognition method/payload consistency, and record identity consistency.
     */
    private function buildCandidateSubmittedJson(AnnotationRecord $record): string
    {
        $draftPayload = $this->readCurrentDraftPayload($record);

        $recordId = $this->normalizeIdentifier((string) $record->record_id, 'record_id');
        $schemaVersion = is_string($record->schema_version) && trim($record->schema_version) !== ''
            ? trim($record->schema_version)
            : 'human-recognition-v1';
        if ($schemaVersion !== 'human-recognition-v1') {
            throw new AnnotationLifecycleValidationException('Unsupported schema_version for submission candidate.');
        }

        $recognitionMethod = $this->normalizeRecognitionMethod((string) $record->recognition_method);
        $verificationStatus = $this->normalizeVerificationStatus((string) $record->verification_status);
        $this->assertWorkflowVerificationAllowed('draft', $verificationStatus);

        $allowedTopLevel = [
            'schema_version', 'record_id', 'recognition_method', 'verification_status',
            'ingredient_labels', 'uncertain_labels', 'out_of_scope_labels', 'reviewer_id',
            'recognition_date', 'notes', 'provenance', 'individual', 'group',
        ];
        foreach (array_keys($draftPayload) as $key) {
            if (! in_array($key, $allowedTopLevel, true)) {
                throw new AnnotationLifecycleValidationException('Draft payload contains unsupported field: '.$key.'.');
            }
        }

        if (array_key_exists('schema_version', $draftPayload) && $draftPayload['schema_version'] !== $schemaVersion) {
            throw new AnnotationLifecycleValidationException('Draft payload schema_version does not match the annotation record.');
        }
        if (array_key_exists('record_id', $draftPayload) && $draftPayload['record_id'] !== $recordId) {
            throw new AnnotationLifecycleValidationException('Draft payload record_id does not match the annotation record.');
        }
        if (array_key_exists('recognition_method', $draftPayload) && $draftPayload['recognition_method'] !== $recognitionMethod) {
            throw new AnnotationLifecycleValidationException('Draft payload recognition_method does not match the annotation record.');
        }
        if (array_key_exists('verification_status', $draftPayload) && $draftPayload['verification_status'] !== $verificationStatus) {
            throw new AnnotationLifecycleValidationException('Draft payload verification_status does not match the annotation record.');
        }

        $candidate = [
            'schema_version' => $schemaVersion,
            'record_id' => $recordId,
            'recognition_method' => $recognitionMethod,
            'verification_status' => $verificationStatus,
            'ingredient_labels' => $this->normalizeStringList($draftPayload['ingredient_labels'] ?? null, 'ingredient_labels', true),
            'uncertain_labels' => $this->normalizeStringList($draftPayload['uncertain_labels'] ?? null, 'uncertain_labels', true),
            'out_of_scope_labels' => $this->normalizeStringList($draftPayload['out_of_scope_labels'] ?? null, 'out_of_scope_labels', true),
            'reviewer_id' => $this->normalizeNullableString($draftPayload['reviewer_id'] ?? null, 'reviewer_id'),
            'recognition_date' => $this->normalizeNullableString($draftPayload['recognition_date'] ?? null, 'recognition_date'),
            'notes' => $this->normalizeRequiredString($draftPayload['notes'] ?? null, 'notes'),
            'provenance' => $this->normalizeProvenance($draftPayload['provenance'] ?? null),
        ];

        if ($recognitionMethod === 'individual') {
            $candidate['individual'] = $this->normalizeIndividual($draftPayload['individual'] ?? null);
            if (array_key_exists('group', $draftPayload)) {
                throw new AnnotationLifecycleValidationException('Individual recognition draft must not include group payload.');
            }
        } else {
            $candidate['group'] = $this->normalizeGroup($draftPayload['group'] ?? null);
            if (array_key_exists('individual', $draftPayload)) {
                throw new AnnotationLifecycleValidationException('Group recognition draft must not include individual payload.');
            }
        }

        $json = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new AnnotationLifecycleValidationException('Submission candidate could not be encoded as JSON.');
        }

        return $json.PHP_EOL;
    }

    /**
     * @return array<string,mixed>
     */
    private function readCurrentDraftPayload(AnnotationRecord $record): array
    {
        $path = $record->current_draft_path;
        $expectedSha = $record->current_draft_sha256;
        if (! is_string($path) || trim($path) === '' || ! is_string($expectedSha) || trim($expectedSha) === '') {
            throw new AnnotationLifecycleValidationException('Draft content is missing or invalid for submission.');
        }

        $verified = $this->store->verifyStoredArtifact($path, $expectedSha);
        $absolutePath = $this->store->resolveRelativePath($verified['relative_path'], true);
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new AnnotationLifecycleValidationException('Draft content could not be read for submission.');
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new AnnotationLifecycleValidationException('Draft content is not a valid JSON object for submission.');
        }

        return $decoded;
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $value, string $field, bool $uniqueItems): array
    {
        if (! is_array($value) || array_is_list($value) === false) {
            throw new AnnotationLifecycleValidationException('Draft field '.$field.' must be a list.');
        }

        $out = [];
        $seen = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new AnnotationLifecycleValidationException('Draft field '.$field.' must contain strings.');
            }
            if ($item === '') {
                throw new AnnotationLifecycleValidationException('Draft field '.$field.' must not contain empty strings.');
            }
            if ($uniqueItems) {
                if (array_key_exists($item, $seen)) {
                    throw new AnnotationLifecycleValidationException('Draft field '.$field.' must not contain duplicate values.');
                }
                $seen[$item] = true;
            }
            $out[] = $item;
        }

        return $out;
    }

    private function normalizeNullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new AnnotationLifecycleValidationException('Draft field '.$field.' must be a string or null.');
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        if (! is_string($value)) {
            throw new AnnotationLifecycleValidationException('Draft field '.$field.' must be a string.');
        }

        return $value;
    }

    /**
     * @return array{source_type:string,source_reference:string,evidence_summary:string,is_human_ground_truth:bool}
     */
    private function normalizeProvenance(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new AnnotationLifecycleValidationException('Draft provenance must be an object.');
        }
        $this->assertNoUnsupportedKeys($value, ['source_type', 'source_reference', 'evidence_summary', 'is_human_ground_truth'], 'provenance');

        foreach (['source_type', 'source_reference', 'evidence_summary', 'is_human_ground_truth'] as $required) {
            if (! array_key_exists($required, $value)) {
                throw new AnnotationLifecycleValidationException('Draft provenance.'.$required.' is required.');
            }
        }

        $sourceType = $value['source_type'];
        $allowed = ['historical_individual_inspection', 'historical_group_inspection', 'folder_confirmation_report', 'unknown'];
        if (! is_string($sourceType) || ! in_array($sourceType, $allowed, true)) {
            throw new AnnotationLifecycleValidationException('Draft provenance.source_type is invalid.');
        }

        $sourceReference = $value['source_reference'];
        $evidenceSummary = $value['evidence_summary'];
        $isHumanGroundTruth = $value['is_human_ground_truth'];
        if (! is_string($sourceReference)) {
            throw new AnnotationLifecycleValidationException('Draft provenance.source_reference must be a string.');
        }
        if (! is_string($evidenceSummary)) {
            throw new AnnotationLifecycleValidationException('Draft provenance.evidence_summary must be a string.');
        }
        if (! is_bool($isHumanGroundTruth)) {
            throw new AnnotationLifecycleValidationException('Draft provenance.is_human_ground_truth must be boolean.');
        }

        return [
            'source_type' => $sourceType,
            'source_reference' => $sourceReference,
            'evidence_summary' => $evidenceSummary,
            'is_human_ground_truth' => $isHumanGroundTruth,
        ];
    }

    /**
     * @return array{image_id:string,relative_path:string,original_folder:string,original_filename:string}
     */
    private function normalizeIndividual(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new AnnotationLifecycleValidationException('Draft individual payload is required for individual recognition.');
        }
        $this->assertNoUnsupportedKeys($value, ['image_id', 'relative_path', 'original_folder', 'original_filename'], 'individual');
        foreach (['image_id', 'relative_path', 'original_folder', 'original_filename'] as $required) {
            if (! array_key_exists($required, $value)) {
                throw new AnnotationLifecycleValidationException('Draft individual.'.$required.' is required.');
            }
        }

        $imageId = $value['image_id'];
        $relativePath = $value['relative_path'];
        $originalFolder = $value['original_folder'];
        $originalFilename = $value['original_filename'];

        if (! is_string($imageId) || preg_match('/^IMG-[0-9]{6}$/', $imageId) !== 1) {
            throw new AnnotationLifecycleValidationException('Draft individual.image_id is invalid.');
        }
        foreach (['relative_path' => $relativePath, 'original_folder' => $originalFolder, 'original_filename' => $originalFilename] as $field => $fieldValue) {
            if (! is_string($fieldValue)) {
                throw new AnnotationLifecycleValidationException('Draft individual.'.$field.' must be a string.');
            }
            if ($fieldValue === '') {
                throw new AnnotationLifecycleValidationException('Draft individual.'.$field.' must not be empty.');
            }
        }

        return [
            'image_id' => $imageId,
            'relative_path' => $relativePath,
            'original_folder' => $originalFolder,
            'original_filename' => $originalFilename,
        ];
    }

    /**
     * @return array{group_id:string,original_folder:string,proposed_ingredient:string|null,image_ids:list<string>,image_count:int}
     */
    private function normalizeGroup(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new AnnotationLifecycleValidationException('Draft group payload is required for group recognition.');
        }
        $this->assertNoUnsupportedKeys($value, ['group_id', 'original_folder', 'proposed_ingredient', 'image_ids', 'image_count'], 'group');
        foreach (['group_id', 'original_folder', 'proposed_ingredient', 'image_ids', 'image_count'] as $required) {
            if (! array_key_exists($required, $value)) {
                throw new AnnotationLifecycleValidationException('Draft group.'.$required.' is required.');
            }
        }

        $groupId = $value['group_id'];
        $originalFolder = $value['original_folder'];
        $proposedIngredient = $value['proposed_ingredient'];
        $imageIds = $value['image_ids'];
        $imageCount = $value['image_count'];

        if (! is_string($groupId)) {
            throw new AnnotationLifecycleValidationException('Draft group.group_id must be a string.');
        }
        if ($groupId === '') {
            throw new AnnotationLifecycleValidationException('Draft group.group_id must not be empty.');
        }
        if (! is_string($originalFolder)) {
            throw new AnnotationLifecycleValidationException('Draft group.original_folder must be a string.');
        }
        if ($originalFolder === '') {
            throw new AnnotationLifecycleValidationException('Draft group.original_folder must not be empty.');
        }
        if ($proposedIngredient !== null && ! is_string($proposedIngredient)) {
            throw new AnnotationLifecycleValidationException('Draft group.proposed_ingredient must be string or null.');
        }
        if (! is_array($imageIds) || array_is_list($imageIds) === false) {
            throw new AnnotationLifecycleValidationException('Draft group.image_ids must be a list.');
        }
        if (count($imageIds) < 1) {
            throw new AnnotationLifecycleValidationException('Draft group.image_ids must not be empty.');
        }
        $normalizedImageIds = [];
        $seen = [];
        foreach ($imageIds as $imageId) {
            if (! is_string($imageId) || preg_match('/^IMG-[0-9]{6}$/', $imageId) !== 1) {
                throw new AnnotationLifecycleValidationException('Draft group.image_ids contains invalid image_id.');
            }
            if (array_key_exists($imageId, $seen)) {
                throw new AnnotationLifecycleValidationException('Draft group.image_ids must not contain duplicates.');
            }
            $seen[$imageId] = true;
            $normalizedImageIds[] = $imageId;
        }
        if (! is_int($imageCount)) {
            throw new AnnotationLifecycleValidationException('Draft group.image_count must be an integer.');
        }
        if ($imageCount < 1) {
            throw new AnnotationLifecycleValidationException('Draft group.image_count must be >= 1.');
        }
        if ($imageCount !== count($normalizedImageIds)) {
            throw new AnnotationLifecycleValidationException('Draft group.image_count must match image_ids.');
        }

        return [
            'group_id' => $groupId,
            'original_folder' => $originalFolder,
            'proposed_ingredient' => $proposedIngredient,
            'image_ids' => $normalizedImageIds,
            'image_count' => $imageCount,
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     * @param  list<string>  $allowed
     */
    private function assertNoUnsupportedKeys(array $value, array $allowed, string $context): void
    {
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new AnnotationLifecycleValidationException('Draft '.$context.' contains unsupported field: '.$key.'.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function appendAudit(AnnotationRecord $record, ?User $actor, string $actorType, string $action, ?string $fromWorkflowStatus, ?string $toWorkflowStatus, ?string $comment, array $metadata): void
    {
        AnnotationReviewAction::query()->create([
            'annotation_record_id' => $record->id,
            'actor_user_id' => $actor?->id,
            'actor_type' => $actorType,
            'action' => $action,
            'from_workflow_status' => $fromWorkflowStatus,
            'to_workflow_status' => $toWorkflowStatus,
            'comment' => $comment,
            'metadata_json' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array{record_id:int,record_uuid:string,expected_lock_version:int,submission_intent_id:string,submission_staging_path:string,submission_staging_sha256:string}  $snapshot
     */
    private function applySubmissionIntentValidationTransition(array $snapshot, AnnotationValidationRun $run, B1ValidationResult $b1Result, User $actor): void
    {
        $txLocked = $this->lockRecord($snapshot['record_id']);
        $this->assertStagingIntentSnapshotMatches($txLocked, $snapshot);

        $targetState = $b1Result->ok ? 'b1_completed' : 'b1_infra_failed';
        $nextLockVersion = (int) $txLocked->lock_version + 1;
        $updatedAt = now();

        $updatedRows = AnnotationRecord::query()
            ->whereKey($txLocked->id)
            ->where('lock_version', $snapshot['expected_lock_version'])
            ->where('submission_intent_id', $snapshot['submission_intent_id'])
            ->where('submission_state', 'staging_written')
            ->where('submission_staging_path', $snapshot['submission_staging_path'])
            ->where('submission_staging_sha256', $snapshot['submission_staging_sha256'])
            ->where('workflow_status', 'draft')
            ->where('artifact_state', 'draft')
            ->whereNull('discarded_at')
            ->whereNull('submitted_json_path')
            ->whereNull('submitted_artifact_sha256')
            ->whereNull('submission_finalized_at')
            ->update([
                'submission_state' => $targetState,
                'lock_version' => $nextLockVersion,
                'updated_at' => $updatedAt,
            ]);

        if ($updatedRows !== 1) {
            $fresh = $this->lockRecord($snapshot['record_id']);
            $this->assertStagingIntentSnapshotMatches($fresh, $snapshot);
            throw new AnnotationLifecycleConflictException('Stale submission intent or lock_version; reload the record and retry.');
        }

        $txLocked = $this->lockRecord($snapshot['record_id']);

        if (! $b1Result->ok) {
            $infra = is_array($b1Result->payload['infrastructure_error'] ?? null) ? $b1Result->payload['infrastructure_error'] : [];
            $this->appendAudit(
                record: $txLocked,
                actor: $actor,
                actorType: 'user',
                action: 'submission_infra_failed',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: 'draft',
                comment: null,
                metadata: [
                    'submission_intent_id' => $snapshot['submission_intent_id'],
                    'validation_run_id' => $run->run_id,
                    'from_submission_state' => 'staging_written',
                    'to_submission_state' => 'b1_infra_failed',
                    'previous_lock_version' => $snapshot['expected_lock_version'],
                    'lock_version' => $txLocked->lock_version,
                    'submission_staging_path' => $snapshot['submission_staging_path'],
                    'submission_staging_sha256' => $snapshot['submission_staging_sha256'],
                    'infra_error_code' => is_string($infra['code'] ?? null) ? $infra['code'] : null,
                    'timed_out' => (bool) ($infra['timed_out'] ?? false),
                    'exit_code' => isset($infra['exit_code']) && is_numeric($infra['exit_code']) ? (int) $infra['exit_code'] : null,
                ],
            );
        }
    }

    /**
     * @return array{record_id:int,record_uuid:string,expected_lock_version:int,submission_intent_id:string,submission_staging_path:string,submission_staging_sha256:string}
     */
    private function captureStagingIntentSnapshot(AnnotationRecord $record, int $expectedLockVersion): array
    {
        $intentId = $record->submission_intent_id;
        $stagingPath = $record->submission_staging_path;
        $stagingSha = $record->submission_staging_sha256;
        if (! is_string($intentId) || trim($intentId) === '' || ! is_string($stagingPath) || trim($stagingPath) === '' || ! is_string($stagingSha) || trim($stagingSha) === '') {
            throw new AnnotationLifecycleValidationException('Active staging intent metadata is missing or invalid.');
        }

        $stagingSha = strtolower(trim($stagingSha));
        if (preg_match('/\A[a-f0-9]{64}\z/', $stagingSha) !== 1) {
            throw new AnnotationLifecycleValidationException('Active staging intent SHA-256 is invalid.');
        }

        return [
            'record_id' => (int) $record->id,
            'record_uuid' => (string) $record->record_id,
            'expected_lock_version' => $expectedLockVersion,
            'submission_intent_id' => $this->normalizeIdentifier($intentId, 'submission_intent_id'),
            'submission_staging_path' => $stagingPath,
            'submission_staging_sha256' => $stagingSha,
        ];
    }

    /**
     * @param  array{record_id:int,record_uuid:string,expected_lock_version:int,submission_intent_id:string,submission_staging_path:string,submission_staging_sha256:string}  $snapshot
     */
    private function assertStagingIntentSnapshotMatches(AnnotationRecord $record, array $snapshot): void
    {
        if ((int) $record->id !== $snapshot['record_id']) {
            throw new AnnotationLifecycleConflictException('Submission intent record mismatch.');
        }
        if ((int) $record->lock_version !== $snapshot['expected_lock_version']) {
            throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
        }
        $this->assertCanExecuteStagingIntent($record, null, $snapshot['expected_lock_version']);
        if ((string) $record->submission_intent_id !== $snapshot['submission_intent_id']) {
            throw new AnnotationLifecycleConflictException('Submission intent changed; reload the record and retry.');
        }
        if ((string) $record->submission_staging_path !== $snapshot['submission_staging_path']
            || strtolower((string) $record->submission_staging_sha256) !== $snapshot['submission_staging_sha256']) {
            throw new AnnotationLifecycleConflictException('Staged submission artifact metadata changed; reload the record and retry.');
        }
    }

    private function assertCanExecuteStagingIntent(AnnotationRecord $record, ?User $actor, int $expectedLockVersion): void
    {
        if ($actor !== null) {
            $isOwner = (int) $record->created_by_user_id === (int) $actor->id;
            $canExecuteByRole = $actor->hasAnnotationRole();
            if (! $isOwner || ! $canExecuteByRole) {
                throw new AnnotationLifecycleAuthorizationException('Only the draft owner with an authorized annotation role may execute submission validation.');
            }
        }

        if ($expectedLockVersion < 1 || (int) $record->lock_version !== $expectedLockVersion) {
            throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
        }

        if ((string) $record->workflow_status !== 'draft') {
            throw new AnnotationLifecycleConflictException('Only draft records can execute submission validation.');
        }
        if ((string) $record->artifact_state !== 'draft') {
            throw new AnnotationLifecycleConflictException('Finalized records cannot execute submission validation.');
        }
        if ($record->discarded_at !== null) {
            throw new AnnotationLifecycleConflictException('Discarded records cannot execute submission validation.');
        }
        if ((string) $record->submission_state !== 'staging_written') {
            throw new AnnotationLifecycleConflictException('Submission validation can only execute an active staging_written intent.');
        }
        if (! is_string($record->submission_intent_id) || trim($record->submission_intent_id) === ''
            || ! is_string($record->submission_staging_path) || trim($record->submission_staging_path) === ''
            || ! is_string($record->submission_staging_sha256) || trim($record->submission_staging_sha256) === '') {
            throw new AnnotationLifecycleValidationException('Active staging intent metadata is missing or invalid.');
        }
        if ($record->submitted_json_path !== null || $record->submitted_artifact_sha256 !== null || $record->submission_finalized_at !== null) {
            throw new AnnotationLifecycleConflictException('Finalized records cannot execute submission validation.');
        }
    }

    /**
     * Promote the exact staged artifact validated by B1 and finalize submission (S2-I6-D, ?6.3 steps 8-11).
     *
     * @return array{
     *   record:AnnotationRecord,
     *   validation_run:AnnotationValidationRun,
     *   promotion:array<string,mixed>,
     *   evidence_updated:bool,
     *   cleanup:array<string,mixed>|null,
     *   idempotent:bool
     * }
     */
    public function promoteAndFinalizeSubmissionIntent(AnnotationRecord $record, User $actor, int $expectedLockVersion): array
    {
        $this->requireAnnotationRole($actor);
        $this->assertSupportedDriver();

        $locked = $this->lockRecord($record->id);
        $this->assertCanPromoteSubmissionIntent($locked, $actor, $expectedLockVersion);

        $recordId = (string) $locked->record_id;
        $result = $this->store->withRecordPromotionLock($recordId, function () use ($record, $actor, $expectedLockVersion, $recordId): array {
            $locked = $this->lockRecord($record->id);
            $this->assertCanPromoteSubmissionIntent($locked, $actor, $expectedLockVersion);

            $run = $this->findCompletedValidationRunForActiveIntent($locked);
            $this->assertPromotionRunMatchesRecord($locked, $run);

            $idempotent = $this->maybeReturnIdempotentFinalizedSubmission($locked, $run);
            if ($idempotent !== null) {
                $evidenceUpdated = $this->promoteValidationRunEvidenceToFinalArtifact($this->lockRecord($record->id), $run);
                $cleanup = $this->cleanupPromotionTempIfPresent($this->lockRecord($record->id), $run);

                return [
                    'record' => $this->lockRecord($record->id),
                    'validation_run' => $run->fresh() ?? $run,
                    'promotion' => [
                        'idempotent' => true,
                        'relative_path' => (string) $locked->submitted_json_path,
                        'sha256' => (string) $locked->submitted_artifact_sha256,
                    ],
                    'evidence_updated' => $evidenceUpdated,
                    'cleanup' => $cleanup,
                    'idempotent' => true,
                ];
            }

            if ((string) $locked->submission_state === 'b1_completed') {
                $this->markSubmissionPromotionPending($locked, $actor, $expectedLockVersion, $run);
                $locked = $this->lockRecord($record->id);
            } elseif ((string) $locked->submission_state !== 'promotion_pending') {
                throw new AnnotationLifecycleConflictException('Submission intent is not eligible for promotion.');
            }

            $this->assertPromotionPendingPreconditions($locked, $actor, $run);

            $promotion = $this->store->publishFinalSubmittedArtifact(
                $recordId,
                (string) $locked->submission_staging_path,
                (string) $locked->submission_staging_sha256,
                (string) $run->input_artifact_sha256,
                false
            );

            $this->assertPublishedArtifactMatchesIntent($promotion, $locked, $run);

            $finalizedRecord = $this->finalizeOrRecoverPromotedSubmission($locked, $actor, $run, $promotion, $expectedLockVersion);
            $evidenceUpdated = $this->promoteValidationRunEvidenceToFinalArtifact($finalizedRecord, $run);

            $cleanup = null;
            $promotionTmp = $promotion['promotion_tmp_relative_path'] ?? null;
            if ($evidenceUpdated && is_string($promotionTmp) && $promotionTmp !== '') {
                $cleanup = $this->store->cleanupPromotionTempAfterEvidenceCommit(
                    $recordId,
                    $promotionTmp,
                    (string) $finalizedRecord->submitted_artifact_sha256,
                    (string) $run->input_artifact_sha256
                );
            } elseif ($evidenceUpdated) {
                $cleanup = $this->cleanupPromotionTempIfPresent($this->lockRecord($record->id), $run);
            }

            return [
                'record' => $this->lockRecord($record->id),
                'validation_run' => $run->fresh() ?? $run,
                'promotion' => $promotion,
                'evidence_updated' => $evidenceUpdated,
                'cleanup' => $cleanup,
                'idempotent' => false,
            ];
        });

        return $result;
    }

    private function assertCanPromoteSubmissionIntent(AnnotationRecord $record, User $actor, int $expectedLockVersion): void
    {
        $isOwner = (int) $record->created_by_user_id === (int) $actor->id;
        if (! $isOwner || ! $actor->hasAnnotationRole()) {
            throw new AnnotationLifecycleAuthorizationException('Only the draft owner with an authorized annotation role may finalize this submission.');
        }

        if ($expectedLockVersion < 1 || (int) $record->lock_version !== $expectedLockVersion) {
            throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
        }

        if ($record->discarded_at !== null) {
            throw new AnnotationLifecycleConflictException('Discarded records cannot be finalized.');
        }

        $state = (string) $record->submission_state;
        $isFinalized = $state === 'finalized' && (string) $record->artifact_state === 'submitted_finalized';
        if (! in_array($state, ['b1_completed', 'promotion_pending'], true) && ! $isFinalized) {
            throw new AnnotationLifecycleConflictException('Submission intent is not eligible for promotion.');
        }

        if ((string) $record->workflow_status !== 'draft' && ! $isFinalized) {
            throw new AnnotationLifecycleConflictException('Only draft records can be promoted and finalized.');
        }

        if ((string) $record->artifact_state !== 'draft' && ! $isFinalized) {
            throw new AnnotationLifecycleConflictException('Record artifact state is not eligible for promotion.');
        }

        if (! is_string($record->submission_intent_id) || trim($record->submission_intent_id) === ''
            || ! is_string($record->submission_staging_path) || trim($record->submission_staging_path) === ''
            || ! is_string($record->submission_staging_sha256) || trim($record->submission_staging_sha256) === '') {
            throw new AnnotationLifecycleValidationException('Active submission intent metadata is missing or invalid.');
        }

        $stagingSha = strtolower((string) $record->submission_staging_sha256);
        if (preg_match('/\A[a-f0-9]{64}\z/', $stagingSha) !== 1) {
            throw new AnnotationLifecycleValidationException('Submission staging SHA-256 is invalid.');
        }

        if (! $isFinalized) {
            if ($record->submitted_json_path !== null || $record->submitted_artifact_sha256 !== null || $record->submission_finalized_at !== null || $record->submitted_by_user_id !== null || $record->submitted_at !== null) {
                throw new AnnotationLifecycleConflictException('Record already has finalized submission metadata.');
            }
        }
    }

    private function findCompletedValidationRunForActiveIntent(AnnotationRecord $record): AnnotationValidationRun
    {
        $run = AnnotationValidationRun::query()
            ->where('annotation_record_id', $record->id)
            ->where('submission_intent_id', (string) $record->submission_intent_id)
            ->orderByDesc('id')
            ->first();

        if (! $run instanceof AnnotationValidationRun) {
            throw new AnnotationLifecycleConflictException('Completed B1 validation run for the active submission intent was not found.');
        }

        if ((string) $run->status !== 'completed') {
            throw new AnnotationLifecycleConflictException('Submission intent is not eligible for promotion because B1 validation is not completed.');
        }

        if (! is_bool($run->is_valid)) {
            throw new AnnotationLifecycleConflictException('Completed B1 validation run is missing validity metadata.');
        }

        return $run;
    }

    private function assertPromotionRunMatchesRecord(AnnotationRecord $record, AnnotationValidationRun $run): void
    {
        if ((int) $run->annotation_record_id !== (int) $record->id) {
            throw new AnnotationLifecycleConflictException('Validation run does not belong to the record.');
        }

        if ((string) $run->submission_intent_id !== (string) $record->submission_intent_id) {
            throw new AnnotationLifecycleConflictException('Validation run does not belong to the active submission intent.');
        }

        $runPath = is_string($run->input_artifact_path) ? trim($run->input_artifact_path) : '';
        $runSha = is_string($run->input_artifact_sha256) ? strtolower(trim($run->input_artifact_sha256)) : '';
        $stagingPath = (string) $record->submission_staging_path;
        $stagingSha = strtolower((string) $record->submission_staging_sha256);

        if ($runPath === '' || preg_match('/\A[a-f0-9]{64}\z/', $runSha) !== 1) {
            throw new AnnotationLifecycleConflictException('Completed B1 validation run input metadata is invalid.');
        }

        if (! hash_equals($runPath, $stagingPath) || ! hash_equals($runSha, $stagingSha)) {
            throw new AnnotationLifecycleConflictException('Staged submission artifact does not match the completed B1 validation input.');
        }
    }

    private function assertPromotionPendingPreconditions(AnnotationRecord $record, User $actor, AnnotationValidationRun $run): void
    {
        $this->assertCanPromoteSubmissionIntent($record, $actor, (int) $record->lock_version);
        if ((string) $record->submission_state !== 'promotion_pending') {
            throw new AnnotationLifecycleConflictException('Submission intent is not promotion_pending.');
        }
        $this->assertPromotionRunMatchesRecord($record, $run);
    }

    private function markSubmissionPromotionPending(AnnotationRecord $record, User $actor, int $expectedLockVersion, AnnotationValidationRun $run): void
    {
        DB::transaction(function () use ($record, $actor, $expectedLockVersion, $run): void {
            $txLocked = $this->lockRecord($record->id);
            $this->assertCanPromoteSubmissionIntent($txLocked, $actor, $expectedLockVersion);
            $this->assertPromotionRunMatchesRecord($txLocked, $run);
            if ((string) $txLocked->submission_state !== 'b1_completed') {
                throw new AnnotationLifecycleConflictException('Submission intent is not eligible for promotion pending transition.');
            }

            $nextLockVersion = (int) $txLocked->lock_version + 1;
            $updatedAt = now();
            $updatedRows = AnnotationRecord::query()
                ->whereKey($txLocked->id)
                ->where('lock_version', $expectedLockVersion)
                ->where('submission_intent_id', (string) $record->submission_intent_id)
                ->where('submission_state', 'b1_completed')
                ->where('submission_staging_path', (string) $record->submission_staging_path)
                ->where('submission_staging_sha256', (string) $record->submission_staging_sha256)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereNull('discarded_at')
                ->whereNull('submitted_json_path')
                ->whereNull('submitted_artifact_sha256')
                ->whereNull('submission_finalized_at')
                ->update([
                    'submission_state' => 'promotion_pending',
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $updatedAt,
                ]);

            if ($updatedRows !== 1) {
                $fresh = $this->lockRecord($record->id);
                $this->assertCanPromoteSubmissionIntent($fresh, $actor, $expectedLockVersion);
                throw new AnnotationLifecycleConflictException('Stale submission intent or lock_version; reload the record and retry.');
            }

            $fresh = $this->lockRecord($record->id);
            $this->appendAudit(
                record: $fresh,
                actor: $actor,
                actorType: 'user',
                action: 'submission_promotion_pending',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: 'draft',
                comment: null,
                metadata: [
                    'submission_intent_id' => (string) $fresh->submission_intent_id,
                    'validation_run_id' => (string) $run->run_id,
                    'from_submission_state' => 'b1_completed',
                    'to_submission_state' => 'promotion_pending',
                    'previous_lock_version' => $expectedLockVersion,
                    'lock_version' => (int) $fresh->lock_version,
                    'submission_staging_path' => (string) $fresh->submission_staging_path,
                    'submission_staging_sha256' => (string) $fresh->submission_staging_sha256,
                    'b1_valid' => (bool) $run->is_valid,
                ],
            );
        });
    }

    /**
     * @param  array<string,mixed>  $promotion
     */
    private function assertPublishedArtifactMatchesIntent(array $promotion, AnnotationRecord $record, AnnotationValidationRun $run): void
    {
        $finalPath = is_string($promotion['relative_path'] ?? null) ? $promotion['relative_path'] : '';
        $finalSha = is_string($promotion['sha256'] ?? null) ? strtolower($promotion['sha256']) : '';
        $expected = strtolower((string) $record->submission_staging_sha256);
        $runSha = strtolower((string) $run->input_artifact_sha256);

        if ($finalPath === '' || ! hash_equals($finalSha, $expected) || ! hash_equals($finalSha, $runSha)) {
            throw new AnnotationLifecycleConflictException('Promoted final artifact does not match the staged submission intent.');
        }
    }

    /**
     * @param  array<string,mixed>  $promotion
     */
    private function finalizeOrRecoverPromotedSubmission(AnnotationRecord $record, User $actor, AnnotationValidationRun $run, array $promotion, int $expectedLockVersion): AnnotationRecord
    {
        $locked = $this->lockRecord($record->id);
        if ((string) $locked->submission_state === 'finalized') {
            $idempotent = $this->maybeReturnIdempotentFinalizedSubmission($locked, $run);
            if ($idempotent !== null) {
                return $this->lockRecord($record->id);
            }
            throw new AnnotationLifecycleConflictException('Finalized submission metadata is inconsistent.');
        }

        $this->assertCanPromoteSubmissionIntent($locked, $actor, (int) $locked->lock_version);
        $this->assertPromotionRunMatchesRecord($locked, $run);

        $expectedFinalizeLockVersion = (string) $locked->submission_state === 'promotion_pending'
            ? (int) $locked->lock_version
            : $expectedLockVersion;

        return $this->finalizePromotedSubmission($locked, $actor, $run, $promotion, $expectedFinalizeLockVersion);
    }

    /**
     * @param  array<string,mixed>  $promotion
     */
    private function finalizePromotedSubmission(AnnotationRecord $record, User $actor, AnnotationValidationRun $run, array $promotion, int $expectedFinalizeLockVersion): AnnotationRecord
    {
        return DB::transaction(function () use ($record, $actor, $run, $promotion, $expectedFinalizeLockVersion): AnnotationRecord {
            $txLocked = $this->lockRecord($record->id);
            $this->assertCanPromoteSubmissionIntent($txLocked, $actor, (int) $txLocked->lock_version);
            $this->assertPromotionRunMatchesRecord($txLocked, $run);
            if ((int) $txLocked->lock_version !== $expectedFinalizeLockVersion) {
                throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
            }
            if ((string) $txLocked->submission_state !== 'promotion_pending') {
                $idempotent = $this->maybeReturnIdempotentFinalizedSubmission($txLocked, $run);
                if ($idempotent !== null) {
                    return $this->lockRecord($record->id);
                }
                throw new AnnotationLifecycleConflictException('Submission intent is not promotion_pending.');
            }

            $finalPath = (string) ($promotion['relative_path'] ?? '');
            $finalSha = strtolower((string) ($promotion['sha256'] ?? ''));
            if ($finalPath === '' || ! hash_equals($finalSha, strtolower((string) $txLocked->submission_staging_sha256))) {
                throw new AnnotationLifecycleConflictException('Final artifact verification failed before DB finalization.');
            }

            $verifiedFinal = $this->store->verifyStoredArtifact($finalPath, $finalSha);
            if (! hash_equals(strtolower((string) $verifiedFinal['sha256']), $finalSha)) {
                throw new AnnotationLifecycleConflictException('Final artifact hash verification failed.');
            }

            $nextLockVersion = (int) $txLocked->lock_version + 1;
            $now = now();
            $workflowStatus = $run->is_valid ? 'validated' : 'validation_failed';
            $updatedRows = AnnotationRecord::query()
                ->whereKey($txLocked->id)
                ->where('lock_version', $expectedFinalizeLockVersion)
                ->where('submission_intent_id', (string) $txLocked->submission_intent_id)
                ->where('submission_state', 'promotion_pending')
                ->where('submission_staging_path', (string) $txLocked->submission_staging_path)
                ->where('submission_staging_sha256', (string) $txLocked->submission_staging_sha256)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereNull('discarded_at')
                ->whereNull('submitted_json_path')
                ->whereNull('submitted_artifact_sha256')
                ->whereNull('submission_finalized_at')
                ->whereNull('submitted_by_user_id')
                ->whereNull('submitted_at')
                ->update([
                    'artifact_state' => 'submitted_finalized',
                    'submitted_json_path' => $finalPath,
                    'submitted_artifact_sha256' => $finalSha,
                    'submitted_by_user_id' => $actor->id,
                    'submitted_at' => $now,
                    'submission_finalized_at' => $now,
                    'submission_state' => 'finalized',
                    'workflow_status' => $workflowStatus,
                    'latest_validation_run_id' => $run->id,
                    'latest_b1_valid' => (bool) $run->is_valid,
                    'is_export_eligible_canonical' => (bool) $run->is_export_eligible_canonical,
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $now,
                ]);

            if ($updatedRows !== 1) {
                $fresh = $this->lockRecord($record->id);
                $idempotent = $this->maybeReturnIdempotentFinalizedSubmission($fresh, $run);
                if ($idempotent !== null) {
                    return $this->lockRecord($record->id);
                }
                $this->assertCanPromoteSubmissionIntent($fresh, $actor, (int) $fresh->lock_version);
                throw new AnnotationLifecycleConflictException('Stale submission intent or lock_version; reload the record and retry.');
            }

            $fresh = $this->lockRecord($record->id);
            $this->appendAudit(
                record: $fresh,
                actor: $actor,
                actorType: 'user',
                action: 'submission_finalized',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: $workflowStatus,
                comment: null,
                metadata: [
                    'submission_intent_id' => (string) $fresh->submission_intent_id,
                    'validation_run_id' => (string) $run->run_id,
                    'validation_run_db_id' => (int) $run->id,
                    'from_submission_state' => 'promotion_pending',
                    'to_submission_state' => 'finalized',
                    'previous_lock_version' => $nextLockVersion - 1,
                    'lock_version' => (int) $fresh->lock_version,
                    'submission_staging_path' => (string) $fresh->submission_staging_path,
                    'submission_staging_sha256' => (string) $fresh->submission_staging_sha256,
                    'submitted_json_path' => (string) $fresh->submitted_json_path,
                    'submitted_artifact_sha256' => (string) $fresh->submitted_artifact_sha256,
                    'b1_status' => (string) $run->status,
                    'b1_valid' => (bool) $run->is_valid,
                    'b1_is_export_eligible_canonical' => (bool) $run->is_export_eligible_canonical,
                    'b1_error_count' => (int) $run->error_count,
                    'b1_warning_count' => (int) $run->warning_count,
                    'b1_export_block_count' => (int) $run->export_block_count,
                ],
            );

            return $fresh->fresh();
        });
    }

    /**
     * Reconciliation-only evidence promotion. Requires trusted principal and current DB research_admin.
     * Normal lifecycle promotion remains internal to submission finalization flows.
     */
    public function promoteFinalizedValidationRunEvidenceForReconciliation(
        AnnotationRecord $record,
        AnnotationValidationRun $run,
        TrustedReconciliationPrincipal $principal
    ): bool {
        $this->assertCurrentReconciliationResearchAdmin($principal);

        return $this->promoteValidationRunEvidenceToFinalArtifact($record, $run);
    }

    private function promoteValidationRunEvidenceToFinalArtifact(AnnotationRecord $record, AnnotationValidationRun $run): bool
    {
        return DB::transaction(function () use ($record, $run): bool {
            $freshRecord = $this->lockRecord($record->id);
            if ((string) $freshRecord->submission_state !== 'finalized' || (string) $freshRecord->artifact_state !== 'submitted_finalized') {
                throw new AnnotationLifecycleConflictException('Record is not finalized for evidence promotion.');
            }
            if ((string) $freshRecord->submission_intent_id !== (string) $run->submission_intent_id) {
                throw new AnnotationLifecycleConflictException('Finalized record intent does not match validation run.');
            }

            $finalPath = is_string($freshRecord->submitted_json_path) ? $freshRecord->submitted_json_path : '';
            $finalSha = strtolower((string) $freshRecord->submitted_artifact_sha256);
            $inputSha = strtolower((string) $run->input_artifact_sha256);
            if ($finalPath === '' || ! hash_equals($finalSha, $inputSha)) {
                throw new AnnotationLifecycleConflictException('Final artifact metadata does not match validation run input hash.');
            }

            $this->store->verifyStoredArtifact($finalPath, $finalSha);

            $updated = AnnotationValidationRun::query()
                ->where('annotation_record_id', $freshRecord->id)
                ->where('submission_intent_id', (string) $run->submission_intent_id)
                ->where('input_artifact_sha256', (string) $run->input_artifact_sha256)
                ->whereIn('input_evidence_state', ['staged_path', 'retained_staging_copy', 'final_artifact'])
                ->update([
                    'input_evidence_state' => 'final_artifact',
                    'durable_input_evidence_path' => $finalPath,
                    'durable_input_evidence_sha256' => $finalSha,
                    'updated_at' => now(),
                ]);

            if ($updated < 1) {
                $existing = AnnotationValidationRun::query()
                    ->where('annotation_record_id', $freshRecord->id)
                    ->where('submission_intent_id', (string) $run->submission_intent_id)
                    ->where('input_evidence_state', 'final_artifact')
                    ->where('durable_input_evidence_path', $finalPath)
                    ->where('durable_input_evidence_sha256', $finalSha)
                    ->exists();
                if (! $existing) {
                    throw new AnnotationLifecycleConflictException('Validation run evidence could not be promoted to final artifact.');
                }
            }

            return true;
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    private function maybeReturnIdempotentFinalizedSubmission(AnnotationRecord $record, AnnotationValidationRun $run): ?array
    {
        if ((string) $record->submission_state !== 'finalized' || (string) $record->artifact_state !== 'submitted_finalized') {
            return null;
        }

        $finalPath = is_string($record->submitted_json_path) ? $record->submitted_json_path : '';
        $finalSha = strtolower((string) $record->submitted_artifact_sha256);
        $inputSha = strtolower((string) $run->input_artifact_sha256);
        $expectedWorkflow = $run->is_valid ? 'validated' : 'validation_failed';

        if ($finalPath === '' || $finalSha === '' || ! hash_equals($finalSha, $inputSha)) {
            throw new AnnotationLifecycleConflictException('Finalized submission metadata is inconsistent.');
        }

        if ((string) $record->workflow_status !== $expectedWorkflow) {
            throw new AnnotationLifecycleConflictException('Finalized submission workflow state is inconsistent.');
        }

        $this->store->verifyStoredArtifact($finalPath, $finalSha);

        $runMatches = AnnotationValidationRun::query()
            ->where('annotation_record_id', $record->id)
            ->where('submission_intent_id', (string) $record->submission_intent_id)
            ->where('input_artifact_sha256', $inputSha)
            ->exists();
        if (! $runMatches) {
            throw new AnnotationLifecycleConflictException('Finalized submission validation evidence is inconsistent.');
        }

        return [
            'relative_path' => $finalPath,
            'sha256' => $finalSha,
        ];
    }

    /**
     * Reconcile a verified final artifact for an active submission intent.
     * Caller MUST hold the per-record promotion filesystem lock.
     *
     * I6-D parity note: this reconciliation path intentionally remains separate from
     * promoteAndFinalizeSubmissionIntent/finalizePromotedSubmission/markSubmissionPromotionPending.
     * The normal path enforces owner + expectedLockVersion actor flow and draft-only promotion;
     * reconciliation allows owner-or-research_admin repair of verified-final anomalies and already-
     * finalized evidence sync, while reusing I6-D invariants/helpers where safe. Shared extraction
     * was avoided because authorization, idempotent-repair, and audit metadata differ subtly and
     * broad refactor risks I6-D behavior. Keep these invariants equivalent: intent/staging/run/final
     * hash correlation, no-replace/conflict fail-closed, lock_version CAS, workflow mapping by
     * run->is_valid, submitted artifact/hash/timestamps/cache pointers, submission_promotion_pending
     * and submission_finalized audit atomicity, evidence promotion only for verified finalized final,
     * and promotion temp cleanup only after durable evidence commit.
     *
     * @param  array<string,mixed>  $promotion
     * @return array{record:AnnotationRecord,validation_run:AnnotationValidationRun,promotion:array<string,mixed>,evidence_updated:bool,cleanup:array<string,mixed>|null,idempotent:bool}
     */
    public function reconcileVerifiedFinalizedPromotion(
        AnnotationRecord $record,
        AnnotationValidationRun $run,
        array $promotion,
        TrustedReconciliationPrincipal $principal
    ): array {
        $this->assertSupportedDriver();

        $actor = $this->assertCurrentReconciliationResearchAdmin($principal);

        $locked = $this->lockRecord($record->id);
        $this->assertReconciliationPromotionPreconditions($locked, $run, $promotion, $actor);

        $expectedLockVersion = (int) $locked->lock_version;
        $idempotent = $this->maybeReturnIdempotentFinalizedSubmission($locked, $run);
        if ($idempotent !== null) {
            $evidenceUpdated = $this->promoteValidationRunEvidenceToFinalArtifact($this->lockRecord($record->id), $run);
            $cleanup = $this->cleanupPromotionTempIfPresent($this->lockRecord($record->id), $run);

            return [
                'record' => $this->lockRecord($record->id),
                'validation_run' => $run->fresh() ?? $run,
                'promotion' => $promotion + ['idempotent' => true],
                'evidence_updated' => $evidenceUpdated,
                'cleanup' => $cleanup,
                'idempotent' => true,
            ];
        }

        if ((string) $locked->submission_state === 'b1_completed') {
            $this->markSubmissionPromotionPendingForReconciliation($locked, $run, $actor, $expectedLockVersion);
            $locked = $this->lockRecord($record->id);
            $expectedLockVersion = (int) $locked->lock_version;
        }

        $this->assertReconciliationPromotionPreconditions($locked, $run, $promotion, $actor);
        $idempotent = $this->maybeReturnIdempotentFinalizedSubmission($locked, $run);
        if ($idempotent !== null) {
            $evidenceUpdated = $this->promoteValidationRunEvidenceToFinalArtifact($this->lockRecord($record->id), $run);
            $cleanup = $this->cleanupPromotionTempIfPresent($this->lockRecord($record->id), $run);

            return [
                'record' => $this->lockRecord($record->id),
                'validation_run' => $run->fresh() ?? $run,
                'promotion' => $promotion + ['idempotent' => true],
                'evidence_updated' => $evidenceUpdated,
                'cleanup' => $cleanup,
                'idempotent' => true,
            ];
        }
        if ((string) $locked->submission_state !== 'promotion_pending') {
            throw new AnnotationLifecycleConflictException('Submission intent is not eligible for reconciliation finalization.');
        }

        $finalizedRecord = $this->finalizePromotedSubmissionForReconciliation($locked, $run, $promotion, $expectedLockVersion, $actor);
        $evidenceUpdated = $this->promoteValidationRunEvidenceToFinalArtifact($finalizedRecord, $run);
        $cleanup = null;
        $promotionTmp = $promotion['promotion_tmp_relative_path'] ?? null;
        if ($evidenceUpdated && is_string($promotionTmp) && $promotionTmp !== '') {
            $cleanup = $this->store->cleanupPromotionTempAfterEvidenceCommit(
                (string) $finalizedRecord->record_id,
                $promotionTmp,
                (string) $finalizedRecord->submitted_artifact_sha256,
                (string) $run->input_artifact_sha256
            );
        } elseif ($evidenceUpdated) {
            $cleanup = $this->cleanupPromotionTempIfPresent($this->lockRecord($record->id), $run);
        }

        return [
            'record' => $this->lockRecord($record->id),
            'validation_run' => $run->fresh() ?? $run,
            'promotion' => $promotion,
            'evidence_updated' => $evidenceUpdated,
            'cleanup' => $cleanup,
            'idempotent' => false,
        ];
    }

    private function assertCurrentReconciliationResearchAdmin(TrustedReconciliationPrincipal $principal): User
    {
        $actor = User::query()->whereKey($principal->userId())->first();
        if (! $actor instanceof User || ! $actor->isResearchAdmin()) {
            throw new AnnotationLifecycleAuthorizationException(
                'Reconciliation requires an authenticated, currently authorized research_admin principal.'
            );
        }

        return $actor;
    }

    /**
     * @param  array<string,mixed>  $promotion
     */
    private function assertReconciliationPromotionPreconditions(AnnotationRecord $record, AnnotationValidationRun $run, array $promotion, ?User $actor): void
    {
        if (! $actor instanceof User || ! $actor->isResearchAdmin()) {
            throw new AnnotationLifecycleAuthorizationException(
                'Reconciliation requires an authenticated, currently authorized research_admin principal.'
            );
        }

        if ($record->discarded_at !== null) {
            throw new AnnotationLifecycleConflictException('Discarded records cannot be finalized.');
        }

        $state = (string) $record->submission_state;
        $isFinalized = $state === 'finalized' && (string) $record->artifact_state === 'submitted_finalized';
        if (! in_array($state, ['b1_completed', 'promotion_pending'], true) && ! $isFinalized) {
            throw new AnnotationLifecycleConflictException('Submission intent is not eligible for promotion.');
        }

        if (! is_string($record->submission_intent_id) || trim($record->submission_intent_id) === ''
            || ! is_string($record->submission_staging_path) || trim($record->submission_staging_path) === ''
            || ! is_string($record->submission_staging_sha256) || trim($record->submission_staging_sha256) === '') {
            throw new AnnotationLifecycleValidationException('Active submission intent metadata is missing or invalid.');
        }

        $stagingSha = strtolower((string) $record->submission_staging_sha256);
        if (preg_match('/\A[a-f0-9]{64}\z/', $stagingSha) !== 1) {
            throw new AnnotationLifecycleValidationException('Submission staging SHA-256 is invalid.');
        }

        $this->assertPromotionRunMatchesRecord($record, $run);
        $this->assertPublishedArtifactMatchesIntent($promotion, $record, $run);

        $finalPath = is_string($promotion['relative_path'] ?? null) ? $promotion['relative_path'] : '';
        $finalSha = strtolower((string) ($promotion['sha256'] ?? ''));
        $this->store->verifyStoredArtifact($finalPath, $finalSha);

        if (! $isFinalized) {
            if ((string) $record->workflow_status !== 'draft' || (string) $record->artifact_state !== 'draft') {
                throw new AnnotationLifecycleConflictException('Only draft records can be promoted and finalized.');
            }
            if ($record->submitted_json_path !== null || $record->submitted_artifact_sha256 !== null || $record->submission_finalized_at !== null || $record->submitted_by_user_id !== null || $record->submitted_at !== null) {
                throw new AnnotationLifecycleConflictException('Record already has finalized submission metadata.');
            }
        }
    }

    private function markSubmissionPromotionPendingForReconciliation(AnnotationRecord $record, AnnotationValidationRun $run, ?User $actor, int $expectedLockVersion): void
    {
        DB::transaction(function () use ($record, $run, $actor, $expectedLockVersion): void {
            $txLocked = $this->lockRecord($record->id);
            $finalPath = $this->normalizeNullableString($txLocked->submitted_json_path, 'submitted_json_path')
                ?? $this->store->finalSubmittedRelativePathForRecord((string) $txLocked->record_id);
            $this->assertReconciliationPromotionPreconditions($txLocked, $run, [
                'relative_path' => $finalPath,
                'sha256' => (string) $txLocked->submission_staging_sha256,
            ], $actor);
            if ((int) $txLocked->lock_version !== $expectedLockVersion || (string) $txLocked->submission_state !== 'b1_completed') {
                throw new AnnotationLifecycleConflictException('Stale submission intent or lock_version; reload the record and retry.');
            }

            $nextLockVersion = $expectedLockVersion + 1;
            $updatedAt = now();
            $updatedRows = AnnotationRecord::query()
                ->whereKey($txLocked->id)
                ->where('lock_version', $expectedLockVersion)
                ->where('submission_intent_id', (string) $record->submission_intent_id)
                ->where('submission_state', 'b1_completed')
                ->where('submission_staging_path', (string) $record->submission_staging_path)
                ->where('submission_staging_sha256', (string) $record->submission_staging_sha256)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereNull('discarded_at')
                ->whereNull('submitted_json_path')
                ->whereNull('submitted_artifact_sha256')
                ->whereNull('submission_finalized_at')
                ->update([
                    'submission_state' => 'promotion_pending',
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $updatedAt,
                ]);

            if ($updatedRows !== 1) {
                throw new AnnotationLifecycleConflictException('Stale submission intent or lock_version; reload the record and retry.');
            }

            $fresh = $this->lockRecord($record->id);
            $this->appendAudit(
                record: $fresh,
                actor: $actor,
                actorType: $actor instanceof User ? 'user' : 'system',
                action: 'submission_promotion_pending',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: 'draft',
                comment: null,
                metadata: [
                    'submission_intent_id' => (string) $fresh->submission_intent_id,
                    'validation_run_id' => (string) $run->run_id,
                    'from_submission_state' => 'b1_completed',
                    'to_submission_state' => 'promotion_pending',
                    'previous_lock_version' => $expectedLockVersion,
                    'lock_version' => (int) $fresh->lock_version,
                    'submission_staging_path' => (string) $fresh->submission_staging_path,
                    'submission_staging_sha256' => (string) $fresh->submission_staging_sha256,
                    'b1_valid' => (bool) $run->is_valid,
                    'reconciliation' => true,
                ],
            );
        });
    }

    /**
     * @param  array<string,mixed>  $promotion
     */
    private function finalizePromotedSubmissionForReconciliation(AnnotationRecord $record, AnnotationValidationRun $run, array $promotion, int $expectedFinalizeLockVersion, ?User $actor): AnnotationRecord
    {
        return DB::transaction(function () use ($record, $run, $promotion, $expectedFinalizeLockVersion, $actor): AnnotationRecord {
            $txLocked = $this->lockRecord($record->id);
            $this->assertReconciliationPromotionPreconditions($txLocked, $run, $promotion, $actor);
            if ((int) $txLocked->lock_version !== $expectedFinalizeLockVersion) {
                throw new AnnotationLifecycleConflictException('Stale lock_version; reload the record and retry.');
            }
            if ((string) $txLocked->submission_state !== 'promotion_pending') {
                $idempotent = $this->maybeReturnIdempotentFinalizedSubmission($txLocked, $run);
                if ($idempotent !== null) {
                    return $this->lockRecord($record->id);
                }
                throw new AnnotationLifecycleConflictException('Submission intent is not promotion_pending.');
            }

            $finalPath = (string) ($promotion['relative_path'] ?? '');
            $finalSha = strtolower((string) ($promotion['sha256'] ?? ''));
            $verifiedFinal = $this->store->verifyStoredArtifact($finalPath, $finalSha);
            if (! hash_equals(strtolower((string) $verifiedFinal['sha256']), $finalSha)) {
                throw new AnnotationLifecycleConflictException('Final artifact hash verification failed.');
            }

            $nextLockVersion = (int) $txLocked->lock_version + 1;
            $now = now();
            $workflowStatus = $run->is_valid ? 'validated' : 'validation_failed';
            $updatedRows = AnnotationRecord::query()
                ->whereKey($txLocked->id)
                ->where('lock_version', $expectedFinalizeLockVersion)
                ->where('submission_intent_id', (string) $txLocked->submission_intent_id)
                ->where('submission_state', 'promotion_pending')
                ->where('submission_staging_path', (string) $txLocked->submission_staging_path)
                ->where('submission_staging_sha256', (string) $txLocked->submission_staging_sha256)
                ->where('workflow_status', 'draft')
                ->where('artifact_state', 'draft')
                ->whereNull('discarded_at')
                ->whereNull('submitted_json_path')
                ->whereNull('submitted_artifact_sha256')
                ->whereNull('submission_finalized_at')
                ->whereNull('submitted_by_user_id')
                ->whereNull('submitted_at')
                ->update([
                    'artifact_state' => 'submitted_finalized',
                    'submitted_json_path' => $finalPath,
                    'submitted_artifact_sha256' => $finalSha,
                    'submitted_by_user_id' => $actor?->id,
                    'submitted_at' => $now,
                    'submission_finalized_at' => $now,
                    'submission_state' => 'finalized',
                    'workflow_status' => $workflowStatus,
                    'latest_validation_run_id' => $run->id,
                    'latest_b1_valid' => (bool) $run->is_valid,
                    'is_export_eligible_canonical' => (bool) $run->is_export_eligible_canonical,
                    'lock_version' => $nextLockVersion,
                    'updated_at' => $now,
                ]);

            if ($updatedRows !== 1) {
                $fresh = $this->lockRecord($record->id);
                $idempotent = $this->maybeReturnIdempotentFinalizedSubmission($fresh, $run);
                if ($idempotent !== null) {
                    return $this->lockRecord($record->id);
                }
                throw new AnnotationLifecycleConflictException('Stale submission intent or lock_version; reload the record and retry.');
            }

            $fresh = $this->lockRecord($record->id);
            $this->appendAudit(
                record: $fresh,
                actor: $actor,
                actorType: $actor instanceof User ? 'user' : 'system',
                action: 'submission_finalized',
                fromWorkflowStatus: 'draft',
                toWorkflowStatus: $workflowStatus,
                comment: null,
                metadata: [
                    'submission_intent_id' => (string) $fresh->submission_intent_id,
                    'validation_run_id' => (string) $run->run_id,
                    'validation_run_db_id' => (int) $run->id,
                    'from_submission_state' => 'promotion_pending',
                    'to_submission_state' => 'finalized',
                    'previous_lock_version' => $nextLockVersion - 1,
                    'lock_version' => (int) $fresh->lock_version,
                    'submission_staging_path' => (string) $fresh->submission_staging_path,
                    'submission_staging_sha256' => (string) $fresh->submission_staging_sha256,
                    'submitted_json_path' => (string) $fresh->submitted_json_path,
                    'submitted_artifact_sha256' => (string) $fresh->submitted_artifact_sha256,
                    'b1_status' => (string) $run->status,
                    'b1_valid' => (bool) $run->is_valid,
                    'b1_is_export_eligible_canonical' => (bool) $run->is_export_eligible_canonical,
                    'b1_error_count' => (int) $run->error_count,
                    'b1_warning_count' => (int) $run->warning_count,
                    'b1_export_block_count' => (int) $run->export_block_count,
                    'reconciliation' => true,
                ],
            );

            return $fresh->fresh();
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cleanupPromotionTempIfPresent(AnnotationRecord $record, AnnotationValidationRun $run): ?array
    {
        $inspection = $this->store->inspectRecordArtifacts((string) $record->record_id);
        $temps = is_array($inspection['promotion_temps'] ?? null) ? $inspection['promotion_temps'] : [];
        $cleanup = null;
        foreach ($temps as $temp) {
            $path = is_array($temp) && is_string($temp['relative_path'] ?? null) ? $temp['relative_path'] : null;
            if ($path === null) {
                continue;
            }
            $cleanup = $this->store->cleanupPromotionTempAfterEvidenceCommit(
                (string) $record->record_id,
                $path,
                (string) $record->submitted_artifact_sha256,
                (string) $run->input_artifact_sha256
            );
        }

        return $cleanup;
    }

    private function validationPersistence(): AnnotationValidationPersistenceService
    {
        $service = app(AnnotationValidationPersistenceService::class);
        if (! $service instanceof AnnotationValidationPersistenceService) {
            throw new RuntimeException('Annotation validation persistence service is unavailable.');
        }

        return $service;
    }
}
