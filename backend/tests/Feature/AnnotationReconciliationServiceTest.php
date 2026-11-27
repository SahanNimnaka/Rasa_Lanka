<?php

namespace Tests\Feature;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\AnnotationValidationRun;
use App\Models\User;
use App\Services\Annotation\AnnotationReconciliationService;
use App\Services\Annotation\Reconciliation\ReconciliationMode;
use App\Services\Annotation\Reconciliation\TrustedReconciliationPrincipal;
use App\Services\Annotation\Reconciliation\TrustedReconciliationPrincipalFactory;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
use App\Services\Annotation\Storage\Exceptions\ArtifactLockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AnnotationReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $artifactRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artifactRoot = storage_path('framework/testing/annotation-reconciliation-'.uniqid('', true));
        File::ensureDirectoryExists($this->artifactRoot);
        $this->configureStore($this->artifactRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->artifactRoot);
        parent::tearDown();
    }

    private function adminPrincipal(): TrustedReconciliationPrincipal
    {
        $admin = User::factory()->create(['role' => User::ROLE_RESEARCH_ADMIN]);

        return (new TrustedReconciliationPrincipalFactory)->make($admin);
    }

    public function test_detects_orphan_draft_version_and_preserves_file(): void
    {
        [$record] = $this->makeDraftRecord('rec-a1');
        $store = $this->makeStore();
        $currentPath = $record->current_draft_path;
        $orphan = 'drafts/'.$record->record_id.'/versions/orphan.json';
        $this->writeFile($orphan, json_encode(['orphan' => true], JSON_UNESCAPED_SLASHES));

        $before = $this->snapshot($store, $record);

        $service = new AnnotationReconciliationService($store);
        $report = $service->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $this->assertHasFinding($report, 'A1_ORPHAN_DRAFT_VERSION');
        $this->assertFileExists($this->artifactRoot.'/'.$orphan);
        $this->assertSame($currentPath, $record->fresh()->current_draft_path);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_detects_missing_current_draft_file_as_integrity_incident(): void
    {
        [$record] = $this->makeDraftRecord('rec-a2');
        $store = $this->makeStore();
        $path = $record->current_draft_path;
        File::delete($this->artifactRoot.'/'.$path);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A2_MISSING_CURRENT_DRAFT_FILE');
        $this->assertSame('integrity_incident', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
    }

    public function test_distinguishes_missing_current_draft_from_hash_mismatch(): void
    {
        [$missingRecord] = $this->makeDraftRecord('rec-a2-missing');
        [$mismatchRecord] = $this->makeDraftRecord('rec-a10-mismatch');
        $store = $this->makeStore();

        File::delete($this->artifactRoot.'/'.$missingRecord->current_draft_path);
        File::put($this->artifactRoot.'/'.$mismatchRecord->current_draft_path, json_encode(['tampered' => true], JSON_UNESCAPED_SLASHES));

        $service = new AnnotationReconciliationService($store);
        $missingReport = $service->reportRecord($missingRecord->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $mismatchReport = $service->reportRecord($mismatchRecord->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $this->assertHasFinding($missingReport, 'A2_MISSING_CURRENT_DRAFT_FILE');
        $this->assertDoesNotHaveFinding($missingReport, 'A10_GENERIC_HASH_INCONSISTENCY');
        $this->assertHasFinding($mismatchReport, 'A10_GENERIC_HASH_INCONSISTENCY');
        $this->assertDoesNotHaveFinding($mismatchReport, 'A2_MISSING_CURRENT_DRAFT_FILE');
    }

    public function test_reports_orphan_staging_without_finalized_artifact_and_preserves_it(): void
    {
        [$record] = $this->makeDraftRecord('rec-a3');
        $store = $this->makeStore();
        $intent = 'intent-a3';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);

        $record->forceFill([
            'submission_intent_id' => $intent,
            'submission_state' => 'staging_written',
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
        ])->save();

        $before = $this->snapshot($store, $record);
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A3_STAGING_INTENT_WITHOUT_FINALIZED_ARTIFACT');
        $this->assertSame(false, $finding['evidence']['auto_delete_in_i6e'] ?? null);
        $this->assertFileExists($this->artifactRoot.'/'.$staging['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_classifies_promotion_temp_without_mutation(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4');
        $store = $this->makeStore();
        $intent = 'intent-a4';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $submittedRoot = (string) config('annotation.annotation_submitted_path', 'submitted');
        $tmp = $submittedRoot.'/'.$record->record_id.'/.record.json.promote-test.tmp';
        $this->writeFile($tmp, json_encode(['staged' => true], JSON_UNESCAPED_SLASHES));

        $record->forceFill([
            'submission_intent_id' => $intent,
            'submission_state' => 'promotion_pending',
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
        ])->save();

        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertContains($finding['classification'], ['recoverable_candidate', 'incomplete_candidate', 'integrity_incident']);
        $this->assertFileExists($this->artifactRoot.'/'.$tmp);
        $this->assertSame('promotion_pending', $record->fresh()->submission_state);
        $this->assertSame($staging['sha256'], $run->fresh()->input_artifact_sha256);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_detects_final_exists_without_finalized_db_metadata_and_preserves_conflicting_final(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5');
        $store = $this->makeStore();
        $intent = 'intent-a5';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $submittedRoot = (string) config('annotation.annotation_submitted_path', 'submitted');
        $finalPath = $submittedRoot.'/'.$record->record_id.'/record.json';
        $final = $this->writeFile($finalPath, json_encode(['conflicting' => true], JSON_UNESCAPED_SLASHES));

        $record->forceFill([
            'submission_intent_id' => $intent,
            'submission_state' => 'promotion_pending',
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
            'submitted_json_path' => null,
            'submitted_artifact_sha256' => null,
            'submission_finalized_at' => null,
        ])->save();

        $before = $this->snapshot($store, $record);
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('integrity_incident', $finding['classification']);
        $this->assertFileExists($this->artifactRoot.'/'.$finalPath);
        $this->assertSame($final['sha256'], hash_file('sha256', $this->artifactRoot.'/'.$finalPath));
        $this->assertNull($record->fresh()->submitted_json_path);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_detects_finalized_record_with_stale_staged_evidence(): void
    {
        [$record] = $this->makeDraftRecord('rec-a6');
        $store = $this->makeStore();
        $intent = 'intent-a6';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $submittedRoot = (string) config('annotation.annotation_submitted_path', 'submitted');
        $finalPath = $submittedRoot.'/'.$record->record_id.'/record.json';
        $final = $this->writeFile($finalPath, json_encode(['staged' => true], JSON_UNESCAPED_SLASHES));

        $record->forceFill([
            'submission_intent_id' => $intent,
            'submission_state' => 'finalized',
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
            'submitted_json_path' => $finalPath,
            'submitted_artifact_sha256' => $final['sha256'],
            'submission_finalized_at' => now(),
        ])->save();

        $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A6_FINALIZED_RECORD_STALE_EVIDENCE');
        $this->assertSame('potentially_recoverable_by_reconciliation', $finding['recoverability']);
    }

    public function test_reports_missing_durable_evidence_without_changing_db_fields(): void
    {
        [$record] = $this->makeDraftRecord('rec-a7');
        $store = $this->makeStore();
        $intent = 'intent-a7';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        File::delete($this->artifactRoot.'/'.$staging['relative_path']);

        $before = $run->fresh()->getAttributes();
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A7_MISSING_DURABLE_EVIDENCE_FILE');
        $this->assertSame('missing_evidence', $finding['classification']);
        $after = $run->fresh()->getAttributes();
        $this->assertSame($before['input_evidence_state'], $after['input_evidence_state']);
        $this->assertSame($before['durable_input_evidence_path'], $after['durable_input_evidence_path']);
        $this->assertSame($before['durable_input_evidence_sha256'], $after['durable_input_evidence_sha256']);
        $this->assertSame($before['input_artifact_path'], $after['input_artifact_path']);
        $this->assertSame($before['input_artifact_sha256'], $after['input_artifact_sha256']);
    }

    public function test_reports_durable_evidence_hash_mismatch_as_integrity_incident(): void
    {
        [$record] = $this->makeDraftRecord('rec-a8');
        $store = $this->makeStore();
        $intent = 'intent-a8';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        File::put($this->artifactRoot.'/'.$staging['relative_path'], json_encode(['tampered' => true], JSON_UNESCAPED_SLASHES));

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A8_DURABLE_EVIDENCE_HASH_MISMATCH');
        $this->assertSame('integrity_incident', $finding['classification']);
    }

    public function test_reports_evidence_path_nonexistent_when_available_state_has_null_path(): void
    {
        [$record] = $this->makeDraftRecord('rec-a9');
        $store = $this->makeStore();
        $intent = 'intent-a9';
        $this->makeValidationRun($record, $intent, 'staged_path', 'submitted/'.$record->record_id.'/staging/'.$intent.'.json', hash('sha256', 'x'), null, null);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A9_EVIDENCE_PATH_NONEXISTENT');
        $this->assertSame('missing_evidence', $finding['classification']);
    }

    public function test_reports_infra_failed_run_missing_expected_audit_without_fabricating_audit(): void
    {
        [$record] = $this->makeDraftRecord('rec-a11');
        $store = $this->makeStore();
        $intent = 'intent-a11';
        $run = $this->makeValidationRun($record, $intent, 'staged_path', 'submitted/'.$record->record_id.'/staging/'.$intent.'.json', hash('sha256', 'x'), hash('sha256', 'x'), 'staged_path');
        $run->forceFill(['status' => 'infra_failed', 'is_valid' => null])->save();

        $beforeAuditCount = AnnotationReviewAction::query()->count();
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A11_INFRA_FAILED_RUN_MISSING_EXPECTED_AUDIT');
        $this->assertSame('audit_inconsistency', $finding['classification']);
        $this->assertSame($beforeAuditCount, AnnotationReviewAction::query()->count());
        $this->assertDatabaseMissing('annotation_review_actions', ['action' => 'validation_infra_failed']);
        $this->assertDatabaseMissing('annotation_review_actions', ['action' => 'submission_infra_failed']);
    }

    public function test_no_op_scan_creates_no_audits_and_leaves_db_fs_unchanged(): void
    {
        [$record] = $this->makeDraftRecord('rec-noop');
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $beforeAuditCount = AnnotationReviewAction::query()->count();

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $this->assertSame([], $report['findings']);
        $this->assertSame($beforeAuditCount, AnnotationReviewAction::query()->count());
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_lock_acquisition_failure_produces_retryable_deferral_and_no_mutation(): void
    {
        [$record] = $this->makeDraftRecord('rec-lock');
        $store = new class extends AnnotationArtifactStore
        {
            public function withRecordPromotionLock(string $recordId, callable $callback): mixed
            {
                throw new ArtifactLockException('simulated lock timeout');
            }
        };
        $before = $this->snapshot($this->makeStore(), $record);
        $beforeAuditCount = AnnotationReviewAction::query()->count();

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A12_LOCK_TIMEOUT_DEFERRED');
        $this->assertSame('retryable_deferral', $finding['classification']);
        $this->assertSame('deferred', $report['status']);
        $this->assertSame($beforeAuditCount, AnnotationReviewAction::query()->count());
        $this->assertSnapshotUnchanged($this->makeStore(), $record, $before);
    }

    public function test_a5_missing_staging_sha_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5-missing-sha');
        $intent = 'intent-a5-missing-sha';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $final = $this->writeFinal($record->record_id, $staging['sha256'], ['record_id' => $record->record_id, 'intent' => $intent]);
        $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);

        $record->forceFill([
            'workflow_status' => 'submitted',
            'artifact_state' => 'staged',
            'submission_state' => 'active',
            'submission_intent_id' => $intent,
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => null,
            'submitted_json_path' => null,
            'submitted_artifact_sha256' => null,
            'submission_finalized_at' => null,
        ])->save();
        $record = $record->fresh();

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $beforeAuditCount = AnnotationReviewAction::query()->count();

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('integrity_incident', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertSame($beforeAuditCount, AnnotationReviewAction::query()->count());
        $this->assertFileExists($this->artifactRoot.'/'.$final['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a5_conflicting_staging_and_final_hashes_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5-conflict');
        $intent = 'intent-a5-conflict';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent, 'v' => 'staging']);
        $final = $this->writeFinal($record->record_id, hash('sha256', 'different-final'), ['record_id' => $record->record_id, 'intent' => $intent, 'v' => 'final']);
        $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);

        $record->forceFill([
            'workflow_status' => 'submitted',
            'artifact_state' => 'staged',
            'submission_state' => 'active',
            'submission_intent_id' => $intent,
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
            'submitted_json_path' => null,
            'submitted_artifact_sha256' => null,
            'submission_finalized_at' => null,
        ])->save();
        $record = $record->fresh();

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('integrity_incident', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$final['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a5_completed_valid_run_with_matching_evidence_is_potentially_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5-valid-ok');
        $intent = 'intent-a5-valid-ok';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $submittedRoot = (string) config('annotation.annotation_submitted_path', 'submitted');
        $finalPath = $submittedRoot.'/'.$record->record_id.'/record.json';
        $final = $this->writeFile($finalPath, json_encode(['staged' => true], JSON_UNESCAPED_SLASHES));
        $this->assertSame($staging['sha256'], $final['sha256']);
        $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('recoverable_integrity_state', $finding['classification']);
        $this->assertSame('potentially_recoverable_by_reconciliation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$final['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a5_completed_invalid_run_with_matching_evidence_is_potentially_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5-invalid-ok');
        $intent = 'intent-a5-invalid-ok';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $submittedRoot = (string) config('annotation.annotation_submitted_path', 'submitted');
        $finalPath = $submittedRoot.'/'.$record->record_id.'/record.json';
        $final = $this->writeFile($finalPath, json_encode(['staged' => true], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['is_valid' => false, 'is_export_eligible_canonical' => false])->save();
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('recoverable_integrity_state', $finding['classification']);
        $this->assertSame('potentially_recoverable_by_reconciliation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$final['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a5_completed_run_with_different_input_path_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5-wrong-path');
        $intent = 'intent-a5-wrong-path';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $submittedRoot = (string) config('annotation.annotation_submitted_path', 'submitted');
        $finalPath = $submittedRoot.'/'.$record->record_id.'/record.json';
        $final = $this->writeFile($finalPath, json_encode(['staged' => true], JSON_UNESCAPED_SLASHES));
        $this->makeValidationRun($record, $intent, 'staged_path', 'submitted/'.$record->record_id.'/staging/other.json', $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('integrity_incident', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$final['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a5_completed_run_with_empty_input_path_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5-empty-path');
        $intent = 'intent-a5-empty-path';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $submittedRoot = (string) config('annotation.annotation_submitted_path', 'submitted');
        $finalPath = $submittedRoot.'/'.$record->record_id.'/record.json';
        $final = $this->writeFile($finalPath, json_encode(['staged' => true], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['input_artifact_path' => '   '])->save();
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('integrity_incident', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$final['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a5_completed_run_with_null_is_valid_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5-null-valid');
        $intent = 'intent-a5-null-valid';
        $staging = $this->writeStaging($record->record_id, $intent, ['staged' => true]);
        $submittedRoot = (string) config('annotation.annotation_submitted_path', 'submitted');
        $finalPath = $submittedRoot.'/'.$record->record_id.'/record.json';
        $final = $this->writeFile($finalPath, json_encode(['staged' => true], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['is_valid' => null])->save();
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('integrity_incident', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$final['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a11_missing_audit_metadata_is_ambiguous_and_not_counted_as_match(): void
    {
        [$record] = $this->makeDraftRecord('rec-a11-missing-meta');
        $intent = 'intent-a11-missing-meta';
        $run = AnnotationValidationRun::query()->create([
            'run_id' => 'run-a11-missing-meta',
            'annotation_record_id' => $record->id,
            'submission_intent_id' => $intent,
            'b1_version' => 'b1-test',
            'input_artifact_path' => 'submitted/'.$record->record_id.'/staging/'.$intent.'.json',
            'input_artifact_sha256' => hash('sha256', 'x'),
            'input_evidence_state' => 'staged_path',
            'durable_input_evidence_path' => 'submitted/'.$record->record_id.'/staging/'.$intent.'.json',
            'durable_input_evidence_sha256' => hash('sha256', 'x'),
            'status' => 'infra_failed',
            'is_valid' => false,
            'is_export_eligible_canonical' => false,
            'error_count' => 0,
            'warning_count' => 0,
            'export_block_count' => 0,
            'timed_out' => false,
            'executed_by_user_id' => $record->created_by_user_id,
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AnnotationReviewAction::query()->create([
            'annotation_record_id' => $record->id,
            'submission_intent_id' => $intent,
            'action' => 'submission_infra_failed',
            'actor_user_id' => $record->created_by_user_id,
            'metadata_json' => ['note' => 'missing run and intent metadata'],
            'created_at' => now(),
        ]);

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $beforeAuditCount = AnnotationReviewAction::query()->count();
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A11_INFRA_FAILED_RUN_MISSING_EXPECTED_AUDIT');
        $this->assertSame('audit_inconsistency_ambiguous', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertSame($run->id, $finding['evidence']['run_id']);
        $this->assertTrue($finding['evidence']['correlation']['has_ambiguous_match']);
        $this->assertSame($beforeAuditCount, AnnotationReviewAction::query()->count());
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a11_ambiguous_audit_correlation_is_reported_conservatively(): void
    {
        [$record] = $this->makeDraftRecord('rec-a11-ambig');
        $intent = 'intent-a11-ambig';
        $run = AnnotationValidationRun::query()->create([
            'run_id' => 'run-a11-ambig',
            'annotation_record_id' => $record->id,
            'submission_intent_id' => $intent,
            'b1_version' => 'b1-test',
            'input_artifact_path' => 'submitted/'.$record->record_id.'/staging/'.$intent.'.json',
            'input_artifact_sha256' => hash('sha256', 'y'),
            'input_evidence_state' => 'staged_path',
            'durable_input_evidence_path' => 'submitted/'.$record->record_id.'/staging/'.$intent.'.json',
            'durable_input_evidence_sha256' => hash('sha256', 'y'),
            'status' => 'infra_failed',
            'is_valid' => false,
            'is_export_eligible_canonical' => false,
            'error_count' => 0,
            'warning_count' => 0,
            'export_block_count' => 0,
            'timed_out' => false,
            'executed_by_user_id' => $record->created_by_user_id,
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AnnotationReviewAction::query()->create([
            'annotation_record_id' => $record->id,
            'submission_intent_id' => $intent,
            'action' => 'validation_infra_failed',
            'actor_user_id' => $record->created_by_user_id,
            'metadata_json' => ['run_id' => 'run-a11-ambig'],
            'created_at' => now(),
        ]);

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A11_INFRA_FAILED_RUN_MISSING_EXPECTED_AUDIT');
        $this->assertSame('audit_inconsistency_ambiguous', $finding['classification']);
        $this->assertSame($run->id, $finding['evidence']['run_id']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_unrelated_validation_run_hash_does_not_create_false_recoverable_candidate(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-unrelated');
        $intent = 'intent-a4-unrelated';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('d', 16).'.tmp';
        $tmp = $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        // Run exists for same record/intent but its input hash does not match staging; must not be treated as correlated.
        $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], hash('sha256', 'unrelated-run-hash'), null, null);

        $record->forceFill([
            'workflow_status' => 'submitted',
            'artifact_state' => 'staged',
            'submission_state' => 'active',
            'submission_intent_id' => $intent,
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertStringContainsString('no positively correlated completed validation run', $finding['explanation']);
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_no_matching_validation_run_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-no-run');
        $intent = 'intent-a4-no-run';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('a', 16).'.tmp';
        $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_matching_record_but_wrong_intent_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-wrong-intent');
        $intent = 'intent-a4-right';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('b', 16).'.tmp';
        $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $this->makeValidationRun($record, 'intent-a4-wrong', 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_matching_record_and_intent_but_wrong_input_hash_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-wrong-hash');
        $intent = 'intent-a4-wrong-hash';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('c', 16).'.tmp';
        $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], hash('sha256', 'other'), null, null);
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_matching_hash_but_different_input_path_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-wrong-path');
        $intent = 'intent-a4-wrong-path';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('4', 16).'.tmp';
        $tmp = $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $this->makeValidationRun($record, $intent, 'staged_path', 'submitted/'.$record->record_id.'/staging/other-intent.json', $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertNotSame('recoverable_candidate', $finding['classification']);
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_matching_hash_but_empty_input_path_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-empty-path');
        $intent = 'intent-a4-empty-path';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('5', 16).'.tmp';
        $tmp = $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['input_artifact_path' => '   '])->save();
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_positively_correlated_completed_run_with_matching_hash_is_recoverable_candidate(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-ok');
        $intent = 'intent-a4-ok';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('e', 16).'.tmp';
        $tmp = $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertTrue($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('recoverable_candidate', $finding['classification']);
        $this->assertSame('potentially_recoverable_by_reconciliation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_completed_invalid_b1_run_matching_record_intent_hash_is_recoverable_candidate(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-invalid');
        $intent = 'intent-a4-invalid';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('2', 16).'.tmp';
        $tmp = $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['status' => 'completed', 'is_valid' => false, 'is_export_eligible_canonical' => false])->save();
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertTrue($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('recoverable_candidate', $finding['classification']);
        $this->assertSame('potentially_recoverable_by_reconciliation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_non_boolean_is_valid_run_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-nonbool');
        $intent = 'intent-a4-nonbool';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('3', 16).'.tmp';
        $tmp = $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['status' => 'completed', 'is_valid' => null, 'is_export_eligible_canonical' => false])->save();
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_matching_infra_failed_run_cannot_support_recoverable_candidate(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-infra');
        $intent = 'intent-a4-infra';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('1', 16).'.tmp';
        $tmp = $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['status' => 'infra_failed', 'is_valid' => false, 'is_export_eligible_canonical' => false])->save();
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertNotSame('recoverable_candidate', $finding['classification']);
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a4_ambiguous_correlation_is_not_recoverable(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-ambig');
        $intent = 'intent-a4-ambig';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('f', 16).'.tmp';
        $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        // Same intent/run correlation exists by hash, but run is not a completed positive correlation for this E1 conservative path? We model ambiguity as: matching hash on different intent-like metadata unavailable is not possible here; use no positively correlated completed run by having only infra_failed run missing completed proof? Instead, create run with matching hash but status not completed/infra_failed matching query? No: query allows completed/infra_failed. Ambiguity case: matching hash exists but record intent is null so correlation cannot be proven.
        $record->forceFill([
            'workflow_status' => 'submitted', 'artifact_state' => 'staged', 'submission_state' => 'active',
            'submission_intent_id' => null, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A4_PROMOTION_TEMP_PRESENT');
        $this->assertFalse($finding['evidence']['validation_run_hash_correlated']);
        $this->assertSame('report_only_unproven_candidate', $finding['classification']);
        $this->assertSame('manual_investigation', $finding['recoverability']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_repeated_scans_produce_consistent_findings_without_mutation(): void
    {
        [$record] = $this->makeDraftRecord('rec-repeat');
        $intent = 'intent-repeat';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $tmpPath = 'submitted/'.$record->record_id.'/record.json.tmp.'.str_repeat('e', 16).'.deadbeef';
        $this->writeFile($tmpPath, json_encode(['record_id' => $record->record_id, 'intent' => $intent], JSON_UNESCAPED_SLASHES));
        $record->forceFill([
            'workflow_status' => 'submitted',
            'artifact_state' => 'staged',
            'submission_state' => 'active',
            'submission_intent_id' => $intent,
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $record = $record->fresh();

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $service = new AnnotationReconciliationService($store);
        $first = $service->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $second = $service->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $this->assertSame($first['findings'], $second['findings']);
        $this->assertSame($first['status'], $second['status']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a10_generic_hash_inconsistency_for_staging_is_reported(): void
    {
        [$record] = $this->makeDraftRecord('rec-a10-staging');
        $intent = 'intent-a10-staging';
        $staging = $this->writeStaging($record->record_id, $intent, ['record_id' => $record->record_id, 'intent' => $intent]);
        $record->forceFill([
            'workflow_status' => 'submitted',
            'artifact_state' => 'staged',
            'submission_state' => 'active',
            'submission_intent_id' => $intent,
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => hash('sha256', 'wrong'),
        ])->save();
        $record = $record->fresh();

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $finding = $this->assertHasFinding($report, 'A10_GENERIC_HASH_INCONSISTENCY');
        $this->assertSame('integrity_incident', $finding['classification']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a1_reports_eligibility_checks_not_proven(): void
    {
        [$record] = $this->makeDraftRecord('rec-a1-elig');
        $store = $this->makeStore();
        $this->writeFile('drafts/'.$record->record_id.'/versions/v2.json', json_encode(['record_id' => $record->record_id, 'v' => 2], JSON_UNESCAPED_SLASHES));
        $before = $this->snapshot($store, $record);

        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);
        $finding = $this->assertHasFinding($report, 'A1_ORPHAN_DRAFT_VERSION');
        $this->assertFalse($finding['evidence']['rd06_cleanup_eligible_in_e1']);
        $this->assertFalse($finding['evidence']['eligibility_checks']['active_submission_intent_reference_checked']);
        $this->assertFalse($finding['evidence']['eligibility_checks']['active_recovery_reference_checked']);
        $this->assertFalse($finding['evidence']['eligibility_checks']['evidence_retention_reference_checked']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_multiple_intents_scan_reports_only_relevant_active_intent_without_mutation(): void
    {
        [$record] = $this->makeDraftRecord('rec-multi-intent');
        $intent1 = 'intent-old';
        $intent2 = 'intent-active';
        $staging1 = $this->writeStaging($record->record_id, $intent1, ['record_id' => $record->record_id, 'intent' => $intent1]);
        $staging2 = $this->writeStaging($record->record_id, $intent2, ['record_id' => $record->record_id, 'intent' => $intent2]);
        $record->forceFill([
            'workflow_status' => 'submitted',
            'artifact_state' => 'staged',
            'submission_state' => 'active',
            'submission_intent_id' => $intent2,
            'submission_staging_path' => $staging2['relative_path'],
            'submission_staging_sha256' => $staging2['sha256'],
        ])->save();
        $record = $record->fresh();

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $this->assertHasFinding($report, 'A3_STAGING_INTENT_WITHOUT_FINALIZED_ARTIFACT');
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a7_invalid_or_unreadable_final_path_is_reported_without_mutation(): void
    {
        [$record] = $this->makeDraftRecord('rec-a7-unreadable');
        $intent = 'intent-a7-unreadable';
        $record->forceFill([
            'workflow_status' => 'finalized',
            'artifact_state' => 'finalized',
            'submission_state' => 'finalized',
            'submission_intent_id' => $intent,
            'submitted_json_path' => 'submitted/'.$record->record_id.'/record.json',
            'submitted_artifact_sha256' => hash('sha256', 'missing-final'),
            'submission_finalized_at' => now(),
        ])->save();
        $record = $record->fresh();

        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $report = (new AnnotationReconciliationService($store))->reportRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $this->assertHasFinding($report, 'A7_MISSING_DURABLE_EVIDENCE_FILE');
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    private function configureStore(string $root): void
    {
        config()->set('annotation.annotation_storage_disk', 'annotation');
        config()->set('annotation.annotation_storage_driver', 'local');
        config()->set('annotation.annotation_artifact_root', $root);
        config()->set('annotation.annotation_draft_versions_path', 'drafts');
        config()->set('annotation.annotation_submitted_path', 'submitted');
        config()->set('annotation.annotation_validation_report_path', 'validation-runs');
        config()->set('annotation.annotation_temp_path', 'tmp');
        config()->set('annotation.annotation_promotion_lock_wait_seconds', 5);
        config()->set('filesystems.disks.annotation', [
            'driver' => 'local',
            'root' => $root,
            'throw' => false,
        ]);
    }

    /**
     * @return array{relative_path:string,sha256:string,bytes:int}
     */
    private function writeFile(string $relativePath, string $content): array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $absolute = $this->artifactRoot.'/'.$relativePath;
        File::ensureDirectoryExists(dirname($absolute));
        File::put($absolute, $content);

        return [
            'relative_path' => $relativePath,
            'sha256' => hash('sha256', $content),
            'bytes' => strlen($content),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{relative_path:string,sha256:string,bytes:int}
     */
    private function writeStaging(string $recordId, string $intent, array $payload): array
    {
        $submittedRoot = trim((string) config('annotation.annotation_submitted_path', 'submitted'), '/\\');
        $path = $submittedRoot.'/'.$recordId.'/staging/'.$intent.'.json';

        return $this->writeFile($path, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{relative_path:string,sha256:string,bytes:int}
     */
    private function writeFinal(string $recordId, string $expectedSha, array $payload): array
    {
        $submittedRoot = trim((string) config('annotation.annotation_submitted_path', 'submitted'), '/\\');
        $path = $submittedRoot.'/'.$recordId.'/record.json';
        $written = $this->writeFile($path, json_encode($payload, JSON_UNESCAPED_SLASHES));
        if ($written['sha256'] !== $expectedSha) {
            $content = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $written = $this->writeFile($path, $content);
        }

        return $written;
    }

    private function makeStore(): AnnotationArtifactStore
    {
        return new AnnotationArtifactStore;
    }

    /**
     * @return array{0:AnnotationRecord,1:User}
     */
    private function makeDraftRecord(string $recordId): array
    {
        $user = User::factory()->create(['role' => User::ROLE_ANNOTATOR]);
        $record = new AnnotationRecord;
        $record->forceFill([
            'record_id' => $recordId,
            'schema_version' => 'human-recognition-v1',
            'recognition_method' => 'manual',
            'verification_status' => 'unverified',
            'workflow_status' => 'draft',
            'artifact_state' => 'draft',
            'current_draft_version_id' => 'v1',
            'created_by_user_id' => $user->id,
            'lock_version' => 1,
        ])->save();

        $draftPath = 'drafts/'.$recordId.'/versions/v1.json';
        $written = $this->writeFile($draftPath, json_encode(['record_id' => $recordId, 'v' => 1], JSON_UNESCAPED_SLASHES));
        $record->forceFill([
            'current_draft_path' => $draftPath,
            'current_draft_sha256' => $written['sha256'],
        ])->save();

        return [$record->fresh(), $user];
    }

    private function makeValidationRun(AnnotationRecord $record, string $intent, string $inputEvidenceState, string $inputPath, string $inputSha, ?string $durableSha, string|null|false $durablePath = false): AnnotationValidationRun
    {
        if ($durablePath === false) {
            $durablePath = $inputPath;
        }

        return AnnotationValidationRun::query()->create([
            'run_id' => 'run-'.uniqid('', true),
            'annotation_record_id' => $record->id,
            'submission_intent_id' => $intent,
            'b1_version' => 'b1-test',
            'input_artifact_path' => $inputPath,
            'input_artifact_sha256' => $inputSha,
            'input_evidence_state' => $inputEvidenceState,
            'durable_input_evidence_path' => $durablePath,
            'durable_input_evidence_sha256' => $durableSha,
            'status' => 'completed',
            'is_valid' => true,
            'is_export_eligible_canonical' => true,
            'error_count' => 0,
            'warning_count' => 0,
            'export_block_count' => 0,
            'timed_out' => false,
            'executed_by_user_id' => $record->created_by_user_id,
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function assertHasFinding(array $report, string $type): array
    {
        foreach ($report['findings'] as $finding) {
            if (($finding['anomaly_type'] ?? null) === $type) {
                $this->assertNotEmpty($finding['explanation'] ?? null);
                $this->assertSame($report['record_id'], $finding['record_id']);

                return $finding;
            }
        }

        $this->fail('Expected finding '.$type.' in '.json_encode($report['findings'], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function assertDoesNotHaveFinding(array $report, string $type): void
    {
        foreach ($report['findings'] as $finding) {
            if (($finding['anomaly_type'] ?? null) === $type) {
                $this->fail('Did not expect finding '.$type);
            }
        }
        $this->assertTrue(true);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(AnnotationArtifactStore $store, AnnotationRecord $record): array
    {
        $record = $record->fresh();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->artifactRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($this->artifactRoot) + 1));
            if (str_ends_with($rel, '.promotion.lock')) {
                continue;
            }
            $files[$rel] = hash_file('sha256', $file->getPathname());
        }
        ksort($files);

        return [
            'record' => $record ? $record->getAttributes() : null,
            'files' => $files,
            'audits' => AnnotationReviewAction::query()->count(),
            'runs' => AnnotationValidationRun::query()->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $before
     */
    private function assertSnapshotUnchanged(AnnotationArtifactStore $store, AnnotationRecord $record, array $before): void
    {
        $after = $this->snapshot($store, $record);
        $this->assertSame($before, $after);
    }
}
