<?php

namespace App\Services\Annotation;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\AnnotationValidationRun;
use App\Models\User;
use App\Services\Annotation\Exceptions\AnnotationLifecycleAuthorizationException;
use App\Services\Annotation\Reconciliation\ReconciliationMode;
use App\Services\Annotation\Reconciliation\TrustedReconciliationPrincipal;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
use App\Services\Annotation\Storage\Exceptions\ArtifactLockException;
use Illuminate\Support\Facades\DB;

class AnnotationReconciliationService
{
    public function __construct(
        private readonly AnnotationArtifactStore $store = new AnnotationArtifactStore,
        private readonly ?AnnotationRecordLifecycleService $lifecycle = null,
    ) {}

    private function lifecycle(): AnnotationRecordLifecycleService
    {
        return $this->lifecycle ?? app(AnnotationRecordLifecycleService::class);
    }

    /**
     * @return array<string,mixed>
     */
    public function reportRecord(
        int|string $record,
        TrustedReconciliationPrincipal $principal,
        ReconciliationMode $mode
    ): array {
        $this->assertReportMode($mode);
        $this->assertPrincipalAuthorized($principal);

        $model = $this->resolveRecord($record);
        if ($model === null) {
            return [
                'record_id' => is_scalar($record) ? (string) $record : null,
                'status' => 'not_found',
                'findings' => [],
                'repairs' => [],
            ];
        }

        $report = $this->reportForRecord($model);
        $report['repairs'] = [];

        return $report;
    }

    /**
     * @return array<string,mixed>
     */
    public function reportAll(TrustedReconciliationPrincipal $principal, ReconciliationMode $mode): array
    {
        $this->assertReportMode($mode);
        $this->assertPrincipalAuthorized($principal);

        return $this->scanAll($mode, $principal, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function reconcileRecord(
        int|string $record,
        TrustedReconciliationPrincipal $principal,
        ReconciliationMode $mode
    ): array {
        $this->assertPrincipalAuthorized($principal);

        $model = $this->resolveRecord($record);
        if ($model === null) {
            return [
                'record_id' => is_scalar($record) ? (string) $record : null,
                'status' => 'not_found',
                'findings' => [],
                'repairs' => [],
            ];
        }

        $report = $this->reportForRecord($model);
        $report['repairs'] = $mode->allowsRepairs()
            ? $this->applyRepairs($model, $report['findings'], $principal)
            : [];

        return $report;
    }

    /**
     * @return array<string,mixed>
     */
    public function reconcileAll(TrustedReconciliationPrincipal $principal, ReconciliationMode $mode): array
    {
        $this->assertPrincipalAuthorized($principal);

        return $this->scanAll($mode, $principal, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function scanAll(ReconciliationMode $mode, TrustedReconciliationPrincipal $principal, bool $allowRepairs): array
    {
        $records = AnnotationRecord::query()->orderBy('id')->get();
        $reports = [];
        $summary = [
            'records_scanned' => 0,
            'records_with_findings' => 0,
            'findings' => 0,
            'retryable_deferrals' => 0,
            'integrity_incidents' => 0,
            'repair_candidates' => 0,
            'repairs_applied' => 0,
            'repair_failures' => 0,
        ];

        foreach ($records as $record) {
            $report = $this->reportForRecord($record);
            $report['repairs'] = $allowRepairs && $mode->allowsRepairs()
                ? $this->applyRepairs($record, $report['findings'], $principal)
                : [];
            $reports[] = $report;
            $summary['records_scanned']++;
            $findingCount = count($report['findings']);
            $summary['findings'] += $findingCount;
            if ($findingCount > 0) {
                $summary['records_with_findings']++;
            }
            foreach ($report['findings'] as $finding) {
                if (($finding['classification'] ?? null) === 'retryable_deferral') {
                    $summary['retryable_deferrals']++;
                }
                if (($finding['classification'] ?? null) === 'integrity_incident') {
                    $summary['integrity_incidents']++;
                }
            }
            foreach ($report['repairs'] as $repair) {
                $status = (string) ($repair['status'] ?? '');
                if (in_array($status, ['applied', 'idempotent'], true)) {
                    $summary['repairs_applied']++;
                } elseif ($status === 'candidate') {
                    $summary['repair_candidates']++;
                } elseif (in_array($status, ['failed', 'deferred'], true)) {
                    $summary['repair_failures']++;
                }
            }
            if (! ($allowRepairs && $mode->allowsRepairs())) {
                $summary['repair_candidates'] += $this->countRepairCandidates($report['findings']);
            }
        }

        return [
            'status' => 'ok',
            'summary' => $summary,
            'records' => $reports,
        ];
    }

    private function assertReportMode(ReconciliationMode $mode): void
    {
        if ($mode === ReconciliationMode::APPLY) {
            throw new \InvalidArgumentException('Report methods do not accept APPLY mode; use reconcileRecord/reconcileAll for repairs.');
        }
    }

    private function assertPrincipalAuthorized(TrustedReconciliationPrincipal $principal): void
    {
        $actor = User::query()->whereKey($principal->userId())->first();
        if (! $actor instanceof User || ! $actor->isResearchAdmin()) {
            throw new AnnotationLifecycleAuthorizationException(
                'Reconciliation requires an authenticated, currently authorized research_admin principal.'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function reportForRecord(AnnotationRecord $record): array
    {
        $findings = [];

        $this->detectDraftFindings($record, $findings);
        $this->detectValidationEvidenceFindings($record, $findings);
        $this->detectInfraAuditFindings($record, $findings);

        $promotion = $this->inspectPromotionNamespace($record, $findings);
        if ($promotion !== null) {
            $this->detectPromotionAndSubmissionFindings($record, $promotion, $findings);
        }

        return [
            'record_id' => (string) $record->record_id,
            'annotation_record_id' => (int) $record->id,
            'status' => $this->hasDeferral($findings) ? 'deferred' : 'ok',
            'findings' => $findings,
        ];
    }

    private function resolveRecord(int|string $record): ?AnnotationRecord
    {
        if (is_int($record) || ctype_digit((string) $record)) {
            $model = AnnotationRecord::query()->whereKey((int) $record)->first();
            if ($model !== null) {
                return $model;
            }
        }

        return AnnotationRecord::query()->where('record_id', (string) $record)->first();
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function detectDraftFindings(AnnotationRecord $record, array &$findings): void
    {
        $recordId = (string) $record->record_id;
        $currentDraftPath = $this->nullableString($record->current_draft_path);
        $currentDraftSha = $this->nullableString($record->current_draft_sha256);
        $currentDraftVersionId = $this->nullableString($record->current_draft_version_id);

        $draftDirRelative = $this->draftVersionsRelativePath($recordId);
        $draftFiles = $this->listJsonFiles($draftDirRelative);

        if ($currentDraftPath !== null) {
            $currentExists = $this->store->artifactExists($currentDraftPath);
            $currentReadable = $currentExists ? $this->isReadableFile($currentDraftPath) : false;
            $currentHash = $currentReadable ? $this->safeHash($currentDraftPath) : null;

            if (! $currentExists || ! $currentReadable) {
                $findings[] = $this->finding(
                    'A2_MISSING_CURRENT_DRAFT_FILE',
                    $record,
                    'integrity_incident',
                    'manual_investigation',
                    'Current draft DB pointer references a missing or unreadable file.',
                    [
                        'expected' => [
                            'current_draft_path' => $currentDraftPath,
                            'current_draft_sha256' => $currentDraftSha,
                            'current_draft_version_id' => $currentDraftVersionId,
                        ],
                        'observed' => [
                            'exists' => $currentExists,
                            'readable' => $currentReadable,
                        ],
                    ]
                );
            } elseif ($currentDraftSha !== null && $currentHash !== null && ! hash_equals($currentDraftSha, $currentHash)) {
                $findings[] = $this->finding(
                    'A10_GENERIC_HASH_INCONSISTENCY',
                    $record,
                    'integrity_incident',
                    'manual_investigation',
                    'Current draft file hash does not match DB current_draft_sha256.',
                    [
                        'artifact_path' => $currentDraftPath,
                        'expected_sha256' => $currentDraftSha,
                        'observed_sha256' => $currentHash,
                    ]
                );
            }
        }

        $protectedDraftPaths = $this->protectedDraftPaths($record);
        foreach ($draftFiles as $file) {
            $relativePath = (string) $file['relative_path'];
            $isCurrent = $currentDraftPath !== null && $this->sameRelativePath($relativePath, $currentDraftPath);
            $isProtected = $isCurrent || in_array($relativePath, $protectedDraftPaths, true);

            if ($isProtected) {
                continue;
            }

            $findings[] = $this->finding(
                'A1_ORPHAN_DRAFT_VERSION',
                $record,
                'report_only_cleanup_candidate',
                'manual_review_or_explicit_rd06_cleanup',
                'Draft version file is not the current draft pointer. This is report-only in E1; RD-06 cleanup eligibility is NOT proven.',
                [
                    'artifact_path' => $relativePath,
                    'observed_sha256' => $file['sha256'] ?? null,
                    'bytes' => $file['bytes'] ?? null,
                    'current_draft_path' => $currentDraftPath,
                    'active_submission_intent' => $this->hasActiveSubmissionIntent($record),
                    'rd06_cleanup_eligible_in_e1' => false,
                    'eligibility_checks' => [
                        'current_draft_pointer_checked' => true,
                        'active_submission_intent_reference_checked' => false,
                        'active_recovery_reference_checked' => false,
                        'evidence_retention_reference_checked' => false,
                        'grace_period_checked' => false,
                    ],
                ]
            );
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function detectValidationEvidenceFindings(AnnotationRecord $record, array &$findings): void
    {
        $runs = AnnotationValidationRun::query()
            ->where('annotation_record_id', $record->id)
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $evidenceState = $this->nullableString($run->input_evidence_state);
            $durablePath = $this->nullableString($run->durable_input_evidence_path);
            $durableHash = $this->nullableString($run->durable_input_evidence_sha256);
            $inputPath = $this->nullableString($run->input_artifact_path);
            $inputHash = $this->nullableString($run->input_artifact_sha256);

            $base = [
                'run_id' => (int) $run->id,
                'run_uuid' => $this->nullableString($run->run_id),
                'submission_intent_id' => $this->nullableString($run->submission_intent_id),
                'input_artifact_path' => $inputPath,
                'input_artifact_sha256' => $inputHash,
                'input_evidence_state' => $evidenceState,
                'durable_input_evidence_path' => $durablePath,
                'durable_input_evidence_sha256' => $durableHash,
            ];

            if ($evidenceState === 'missing_evidence') {
                if ($durablePath !== null || $durableHash !== null) {
                    $findings[] = $this->finding(
                        'A10_GENERIC_HASH_INCONSISTENCY',
                        $record,
                        'integrity_incident',
                        'manual_investigation',
                        'missing_evidence run must not claim durable evidence path/hash.',
                        $base
                    );
                }

                continue;
            }

            if ($durablePath === null) {
                if (in_array($evidenceState, ['staged_path', 'retained_staging_copy', 'final_artifact'], true)) {
                    $findings[] = $this->finding(
                        'A9_EVIDENCE_PATH_NONEXISTENT',
                        $record,
                        'missing_evidence',
                        'potentially_recoverable_by_reconciliation',
                        'Validation run claims available evidence state but durable_input_evidence_path is null.',
                        $base
                    );
                }

                continue;
            }

            $exists = $this->store->artifactExists($durablePath);
            $readable = $exists ? $this->isReadableFile($durablePath) : false;
            $observedHash = $readable ? $this->safeHash($durablePath) : null;

            if (! $exists || ! $readable) {
                $findings[] = $this->finding(
                    'A7_MISSING_DURABLE_EVIDENCE_FILE',
                    $record,
                    'missing_evidence',
                    'potentially_recoverable_by_reconciliation',
                    'Durable evidence path is missing or unreadable while run does not claim missing_evidence.',
                    $base + ['observed' => ['exists' => $exists, 'readable' => $readable]]
                );

                continue;
            }

            if ($inputHash !== null && $observedHash !== null && ! hash_equals($inputHash, $observedHash)) {
                $findings[] = $this->finding(
                    'A8_DURABLE_EVIDENCE_HASH_MISMATCH',
                    $record,
                    'integrity_incident',
                    'manual_investigation',
                    'Durable evidence file hash does not match validation run input_artifact_sha256.',
                    $base + ['observed_sha256' => $observedHash]
                );
            }

            if ($durableHash !== null && $observedHash !== null && ! hash_equals($durableHash, $observedHash)) {
                $findings[] = $this->finding(
                    'A10_GENERIC_HASH_INCONSISTENCY',
                    $record,
                    'integrity_incident',
                    'manual_investigation',
                    'Durable evidence file hash does not match validation run durable_input_evidence_sha256.',
                    $base + ['observed_sha256' => $observedHash]
                );
            }

            if ($evidenceState === 'final_artifact') {
                $submittedPath = $this->nullableString($record->submitted_json_path);
                $submittedSha = $this->nullableString($record->submitted_artifact_sha256);
                if ($submittedPath !== null && ! $this->sameRelativePath($durablePath, $submittedPath)) {
                    $findings[] = $this->finding(
                        'A10_GENERIC_HASH_INCONSISTENCY',
                        $record,
                        'integrity_incident',
                        'manual_investigation',
                        'Final-artifact evidence path does not match record submitted_json_path.',
                        $base + ['submitted_json_path' => $submittedPath]
                    );
                }
                if ($submittedSha !== null && $durableHash !== null && ! hash_equals($submittedSha, $durableHash)) {
                    $findings[] = $this->finding(
                        'A10_GENERIC_HASH_INCONSISTENCY',
                        $record,
                        'integrity_incident',
                        'manual_investigation',
                        'Final-artifact durable evidence hash does not match record submitted_artifact_sha256.',
                        $base + ['submitted_artifact_sha256' => $submittedSha]
                    );
                }
            }
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function detectInfraAuditFindings(AnnotationRecord $record, array &$findings): void
    {
        $infraRuns = AnnotationValidationRun::query()
            ->where('annotation_record_id', $record->id)
            ->where('status', 'infra_failed')
            ->orderBy('id')
            ->get();

        if ($infraRuns->isEmpty()) {
            return;
        }

        $actions = AnnotationReviewAction::query()
            ->where('annotation_record_id', $record->id)
            ->whereIn('action', ['submission_infra_failed', 'validation_infra_failed', 'reconciliation_recorded'])
            ->get();

        foreach ($infraRuns as $run) {
            $runUuid = $this->nullableString($run->run_id);
            $intentId = $this->nullableString($run->submission_intent_id);
            $correlation = $this->evaluateInfraAuditCorrelation($actions, $runUuid, $intentId);

            if ($correlation['has_positive_match']) {
                continue;
            }

            $hasReconciliationRecord = $actions->contains(function (AnnotationReviewAction $action) use ($runUuid, $intentId): bool {
                if ($action->action !== 'reconciliation_recorded') {
                    return false;
                }
                $meta = is_array($action->metadata_json) ? $action->metadata_json : [];

                return ($meta['anomaly_type'] ?? null) === 'A11_INFRA_FAILED_RUN_MISSING_EXPECTED_AUDIT'
                    && $this->nullableString($meta['run_id'] ?? null) === $runUuid
                    && $this->nullableString($meta['submission_intent_id'] ?? null) === $intentId;
            });

            $classification = $correlation['has_ambiguous_match'] ? 'audit_inconsistency_ambiguous' : 'audit_inconsistency';
            $recoverability = 'manual_investigation';
            $explanation = $correlation['has_ambiguous_match']
                ? 'Infra-failed validation run has ambiguous infra-failure audit correlation; missing metadata is not treated as a match.'
                : 'Infra-failed validation run has no positively correlated expected infra-failure audit action.';

            $findings[] = $this->finding(
                'A11_INFRA_FAILED_RUN_MISSING_EXPECTED_AUDIT',
                $record,
                $classification,
                $recoverability,
                $explanation,
                [
                    'run_id' => (int) $run->id,
                    'run_uuid' => $runUuid,
                    'submission_intent_id' => $intentId,
                    'expected_actions' => ['submission_infra_failed', 'validation_infra_failed'],
                    'reconciliation_record_already_present' => $hasReconciliationRecord,
                    'correlation' => $correlation,
                ]
            );
        }
    }

    /**
     * Inspect promotion namespace while holding the promotion lock. All promotion/staging/final
     * filesystem evidence used by A3-A6/A10 promotion findings must come from this locked snapshot.
     *
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<string,mixed>|null
     */
    private function inspectPromotionNamespace(AnnotationRecord $record, array &$findings): ?array
    {
        try {
            return $this->store->withRecordPromotionLock((string) $record->record_id, function () use ($record): array {
                $inspection = $this->store->inspectRecordArtifacts((string) $record->record_id);

                $dbStagingPath = $this->nullableString($record->submission_staging_path);
                $dbFinalPath = $this->nullableString($record->submitted_json_path);
                $submittedRoot = trim((string) config('annotation.annotation_submitted_path', 'submitted'), '/\\');
                $defaultFinalPath = $submittedRoot.'/'.((preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $record->record_id) ?: (string) $record->record_id)).'/record.json';

                $inspection['db_staging_snapshot'] = $this->snapshotArtifact($dbStagingPath);
                $inspection['db_final_snapshot'] = $this->snapshotArtifact($dbFinalPath);
                $inspection['default_final_snapshot'] = $this->snapshotArtifact($defaultFinalPath);
                $inspection['default_final_path'] = $defaultFinalPath;
                $inspection['lock_held_during_inspection'] = true;

                return $inspection;
            });
        } catch (ArtifactLockException $e) {
            $findings[] = $this->finding(
                'A12_LOCK_TIMEOUT_DEFERRED',
                $record,
                'retryable_deferral',
                'retry_later',
                'Promotion lock could not be acquired for report-only inspection; no files or lifecycle metadata were changed.',
                [
                    'lock_error' => $e->getMessage(),
                ]
            );

            return null;
        }
    }

    /**
     * @return array{relative_path:?string,exists:bool,is_file:bool,readable:bool,sha256:?string,bytes:?int}
     */
    private function snapshotArtifact(?string $relativePath): array
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return [
                'relative_path' => null,
                'exists' => false,
                'is_file' => false,
                'readable' => false,
                'sha256' => null,
                'bytes' => null,
            ];
        }

        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        try {
            $absolute = $this->store->resolveRelativePath($relativePath, false);
        } catch (\Throwable) {
            return [
                'relative_path' => $relativePath,
                'exists' => false,
                'is_file' => false,
                'readable' => false,
                'sha256' => null,
                'bytes' => null,
            ];
        }

        $exists = file_exists($absolute) || is_link($absolute);
        $isFile = is_file($absolute) && ! is_link($absolute);
        $readable = $isFile && is_readable($absolute);

        return [
            'relative_path' => $relativePath,
            'exists' => $exists,
            'is_file' => $isFile,
            'readable' => $readable,
            'sha256' => $readable ? (@hash_file('sha256', $absolute) ?: null) : null,
            'bytes' => $isFile ? (int) filesize($absolute) : null,
        ];
    }

    /**
     * Promotion findings use the locked snapshot only; no promotion/staging/final FS reads after lock release.
     *
     * @param  array<string,mixed>  $promotion
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function detectPromotionAndSubmissionFindings(AnnotationRecord $record, array $promotion, array &$findings): void
    {
        $recordId = (string) $record->record_id;
        $state = $this->nullableString($record->submission_state);
        $intentId = $this->nullableString($record->submission_intent_id);
        $dbStagingPath = $this->nullableString($record->submission_staging_path);
        $dbStagingSha = $this->nullableString($record->submission_staging_sha256);
        $dbFinalPath = $this->nullableString($record->submitted_json_path);
        $dbFinalSha = $this->nullableString($record->submitted_artifact_sha256);
        $isFinalized = $state === 'finalized' || $dbFinalPath !== null || $dbFinalSha !== null || $record->submission_finalized_at !== null;

        $stagingFiles = is_array($promotion['staging_artifacts'] ?? null) ? $promotion['staging_artifacts'] : [];
        $promotionTemps = is_array($promotion['promotion_temps'] ?? null) ? $promotion['promotion_temps'] : [];
        $finalArtifact = is_array($promotion['final_artifact'] ?? null) ? $promotion['final_artifact'] : null;
        $dbStagingSnapshot = is_array($promotion['db_staging_snapshot'] ?? null) ? $promotion['db_staging_snapshot'] : $this->snapshotArtifact($dbStagingPath);
        $dbFinalSnapshot = is_array($promotion['db_final_snapshot'] ?? null) ? $promotion['db_final_snapshot'] : $this->snapshotArtifact($dbFinalPath);

        $finalPath = $this->nullableString($finalArtifact['relative_path'] ?? null) ?? $this->finalRelativePath($recordId);
        $finalExists = (bool) ($finalArtifact['exists'] ?? false);
        $finalHash = $this->nullableString($finalArtifact['sha256'] ?? null);

        if ($dbStagingPath !== null) {
            $stagingExists = (bool) ($dbStagingSnapshot['exists'] ?? false);
            $stagingReadable = (bool) ($dbStagingSnapshot['readable'] ?? false);
            $stagingHash = $this->nullableString($dbStagingSnapshot['sha256'] ?? null);
            if (! $stagingExists || ! $stagingReadable) {
                if ($this->hasActiveSubmissionIntent($record) && ! $isFinalized) {
                    $findings[] = $this->finding(
                        'A3_STAGING_INTENT_WITHOUT_FINALIZED_ARTIFACT',
                        $record,
                        'integrity_incident',
                        'manual_investigation',
                        'Active submission intent references a missing or unreadable staging artifact and record is not finalized.',
                        [
                            'submission_intent_id' => $intentId,
                            'submission_state' => $state,
                            'submission_staging_path' => $dbStagingPath,
                            'submission_staging_sha256' => $dbStagingSha,
                            'observed' => $dbStagingSnapshot,
                        ]
                    );
                }
            } elseif ($dbStagingSha !== null && $stagingHash !== null && ! hash_equals($dbStagingSha, $stagingHash)) {
                $findings[] = $this->finding(
                    'A10_GENERIC_HASH_INCONSISTENCY',
                    $record,
                    'integrity_incident',
                    'manual_investigation',
                    'Staging artifact hash does not match record submission_staging_sha256.',
                    [
                        'submission_staging_path' => $dbStagingPath,
                        'expected_sha256' => $dbStagingSha,
                        'observed_sha256' => $stagingHash,
                        'observed' => $dbStagingSnapshot,
                    ]
                );
            }
        }

        foreach ($stagingFiles as $staging) {
            $path = $this->nullableString($staging['relative_path'] ?? null);
            if ($path === null) {
                continue;
            }
            $isReferenced = $dbStagingPath !== null && $this->sameRelativePath($path, $dbStagingPath);
            if (! $isFinalized) {
                if (! $isReferenced) {
                    $findings[] = $this->finding(
                        'A3_STAGING_INTENT_WITHOUT_FINALIZED_ARTIFACT',
                        $record,
                        'report_only_orphan_staging',
                        'manual_investigation',
                        'Staging artifact exists without a finalized record; report-only in I6-E1 and must not be auto-deleted.',
                        [
                            'submission_intent_id' => $intentId,
                            'submission_state' => $state,
                            'artifact_path' => $path,
                            'observed_sha256' => $this->nullableString($staging['sha256'] ?? null),
                            'active_submission_intent' => $this->hasActiveSubmissionIntent($record),
                            'auto_delete_in_i6e' => false,
                        ]
                    );
                } elseif ($this->hasActiveSubmissionIntent($record)) {
                    $findings[] = $this->finding(
                        'A3_STAGING_INTENT_WITHOUT_FINALIZED_ARTIFACT',
                        $record,
                        'active_intent_not_finalized',
                        'manual_investigation_or_potential_recovery',
                        'Active submission intent has staging artifact but record is not finalized; report-only in I6-E1.',
                        [
                            'submission_intent_id' => $intentId,
                            'submission_state' => $state,
                            'artifact_path' => $path,
                            'observed_sha256' => $this->nullableString($staging['sha256'] ?? null),
                            'final_artifact_exists' => $finalExists,
                            'auto_delete_in_i6e' => false,
                        ]
                    );
                }
            }
        }

        if ($finalExists && $finalHash !== null) {
            if ($dbFinalSha !== null && ! hash_equals($dbFinalSha, $finalHash)) {
                $findings[] = $this->finding(
                    'A10_GENERIC_HASH_INCONSISTENCY',
                    $record,
                    'integrity_incident',
                    'manual_investigation',
                    'Final artifact hash does not match record submitted_artifact_sha256.',
                    [
                        'artifact_path' => $finalPath,
                        'expected_sha256' => $dbFinalSha,
                        'observed_sha256' => $finalHash,
                    ]
                );
            }

            if (! $isFinalized) {
                $assessment = $this->assessA5Recoverability($record, $intentId, $dbStagingPath, $dbStagingSha, $dbStagingSnapshot, $finalHash);
                $findings[] = $this->finding(
                    'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA',
                    $record,
                    $assessment['classification'],
                    $assessment['recoverability'],
                    $assessment['explanation'],
                    [
                        'artifact_path' => $finalPath,
                        'observed_sha256' => $finalHash,
                        'submission_state' => $state,
                        'submission_intent_id' => $intentId,
                        'expected_staging_sha256' => $dbStagingSha,
                        'db_staging_snapshot' => $dbStagingSnapshot,
                        'matching_run_id' => $assessment['matching_run']?->id,
                        'matching_run_uuid' => $assessment['matching_run'] ? $this->nullableString($assessment['matching_run']->run_id) : null,
                        'recoverability_basis' => $assessment['basis'],
                    ]
                );
            }
        }

        if ($isFinalized && $dbFinalPath !== null) {
            $dbFinalExists = (bool) ($dbFinalSnapshot['exists'] ?? false);
            $dbFinalReadable = (bool) ($dbFinalSnapshot['readable'] ?? false);
            $dbFinalObservedHash = $this->nullableString($dbFinalSnapshot['sha256'] ?? null);
            if (! $dbFinalExists || ! $dbFinalReadable) {
                $findings[] = $this->finding(
                    'A7_MISSING_DURABLE_EVIDENCE_FILE',
                    $record,
                    'missing_evidence',
                    'manual_investigation',
                    'Finalized record references submitted_json_path that is missing or unreadable.',
                    [
                        'artifact_path' => $dbFinalPath,
                        'expected_sha256' => $dbFinalSha,
                        'submission_state' => $state,
                        'observed' => $dbFinalSnapshot,
                    ]
                );
            } elseif ($dbFinalSha !== null && $dbFinalObservedHash !== null && ! hash_equals($dbFinalSha, $dbFinalObservedHash)) {
                $findings[] = $this->finding(
                    'A10_GENERIC_HASH_INCONSISTENCY',
                    $record,
                    'integrity_incident',
                    'manual_investigation',
                    'Finalized submitted artifact hash does not match record submitted_artifact_sha256.',
                    [
                        'artifact_path' => $dbFinalPath,
                        'expected_sha256' => $dbFinalSha,
                        'observed_sha256' => $dbFinalObservedHash,
                        'observed' => $dbFinalSnapshot,
                    ]
                );
            }
        }

        $this->detectPromotionTempFindings($record, $promotionTemps, $dbStagingPath, $dbStagingSha, $dbStagingSnapshot, $finalHash, $findings);
        $this->detectStaleFinalizedEvidenceFindings($record, $finalHash, $dbFinalSha, $findings);
    }

    /**
     * @param  array<int,array<string,mixed>>  $promotionTemps
     * @param  array<string,mixed>  $dbStagingSnapshot
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function detectPromotionTempFindings(AnnotationRecord $record, array $promotionTemps, ?string $stagingPath, ?string $stagingSha, array $dbStagingSnapshot, ?string $finalHash, array &$findings): void
    {
        if ($promotionTemps === []) {
            return;
        }

        $stagingObservedSha = $this->nullableString($dbStagingSnapshot['sha256'] ?? null);
        $stagingBytes = isset($dbStagingSnapshot['bytes']) ? (int) $dbStagingSnapshot['bytes'] : null;
        $stagingReadable = (bool) ($dbStagingSnapshot['readable'] ?? false);
        $run = $this->correlatedPromotionEligibleValidationRun($record, $stagingPath, $stagingSha);
        $runHash = $run ? $this->nullableString($run->input_artifact_sha256) : null;
        $runCorrelated = $run !== null;

        foreach ($promotionTemps as $tmp) {
            $tmpPath = $this->nullableString($tmp['relative_path'] ?? null);
            $tmpSha = $this->nullableString($tmp['sha256'] ?? null);
            $tmpBytes = isset($tmp['bytes']) ? (int) $tmp['bytes'] : null;
            if ($tmpPath === null) {
                continue;
            }

            $stagingVerified = $stagingReadable && $stagingObservedSha !== null && $stagingSha !== null && hash_equals($stagingObservedSha, $stagingSha);
            $tmpMatchesStaging = $tmpSha !== null && $stagingSha !== null && hash_equals($tmpSha, $stagingSha);
            $tmpMatchesRun = $tmpSha !== null && $runHash !== null && hash_equals($tmpSha, $runHash);

            $classification = 'integrity_incident';
            $recoverability = 'manual_investigation';
            $explanation = 'Promotion temp could not be classified as safely recoverable from available evidence.';

            if ($stagingVerified && $tmpMatchesStaging && $runCorrelated && $tmpMatchesRun) {
                $classification = 'recoverable_candidate';
                $recoverability = 'potentially_recoverable_by_reconciliation';
                $explanation = 'Promotion temp hash matches verified staging and a positively correlated completed validation run for the same record/intent; candidate is report-only in E1.';
            } elseif ($stagingVerified && $tmpMatchesStaging && ! $runCorrelated) {
                $classification = 'report_only_unproven_candidate';
                $recoverability = 'manual_investigation';
                $explanation = 'Promotion temp hash matches verified staging, but no positively correlated completed validation run for the same record/intent was proven; recoverability is not claimed.';
            } elseif ($stagingBytes !== null && $tmpBytes !== null && $tmpBytes < $stagingBytes && $stagingVerified) {
                $classification = 'incomplete_candidate';
                $recoverability = 'potentially_recoverable_by_reconciliation';
                $explanation = 'Promotion temp is shorter than verified staging source; it may be an incomplete candidate if lock ownership rules allow cleanup in a later stage.';
            } elseif ($tmpSha !== null && $stagingSha !== null && ! $tmpMatchesStaging) {
                $classification = 'integrity_incident';
                $recoverability = 'manual_investigation';
                $explanation = 'Promotion temp hash does not match expected staging hash; preserve as integrity incident.';
            } elseif ($tmpSha !== null && $runCorrelated && $runHash !== null && ! $tmpMatchesRun) {
                $classification = 'integrity_incident';
                $recoverability = 'manual_investigation';
                $explanation = 'Promotion temp hash does not match positively correlated validation-run hash; preserve as integrity incident.';
            }

            $findings[] = $this->finding(
                'A4_PROMOTION_TEMP_PRESENT',
                $record,
                $classification,
                $recoverability,
                $explanation,
                [
                    'artifact_path' => $tmpPath,
                    'observed_sha256' => $tmpSha,
                    'observed_bytes' => $tmpBytes,
                    'staging_path' => $stagingPath,
                    'staging_sha256' => $stagingSha,
                    'staging_observed_sha256' => $stagingObservedSha,
                    'staging_bytes' => $stagingBytes,
                    'staging_verified' => $stagingVerified,
                    'validation_run_id' => $run?->id,
                    'validation_run_uuid' => $run ? $this->nullableString($run->run_id) : null,
                    'validation_run_input_sha256' => $runHash,
                    'validation_run_hash_correlated' => $runCorrelated,
                    'final_observed_sha256' => $finalHash,
                    'auto_delete_in_i6e' => false,
                ]
            );
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function detectStaleFinalizedEvidenceFindings(AnnotationRecord $record, ?string $finalHash, ?string $dbFinalSha, array &$findings): void
    {
        if ($this->nullableString($record->submission_state) !== 'finalized') {
            return;
        }
        $submittedSha = $this->nullableString($record->submitted_artifact_sha256);
        $submittedPath = $this->nullableString($record->submitted_json_path);
        if ($submittedSha === null || $submittedPath === null) {
            return;
        }
        if ($dbFinalSha !== null && ! hash_equals($dbFinalSha, $submittedSha)) {
            return;
        }
        if ($finalHash !== null && ! hash_equals($finalHash, $submittedSha)) {
            return;
        }

        $runs = AnnotationValidationRun::query()
            ->where('annotation_record_id', $record->id)
            ->where('submission_intent_id', $record->submission_intent_id)
            ->whereIn('input_evidence_state', ['staged_path', 'retained_staging_copy'])
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $inputHash = $this->nullableString($run->input_artifact_sha256);
            if ($inputHash === null || ! hash_equals($inputHash, $submittedSha)) {
                continue;
            }
            $findings[] = $this->finding(
                'A6_FINALIZED_RECORD_STALE_EVIDENCE',
                $record,
                'recoverable_integrity_state',
                'potentially_recoverable_by_reconciliation',
                'Record is finalized and final hash matches, but validation run evidence still points to staged/retained evidence.',
                [
                    'run_id' => (int) $run->id,
                    'run_uuid' => $this->nullableString($run->run_id),
                    'submission_intent_id' => $this->nullableString($run->submission_intent_id),
                    'input_evidence_state' => $this->nullableString($run->input_evidence_state),
                    'durable_input_evidence_path' => $this->nullableString($run->durable_input_evidence_path),
                    'submitted_json_path' => $submittedPath,
                    'submitted_artifact_sha256' => $submittedSha,
                ]
            );
        }
    }

    /**
     * @param  array<string,mixed>  $dbStagingSnapshot
     * @return array{classification:string,recoverability:string,explanation:string,matching_run:?AnnotationValidationRun,basis:array<string,mixed>}
     */
    private function assessA5Recoverability(AnnotationRecord $record, ?string $intentId, ?string $dbStagingPath, ?string $dbStagingSha, array $dbStagingSnapshot, string $finalHash): array
    {
        $stagingReadable = (bool) ($dbStagingSnapshot['readable'] ?? false);
        $stagingObservedHash = $this->nullableString($dbStagingSnapshot['sha256'] ?? null);
        $stagingPath = $this->nullableString($dbStagingPath);
        $basis = [
            'final_hash_verified' => $finalHash !== '',
            'expected_staging_hash_present' => $dbStagingSha !== null,
            'expected_staging_path_present' => $stagingPath !== null,
            'staging_snapshot_readable' => $stagingReadable,
            'staging_snapshot_hash' => $stagingObservedHash,
            'matching_completed_run_found' => false,
            'same_record_and_intent' => false,
            'run_input_hash_matches_expected_hashes' => false,
            'run_input_path_matches_expected_staging_path' => false,
            'run_is_valid_boolean' => false,
        ];

        if ($dbStagingSha === null || $intentId === null || $stagingPath === null) {
            return [
                'classification' => 'integrity_incident',
                'recoverability' => 'manual_investigation',
                'explanation' => 'Final artifact exists but required staging/intent evidence is missing; A5 is not recoverable without full proof.',
                'matching_run' => null,
                'basis' => $basis,
            ];
        }

        if (! hash_equals($dbStagingSha, $finalHash)) {
            $basis['staging_hash_matches_final_hash'] = false;

            return [
                'classification' => 'integrity_incident',
                'recoverability' => 'manual_investigation',
                'explanation' => 'Final artifact exists but expected staging hash does not match final hash; manual investigation required.',
                'matching_run' => null,
                'basis' => $basis,
            ];
        }
        $basis['staging_hash_matches_final_hash'] = true;

        $matchingRun = AnnotationValidationRun::query()
            ->where('annotation_record_id', $record->id)
            ->where('submission_intent_id', $intentId)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->get()
            ->first(function (AnnotationValidationRun $run) use ($record, $intentId, $stagingPath, $dbStagingSha, $finalHash): bool {
                $runIntent = $this->nullableString($run->submission_intent_id);
                $runHash = $this->nullableString($run->input_artifact_sha256);
                $runPath = is_string($run->input_artifact_path) ? trim($run->input_artifact_path) : '';

                return (int) $run->annotation_record_id === (int) $record->id
                    && $runIntent !== null
                    && hash_equals($runIntent, $intentId)
                    && is_bool($run->is_valid)
                    && $runPath !== ''
                    && hash_equals($runPath, $stagingPath)
                    && $runHash !== null
                    && hash_equals($runHash, $dbStagingSha)
                    && hash_equals($runHash, $finalHash);
            });

        $basis['matching_completed_run_found'] = $matchingRun !== null;
        $basis['same_record_and_intent'] = $matchingRun !== null;
        $basis['run_input_hash_matches_expected_hashes'] = $matchingRun !== null;
        $basis['run_input_path_matches_expected_staging_path'] = $matchingRun !== null;
        $basis['run_is_valid_boolean'] = $matchingRun !== null;

        if ($matchingRun === null) {
            return [
                'classification' => 'integrity_incident',
                'recoverability' => 'manual_investigation',
                'explanation' => 'Final artifact exists, but no positively correlated completed validation run with matching intent, staging path, and hashes was found; do not claim recoverability.',
                'matching_run' => null,
                'basis' => $basis,
            ];
        }

        return [
            'classification' => 'recoverable_integrity_state',
            'recoverability' => 'potentially_recoverable_by_reconciliation',
            'explanation' => 'Final artifact hash is verified, expected staging hash matches, and a positively correlated completed validation run for the same record/intent matches staging path and expected hashes.',
            'matching_run' => $matchingRun,
            'basis' => $basis,
        ];
    }

    private function correlatedPromotionEligibleValidationRun(AnnotationRecord $record, ?string $stagingPath, ?string $stagingSha): ?AnnotationValidationRun
    {
        $intentId = $this->nullableString($record->submission_intent_id);
        $stagingPath = $this->nullableString($stagingPath);
        if ($intentId === null || $stagingPath === null || $stagingSha === null) {
            return null;
        }

        return AnnotationValidationRun::query()
            ->where('annotation_record_id', $record->id)
            ->where('submission_intent_id', $intentId)
            ->where('status', 'completed')
            ->where('input_artifact_sha256', $stagingSha)
            ->orderByDesc('id')
            ->get()
            ->first(function (AnnotationValidationRun $run) use ($stagingPath): bool {
                $runPath = is_string($run->input_artifact_path) ? trim($run->input_artifact_path) : '';

                return is_bool($run->is_valid)
                    && $runPath !== ''
                    && hash_equals($runPath, $stagingPath);
            });
    }

    /**
     * @return array{has_positive_match:bool,has_ambiguous_match:bool,positive_matches:array<int,array<string,mixed>>,ambiguous_matches:array<int,array<string,mixed>>}
     */
    private function evaluateInfraAuditCorrelation($actions, ?string $runUuid, ?string $intentId): array
    {
        $positive = [];
        $ambiguous = [];

        foreach ($actions as $action) {
            if (! $action instanceof AnnotationReviewAction) {
                continue;
            }
            if (! in_array($action->action, ['submission_infra_failed', 'validation_infra_failed'], true)) {
                continue;
            }

            $meta = is_array($action->metadata_json) ? $action->metadata_json : [];
            $metaRun = $this->nullableString($meta['run_id'] ?? $meta['validation_run_id'] ?? null);
            $metaIntent = $this->nullableString($meta['submission_intent_id'] ?? null);

            $runKnown = $runUuid !== null;
            $intentKnown = $intentId !== null;
            $runMatch = $runKnown && $metaRun !== null && hash_equals($runUuid, $metaRun);
            $intentMatch = $intentKnown && $metaIntent !== null && hash_equals($intentId, $metaIntent);
            $runContradiction = $runKnown && $metaRun !== null && ! hash_equals($runUuid, $metaRun);
            $intentContradiction = $intentKnown && $metaIntent !== null && ! hash_equals($intentId, $metaIntent);

            if ($runContradiction || $intentContradiction) {
                continue;
            }

            $info = [
                'action_id' => (int) $action->id,
                'action' => (string) $action->action,
                'meta_run' => $metaRun,
                'meta_intent' => $metaIntent,
            ];

            $runPositive = $runMatch;
            $intentPositive = $intentMatch;
            $runMissing = $runKnown && $metaRun === null;
            $intentMissing = $intentKnown && $metaIntent === null;

            $hasPositiveCorrelation = ($runKnown && $runPositive) || (! $runKnown && $intentKnown && $intentPositive);
            $isFullyPositive = ($runKnown ? $runPositive : true) && ($intentKnown ? $intentPositive : true) && $hasPositiveCorrelation;

            if ($isFullyPositive) {
                $positive[] = $info;

                continue;
            }

            if ($hasPositiveCorrelation && ($runMissing || $intentMissing)) {
                $ambiguous[] = $info + ['reason' => 'partial_metadata_missing'];

                continue;
            }

            if ($runMissing && $intentMissing) {
                $ambiguous[] = $info + ['reason' => 'metadata_missing'];
            }
        }

        return [
            'has_positive_match' => $positive !== [],
            'has_ambiguous_match' => $ambiguous !== [],
            'positive_matches' => $positive,
            'ambiguous_matches' => $ambiguous,
        ];
    }

    /**
     * @return list<string>
     */
    private function protectedDraftPaths(AnnotationRecord $record): array
    {
        $paths = [];
        $current = $this->nullableString($record->current_draft_path);
        if ($current !== null) {
            $paths[] = $current;
        }

        return array_values(array_unique($paths));
    }

    private function hasActiveSubmissionIntent(AnnotationRecord $record): bool
    {
        return in_array($this->nullableString($record->submission_state), ['staging_written', 'b1_completed', 'promotion_pending', 'b1_infra_failed'], true)
            && $this->nullableString($record->submission_intent_id) !== null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function hasDeferral(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (($finding['classification'] ?? null) === 'retryable_deferral') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function finding(string $anomalyType, AnnotationRecord $record, string $classification, string $recoverability, string $explanation, array $evidence = []): array
    {
        return [
            'anomaly_type' => $anomalyType,
            'record_id' => (string) $record->record_id,
            'annotation_record_id' => (int) $record->id,
            'submission_intent_id' => $this->nullableString($record->submission_intent_id),
            'classification' => $classification,
            'recoverability' => $recoverability,
            'explanation' => $explanation,
            'evidence' => $evidence,
        ];
    }

    private function finalRelativePath(string $recordId): string
    {
        $submittedRoot = trim((string) config('annotation.annotation_submitted_path', 'submitted'), '/\\');
        $recordId = preg_replace('/[^A-Za-z0-9_-]/', '_', $recordId) ?: $recordId;

        return $submittedRoot.'/'.$recordId.'/record.json';
    }

    private function draftVersionsRelativePath(string $recordId): string
    {
        $draftRoot = trim((string) config('annotation.annotation_draft_versions_path', 'drafts'), '/\\');
        $recordId = preg_replace('/[^A-Za-z0-9_-]/', '_', $recordId) ?: $recordId;

        return $draftRoot.'/'.$recordId.'/versions';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listJsonFiles(string $relativeDirectory): array
    {
        try {
            $dirAbsolute = $this->store->resolveRelativePath($relativeDirectory, false);
        } catch (\Throwable) {
            return [];
        }

        if (! is_dir($dirAbsolute)) {
            return [];
        }

        $items = [];
        foreach (scandir($dirAbsolute) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! str_ends_with($entry, '.json')) {
                continue;
            }
            $relative = trim($relativeDirectory, '/\\').'/'.$entry;
            $absolute = $dirAbsolute.DIRECTORY_SEPARATOR.$entry;
            $isFile = is_file($absolute) && ! is_link($absolute);
            $items[] = [
                'relative_path' => $relative,
                'exists' => file_exists($absolute),
                'is_file' => $isFile,
                'bytes' => $isFile ? filesize($absolute) : null,
                'sha256' => $isFile ? @hash_file('sha256', $absolute) ?: null : null,
            ];
        }
        usort($items, fn (array $a, array $b) => strcmp((string) $a['relative_path'], (string) $b['relative_path']));

        return $items;
    }

    private function isReadableFile(string $relativePath): bool
    {
        try {
            $absolute = $this->store->resolveRelativePath($relativePath, false);
        } catch (\Throwable) {
            return false;
        }

        return is_file($absolute) && ! is_link($absolute) && is_readable($absolute);
    }

    private function safeHash(string $relativePath): ?string
    {
        try {
            return $this->store->readArtifactSha256($relativePath);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeSize(string $relativePath): ?int
    {
        try {
            $absolute = $this->store->resolveRelativePath($relativePath, false);
        } catch (\Throwable) {
            return null;
        }

        return is_file($absolute) ? (int) filesize($absolute) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function sameRelativePath(string $a, string $b): bool
    {
        return hash_equals($this->normalizeRelative($a), $this->normalizeRelative($b));
    }

    private function normalizeRelative(string $path): string
    {
        return strtolower(str_replace('\\', '/', trim($path)));
    }

    /**
     * @param  list<string>  $expectedHashes
     */
    private function hashMatchesAll(string $actual, array $expectedHashes): bool
    {
        foreach ($expectedHashes as $expected) {
            if (! hash_equals(strtolower($expected), strtolower($actual))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     */
    private function countRepairCandidates(array $findings): int
    {
        $count = 0;
        foreach ($findings as $finding) {
            if ($this->isRepairCandidate($finding)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    private function isRepairCandidate(array $finding): bool
    {
        $type = (string) ($finding['anomaly_type'] ?? '');
        $classification = (string) ($finding['classification'] ?? '');
        $recoverability = (string) ($finding['recoverability'] ?? '');

        if ($type === 'A4_PROMOTION_TEMP_PRESENT') {
            return $classification === 'recoverable_candidate' && $recoverability === 'potentially_recoverable_by_reconciliation';
        }
        if ($type === 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA') {
            return $classification === 'recoverable_integrity_state' && $recoverability === 'potentially_recoverable_by_reconciliation';
        }
        if (in_array($type, ['A6_FINALIZED_DB_WITH_STALE_STAGED_EVIDENCE', 'A6_FINALIZED_RECORD_STALE_EVIDENCE'], true)) {
            return $classification === 'recoverable_integrity_state' && $recoverability === 'potentially_recoverable_by_reconciliation';
        }
        if (in_array($type, ['A7_MISSING_DURABLE_EVIDENCE_FILE', 'A8_DURABLE_EVIDENCE_HASH_MISMATCH', 'A9_EVIDENCE_PATH_NONEXISTENT'], true)) {
            return $classification !== 'integrity_incident';
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @return array<int,array<string,mixed>>
     */
    private function applyRepairs(
        AnnotationRecord $record,
        array $findings,
        TrustedReconciliationPrincipal $principal
    ): array {
        $repairs = [];
        foreach ($findings as $finding) {
            if (! $this->isRepairCandidate($finding)) {
                continue;
            }
            $repairs[] = $this->applyRepairForFinding($record, $finding, $principal);
        }

        return $repairs;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function applyRepairForFinding(
        AnnotationRecord $record,
        array $finding,
        TrustedReconciliationPrincipal $principal
    ): array {
        $type = (string) ($finding['anomaly_type'] ?? '');
        $base = [
            'anomaly_type' => $type,
            'record_id' => (string) $record->record_id,
            'submission_intent_id' => $this->nullableString($record->submission_intent_id),
            'status' => 'candidate',
        ];

        try {
            return match ($type) {
                'A4_PROMOTION_TEMP_PRESENT' => $this->repairA4PromotionTemp($record, $finding, $principal) + $base,
                'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA' => $this->repairA5FinalWithoutFinalizedDb($record, $finding, $principal) + $base,
                'A6_FINALIZED_DB_WITH_STALE_STAGED_EVIDENCE', 'A6_FINALIZED_RECORD_STALE_EVIDENCE' => $this->repairA6StaleEvidence($record, $finding, $principal) + $base,
                'A7_MISSING_DURABLE_EVIDENCE_FILE', 'A8_DURABLE_EVIDENCE_HASH_MISMATCH', 'A9_EVIDENCE_PATH_NONEXISTENT' => $this->repairMissingOrInconsistentEvidence($record, $finding, $principal) + $base,
                default => $base + ['status' => 'skipped', 'reason' => 'unsupported_repair_type'],
            };
        } catch (ArtifactLockException $e) {
            return ['status' => 'deferred', 'reason' => 'lock_timeout', 'error' => $e->getMessage()] + $base;
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason' => 'exception', 'error' => $e->getMessage()] + $base;
        }
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function repairA4PromotionTemp(AnnotationRecord $record, array $finding, TrustedReconciliationPrincipal $principal): array
    {
        $recordId = (string) $record->record_id;
        $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
        $tmpPath = $this->nullableString($evidence['artifact_path'] ?? null);
        $stagingPath = $this->nullableString($evidence['staging_path'] ?? null);
        $stagingSha = $this->nullableString($evidence['staging_sha256'] ?? null);
        if ($tmpPath === null || $stagingPath === null || $stagingSha === null) {
            return ['status' => 'skipped', 'reason' => 'missing_repair_evidence'];
        }

        $result = $this->store->withRecordPromotionLock($recordId, function () use ($record, $recordId, $principal, $tmpPath, $stagingPath, $stagingSha): array {
            $fresh = AnnotationRecord::query()->whereKey($record->id)->first();
            if (! $fresh instanceof AnnotationRecord) {
                return ['status' => 'skipped', 'reason' => 'record_not_found'];
            }
            if (! $this->hasActiveSubmissionIntent($fresh) || $this->isFinalizedRecord($fresh)) {
                return ['status' => 'skipped', 'reason' => 'record_not_eligible'];
            }
            $intentId = $this->nullableString($fresh->submission_intent_id);
            $dbStagingPath = $this->nullableString($fresh->submission_staging_path);
            $dbStagingSha = $this->nullableString($fresh->submission_staging_sha256);
            if ($intentId === null || $dbStagingPath === null || $dbStagingSha === null || ! hash_equals($dbStagingPath, $stagingPath) || ! hash_equals($dbStagingSha, $stagingSha)) {
                return ['status' => 'skipped', 'reason' => 'intent_or_staging_mismatch'];
            }
            $run = $this->correlatedPromotionEligibleValidationRun($fresh, $dbStagingPath, $dbStagingSha);
            if (! $run instanceof AnnotationValidationRun) {
                return ['status' => 'skipped', 'reason' => 'no_correlated_completed_run'];
            }

            $stagingSnapshot = $this->snapshotArtifact($dbStagingPath);
            $tmpSnapshot = $this->snapshotArtifact($tmpPath);
            $finalSnapshot = $this->snapshotArtifact($this->finalRelativePath($recordId));
            if (! ($stagingSnapshot['readable'] ?? false) || ! hash_equals((string) ($stagingSnapshot['sha256'] ?? ''), $dbStagingSha)) {
                return ['status' => 'skipped', 'reason' => 'staging_not_verified'];
            }
            if (! ($tmpSnapshot['readable'] ?? false) || ! hash_equals((string) ($tmpSnapshot['sha256'] ?? ''), $dbStagingSha)) {
                return ['status' => 'skipped', 'reason' => 'temp_not_verified'];
            }

            $finalPath = $this->finalRelativePath($recordId);
            if (($finalSnapshot['exists'] ?? false) === true) {
                $finalSha = $this->nullableString($finalSnapshot['sha256'] ?? null);
                if ($finalSha !== null && hash_equals($finalSha, $dbStagingSha)) {
                    $cleanup = $this->store->cleanupPromotionTempAfterEvidenceCommit($recordId, $tmpPath, $finalSha, (string) $run->input_artifact_sha256);
                    if ($cleanup['removed']) {
                        $this->insertAudit($fresh, $principal, 'reconciliation_recorded', 'A4 promotion temp cleaned after verified final', [
                            'anomaly_type' => 'A4_PROMOTION_TEMP_PRESENT',
                            'submission_intent_id' => $intentId,
                            'cleanup' => $cleanup,
                        ]);
                    }

                    return ['status' => $cleanup['removed'] ? 'applied' : 'idempotent', 'action' => 'cleanup_promotion_temp', 'cleanup' => $cleanup];
                }

                $this->insertAudit($fresh, $principal, 'integrity_check_failed', 'A4 conflicting final artifact', [
                    'anomaly_type' => 'A4_PROMOTION_TEMP_PRESENT',
                    'submission_intent_id' => $intentId,
                    'final_path' => $finalPath,
                    'final_snapshot' => $finalSnapshot,
                ]);

                return ['status' => 'skipped', 'reason' => 'conflicting_final'];
            }

            $publish = $this->store->publishVerifiedPromotionTemp($recordId, $tmpPath, $dbStagingSha, (string) $run->input_artifact_sha256);
            $repair = $this->lifecycle()->reconcileVerifiedFinalizedPromotion($fresh, $run, $publish, $principal);
            if (($repair['idempotent'] ?? false) !== true) {
                $this->insertAudit(AnnotationRecord::query()->whereKey($fresh->id)->firstOrFail(), $principal, 'reconciliation_recorded', 'A4 promotion temp reconciled', [
                    'anomaly_type' => 'A4_PROMOTION_TEMP_PRESENT',
                    'submission_intent_id' => $intentId,
                    'publish' => $publish,
                    'lifecycle_repair' => $repair,
                ]);
            }

            return ['status' => ($repair['idempotent'] ?? false) ? 'idempotent' : 'applied', 'action' => 'publish_finalize_evidence_cleanup', 'publish' => $publish, 'lifecycle_repair' => $repair];
        });

        return $result;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function repairA5FinalWithoutFinalizedDb(AnnotationRecord $record, array $finding, TrustedReconciliationPrincipal $principal): array
    {
        $recordId = (string) $record->record_id;
        $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
        $finalPath = $this->nullableString($evidence['artifact_path'] ?? null) ?? $this->finalRelativePath($recordId);
        $expectedSha = $this->nullableString($evidence['observed_sha256'] ?? null);

        $result = $this->store->withRecordPromotionLock($recordId, function () use ($record, $principal, $finalPath, $expectedSha): array {
            $fresh = AnnotationRecord::query()->whereKey($record->id)->first();
            if (! $fresh instanceof AnnotationRecord) {
                return ['status' => 'skipped', 'reason' => 'record_not_found'];
            }
            $intentId = $this->nullableString($fresh->submission_intent_id);
            $stagingPath = $this->nullableString($fresh->submission_staging_path);
            $stagingSha = $this->nullableString($fresh->submission_staging_sha256);
            if ($intentId === null || $stagingPath === null || $stagingSha === null) {
                return ['status' => 'skipped', 'reason' => 'missing_staging_evidence'];
            }
            $run = $this->correlatedPromotionEligibleValidationRun($fresh, $stagingPath, $stagingSha);
            if (! $run instanceof AnnotationValidationRun) {
                return ['status' => 'skipped', 'reason' => 'no_correlated_completed_run'];
            }
            $finalSnapshot = $this->snapshotArtifact($finalPath);
            $finalSha = $this->nullableString($finalSnapshot['sha256'] ?? null);
            if (! ($finalSnapshot['readable'] ?? false) || $finalSha === null) {
                return ['status' => 'skipped', 'reason' => 'final_not_readable'];
            }
            if (! hash_equals($finalSha, $stagingSha) || ! hash_equals($finalSha, (string) $run->input_artifact_sha256)) {
                $this->insertAudit($fresh, $principal, 'integrity_check_failed', 'A5 final hash mismatch', [
                    'anomaly_type' => 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA',
                    'submission_intent_id' => $intentId,
                    'final_path' => $finalPath,
                    'final_sha256' => $finalSha,
                ]);

                return ['status' => 'skipped', 'reason' => 'final_hash_mismatch'];
            }
            if ($expectedSha !== null && ! hash_equals($expectedSha, $finalSha)) {
                return ['status' => 'skipped', 'reason' => 'finding_hash_stale'];
            }
            if ($this->isFinalizedRecord($fresh)) {
                $updated = $this->promoteValidationRunEvidenceViaLifecycle($fresh, $run, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA', $principal);

                return ['status' => $updated ? 'applied' : 'idempotent', 'action' => 'already_finalized_evidence_sync'];
            }

            $promotion = [
                'record_id' => (string) $fresh->record_id,
                'relative_path' => $finalPath,
                'sha256' => $finalSha,
                'idempotent' => true,
                'source_relative_path' => $stagingPath,
            ];
            $repair = $this->lifecycle()->reconcileVerifiedFinalizedPromotion($fresh, $run, $promotion, $principal);
            if (($repair['idempotent'] ?? false) !== true) {
                $this->insertAudit(AnnotationRecord::query()->whereKey($fresh->id)->firstOrFail(), $principal, 'reconciliation_recorded', 'A5 finalized DB metadata reconciled', [
                    'anomaly_type' => 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA',
                    'submission_intent_id' => $intentId,
                    'final_path' => $finalPath,
                    'final_sha256' => $finalSha,
                    'lifecycle_repair' => $repair,
                ]);
            }

            return ['status' => ($repair['idempotent'] ?? false) ? 'idempotent' : 'applied', 'action' => 'finalize_db_and_evidence', 'lifecycle_repair' => $repair];
        });

        return $result;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function repairA6StaleEvidence(AnnotationRecord $record, array $finding, TrustedReconciliationPrincipal $principal): array
    {
        $recordId = (string) $record->record_id;
        $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
        $runId = isset($evidence['run_id']) ? (int) $evidence['run_id'] : null;
        if ($runId === null || $runId < 1) {
            return ['status' => 'skipped', 'reason' => 'missing_run'];
        }

        $result = $this->store->withRecordPromotionLock($recordId, function () use ($record, $runId, $principal): array {
            $fresh = AnnotationRecord::query()->whereKey($record->id)->first();
            $run = AnnotationValidationRun::query()->whereKey($runId)->first();
            if (! $fresh instanceof AnnotationRecord || ! $run instanceof AnnotationValidationRun) {
                return ['status' => 'skipped', 'reason' => 'record_or_run_not_found'];
            }
            $updated = $this->promoteValidationRunEvidenceViaLifecycle($fresh, $run, 'A6_FINALIZED_DB_WITH_STALE_STAGED_EVIDENCE', $principal);
            if ($updated) {
                $this->insertAudit(AnnotationRecord::query()->whereKey($fresh->id)->firstOrFail(), $principal, 'reconciliation_recorded', 'A6 stale evidence promoted to final artifact', [
                    'anomaly_type' => 'A6_FINALIZED_DB_WITH_STALE_STAGED_EVIDENCE',
                    'run_id' => $run->id,
                ]);
            }

            return ['status' => $updated ? 'applied' : 'idempotent', 'action' => 'promote_evidence_to_final'];
        });

        return $result;
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function repairMissingOrInconsistentEvidence(AnnotationRecord $record, array $finding, TrustedReconciliationPrincipal $principal): array
    {
        $type = (string) ($finding['anomaly_type'] ?? '');
        $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : [];
        $runId = isset($evidence['run_id']) ? (int) $evidence['run_id'] : null;
        if ($runId === null || $runId < 1) {
            return ['status' => 'skipped', 'reason' => 'missing_run'];
        }

        return DB::transaction(function () use ($record, $runId, $type, $principal): array {
            $run = AnnotationValidationRun::query()->whereKey($runId)->lockForUpdate()->first();
            $fresh = AnnotationRecord::query()->whereKey($record->id)->lockForUpdate()->first();
            if (! $run instanceof AnnotationValidationRun || ! $fresh instanceof AnnotationRecord) {
                return ['status' => 'skipped', 'reason' => 'record_or_run_not_found'];
            }
            if ((string) $run->input_evidence_state === 'missing_evidence' && $run->durable_input_evidence_path === null && $run->durable_input_evidence_sha256 === null) {
                return ['status' => 'idempotent', 'action' => 'missing_evidence_already_recorded'];
            }

            $priorState = $this->nullableString($run->input_evidence_state);
            $priorPath = $this->nullableString($run->durable_input_evidence_path);
            $priorSha = $this->nullableString($run->durable_input_evidence_sha256);
            AnnotationValidationRun::query()->whereKey($run->id)->update([
                'input_evidence_state' => 'missing_evidence',
                'durable_input_evidence_path' => null,
                'durable_input_evidence_sha256' => null,
                'updated_at' => now(),
            ]);
            $this->insertAudit($fresh, $principal, 'integrity_check_failed', 'Validation evidence marked missing_evidence', [
                'anomaly_type' => $type,
                'run_id' => $run->id,
                'prior_input_evidence_state' => $priorState,
                'prior_durable_input_evidence_path' => $priorPath,
                'prior_durable_input_evidence_sha256' => $priorSha,
                'preserved_input_artifact_path' => $this->nullableString($run->input_artifact_path),
                'preserved_input_artifact_sha256' => $this->nullableString($run->input_artifact_sha256),
                'reason' => 'reconciliation_evidence_integrity',
            ]);

            return ['status' => 'applied', 'action' => 'mark_missing_evidence'];
        });
    }

    private function isFinalizedRecord(AnnotationRecord $record): bool
    {
        return $this->nullableString($record->submission_state) === 'finalized'
            || $this->nullableString($record->submitted_json_path) !== null
            || $this->nullableString($record->submitted_artifact_sha256) !== null
            || $record->submission_finalized_at !== null;
    }

    private function promoteValidationRunEvidenceViaLifecycle(AnnotationRecord $record, AnnotationValidationRun $run, string $anomalyType, TrustedReconciliationPrincipal $principal): bool
    {
        $fresh = AnnotationRecord::query()->whereKey($record->id)->first();
        if (! $fresh instanceof AnnotationRecord || ! $this->isFinalizedRecord($fresh)) {
            return false;
        }

        $finalPath = $this->nullableString($fresh->submitted_json_path);
        $finalSha = $this->nullableString($fresh->submitted_artifact_sha256);
        $intentId = $this->nullableString($fresh->submission_intent_id);
        $runIntent = $this->nullableString($run->submission_intent_id);
        $runSha = $this->nullableString($run->input_artifact_sha256);
        if ($finalPath === null || $finalSha === null || $intentId === null || $runIntent === null || $runSha === null) {
            return false;
        }
        if ((int) $run->annotation_record_id !== (int) $fresh->id || ! hash_equals($runIntent, $intentId) || ! hash_equals($runSha, $finalSha)) {
            return false;
        }

        $finalSnapshot = $this->snapshotArtifact($finalPath);
        if (! ($finalSnapshot['readable'] ?? false) || ! hash_equals((string) ($finalSnapshot['sha256'] ?? ''), $finalSha)) {
            return false;
        }

        $state = $this->nullableString($run->input_evidence_state);
        if ($state === 'final_artifact') {
            $path = $this->nullableString($run->durable_input_evidence_path);
            $sha = $this->nullableString($run->durable_input_evidence_sha256);
            if ($path !== null && $sha !== null && hash_equals($path, $finalPath) && hash_equals(strtolower($sha), strtolower($finalSha))) {
                return false;
            }
        }

        // Use lifecycle I6-D evidence promotion for normal staged/retained states.
        if (in_array($state, ['staged_path', 'retained_staging_copy', null], true)) {
            return $this->lifecycle()->promoteFinalizedValidationRunEvidenceForReconciliation($fresh, $run, $principal);
        }

        // S2 evidence-state semantics: missing_evidence may be entered only by explicit
        // evidence-retention policy action or reconciliation/integrity detection after
        // evidence loss; final_artifact is entered after successful submission
        // finalization and hash verification. S2 does not explicitly authorize
        // restoring missing_evidence to final_artifact from a final submitted artifact.
        // Having a final artifact with matching hash is not proof of recovering the
        // original input evidence copy. Fail closed and report contract gap.
        if ($state === 'missing_evidence') {
            return false;
        }

        return false;
    }

    /**
     * Append-only reconciliation audit. S2/E0 do not explicitly define reconciliation_recorded
     * atomicity/mandatory semantics; lifecycle transition audits remain atomic in lifecycle txs.
     * This helper is for post-repair observability/integrity audits and must never claim a repair
     * that did not commit. Callers should avoid duplicate idempotent no-op audits.
     *
     * Guard: reconciliation repairs in this path require an authorized user actor. System-actor
     * reconciliation audit is not implemented without explicit S2/E0 authorization.
     */
    private function insertAudit(
        AnnotationRecord $record,
        TrustedReconciliationPrincipal $principal,
        string $action,
        ?string $comment,
        array $metadata
    ): void {
        $this->assertPrincipalAuthorized($principal);

        AnnotationReviewAction::query()->create([
            'annotation_record_id' => $record->id,
            'actor_user_id' => $principal->userId(),
            'actor_type' => 'user',
            'action' => $action,
            'comment' => $comment,
            'metadata_json' => $metadata,
            'created_at' => now(),
        ]);
    }
}
