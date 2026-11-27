<?php

namespace Tests\Feature;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\AnnotationValidationRun;
use App\Models\User;
use App\Services\Annotation\AnnotationReconciliationService;
use App\Services\Annotation\AnnotationRecordLifecycleService;
use App\Services\Annotation\Exceptions\AnnotationLifecycleAuthorizationException;
use App\Services\Annotation\Reconciliation\ReconciliationMode;
use App\Services\Annotation\Reconciliation\TrustedReconciliationPrincipal;
use App\Services\Annotation\Reconciliation\TrustedReconciliationPrincipalFactory;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
use App\Services\Annotation\Storage\Exceptions\ArtifactLockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnnotationReconciliationRepairTest extends TestCase
{
    use RefreshDatabase;

    private string $artifactRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artifactRoot = storage_path('framework/testing/annotation-repair-'.Str::uuid());
        File::ensureDirectoryExists($this->artifactRoot);
        Config::set('filesystems.disks.annotation', [
            'driver' => 'local',
            'root' => $this->artifactRoot,
            'throw' => false,
            'serve' => false,
        ]);
        Config::set('annotation.annotation_storage_disk', 'annotation');
        Config::set('annotation.annotation_storage_driver', 'local');
        Config::set('annotation.annotation_artifact_root', $this->artifactRoot);
        Config::set('annotation.annotation_draft_versions_path', 'drafts');
        Config::set('annotation.annotation_submitted_path', 'submitted');
        Config::set('annotation.annotation_temp_path', 'tmp');
        Config::set('annotation.annotation_promotion_lock_wait_seconds', 1);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->artifactRoot);
        parent::tearDown();
    }

    private function adminPrincipal(): TrustedReconciliationPrincipal
    {
        $admin = $this->createUser(User::ROLE_RESEARCH_ADMIN);

        return (new TrustedReconciliationPrincipalFactory)->make($admin);
    }

    private function principalFor(User $user): TrustedReconciliationPrincipal
    {
        return (new TrustedReconciliationPrincipalFactory)->make($user);
    }

    public function test_a5_dry_run_reports_repair_candidate_without_mutation(): void
    {
        [$record, $run] = $this->makeA5Candidate();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $svc = new AnnotationReconciliationService($store);

        $report = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::REPORT_ONLY);

        $this->assertSame([], $report['repairs']);
        $finding = $this->findFinding($report, 'A5_FINAL_EXISTS_WITHOUT_FINALIZED_DB_METADATA');
        $this->assertSame('recoverable_integrity_state', $finding['classification']);
        $this->assertSame(1, $svc->reportAll($this->adminPrincipal(), ReconciliationMode::REPORT_ONLY)['summary']['repair_candidates']);
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_a5_apply_repairs_finalized_metadata_and_is_idempotent(): void
    {
        [$record, $run] = $this->makeA5Candidate();
        $store = $this->makeStore();
        $svc = new AnnotationReconciliationService($store);

        $first = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertNotEmpty($first['repairs']);
        $this->assertContains($first['repairs'][0]['status'], ['applied', 'idempotent']);
        $fresh = $record->fresh();
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame('submitted_finalized', $fresh->artifact_state);
        $this->assertNotNull($fresh->submitted_json_path);
        $this->assertSame($fresh->submission_staging_sha256, $fresh->submitted_artifact_sha256);
        $run = $run->fresh();
        $this->assertSame('final_artifact', $run->input_evidence_state);
        $this->assertSame($fresh->submitted_json_path, $run->durable_input_evidence_path);
        $this->assertSame($fresh->submitted_artifact_sha256, $run->durable_input_evidence_sha256);

        $second = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertTrue(collect($second['repairs'])->every(fn ($r) => in_array($r['status'], ['applied', 'idempotent', 'skipped'], true)) || $second['repairs'] === []);
        $after = $record->fresh();
        $this->assertSame($fresh->submitted_artifact_sha256, $after->submitted_artifact_sha256);
        $this->assertSame($fresh->submission_state, $after->submission_state);
        $this->assertSame($fresh->workflow_status, $after->workflow_status);
    }

    public function test_a6_apply_promotes_stale_staged_evidence_after_final_verified(): void
    {
        [$record] = $this->makeDraftRecord('rec-a6-repair');
        $intent = 'intent-a6-repair';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $final = $this->writeFinal($record->record_id, $staging['sha256'], ['v' => 1]);
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'validated',
            'artifact_state' => 'submitted_finalized',
            'submission_state' => 'finalized',
            'submission_intent_id' => $intent,
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
            'submitted_json_path' => $final['relative_path'],
            'submitted_artifact_sha256' => $final['sha256'],
            'submission_finalized_at' => now(),
        ])->save();
        $svc = new AnnotationReconciliationService($this->makeStore());
        $report = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);

        $this->assertNotEmpty($report['repairs']);
        $run = $run->fresh();
        $this->assertSame('final_artifact', $run->input_evidence_state);
        $this->assertSame($final['relative_path'], $run->durable_input_evidence_path);
        $this->assertSame($final['sha256'], $run->durable_input_evidence_sha256);
    }

    public function test_a7_missing_durable_evidence_transitions_to_missing_evidence_with_audit(): void
    {
        [$record] = $this->makeDraftRecord('rec-a7-repair');
        $intent = 'intent-a7-repair';
        $stagingPath = 'submitted/'.$record->record_id.'/staging/'.$intent.'.json';
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $stagingPath, hash('sha256', 'x'), hash('sha256', 'x'));
        $record->forceFill(['submission_intent_id' => $intent, 'submission_state' => 'active'])->save();
        $svc = new AnnotationReconciliationService($this->makeStore());
        $report = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);

        $this->assertNotEmpty($report['repairs']);
        $run = $run->fresh();
        $this->assertSame('missing_evidence', $run->input_evidence_state);
        $this->assertNull($run->durable_input_evidence_path);
        $this->assertNull($run->durable_input_evidence_sha256);
        $this->assertSame(hash('sha256', 'x'), $run->input_artifact_sha256);
        $this->assertTrue(AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'integrity_check_failed')->exists());
    }

    private function makeA5Candidate(): array
    {
        [$record] = $this->makeDraftRecord('rec-a5-repair');
        $intent = 'intent-a5-repair';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $final = $this->writeFinal($record->record_id, $staging['sha256'], ['v' => 1]);
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'draft', 'artifact_state' => 'draft', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
            'submitted_json_path' => null, 'submitted_artifact_sha256' => null, 'submission_finalized_at' => null,
        ])->save();

        return [$record->fresh(), $run];
    }

    private function makeStore(): AnnotationArtifactStore
    {
        return new AnnotationArtifactStore;
    }

    private function createUser(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function makeResearchAdmin(): User
    {
        return $this->createUser(User::ROLE_RESEARCH_ADMIN);
    }

    private function makeUnauthorizedActor(): User
    {
        return $this->createUser(User::ROLE_ANNOTATOR);
    }

    private function makeLockTimeoutStore(): AnnotationArtifactStore
    {
        return new class extends AnnotationArtifactStore
        {
            public function withRecordPromotionLock(string $recordId, callable $operation): mixed
            {
                throw new ArtifactLockException('Simulated promotion lock timeout.');
            }
        };
    }

    private function makeCountingStore(): AnnotationArtifactStore
    {
        return new class extends AnnotationArtifactStore
        {
            public int $scanCalls = 0;

            public function inspectRecordArtifacts(string $recordId): array
            {
                $this->scanCalls++;

                return parent::inspectRecordArtifacts($recordId);
            }
        };
    }

    private function makeDraftRecord(string $recordId): array
    {
        $user = User::factory()->create(['role' => User::ROLE_ANNOTATOR]);
        $record = new AnnotationRecord;
        $record->forceFill([
            'record_id' => $recordId, 'created_by_user_id' => $user->id,
            'current_draft_path' => null, 'current_draft_sha256' => null, 'workflow_status' => 'draft', 'artifact_state' => 'draft',
            'schema_version' => '1.0.0', 'recognition_method' => 'human', 'verification_status' => 'unverified',
            'submission_state' => 'not_started', 'is_export_eligible_canonical' => false, 'lock_version' => 0,
        ])->save();
        $record = $record->fresh();

        return [$record, $user];
    }

    private function writeStaging(string $recordId, string $intent, array $payload): array
    {
        return $this->writeFile('submitted/'.$recordId.'/staging/'.$intent.'.json', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function writeFinal(string $recordId, string $sha, array $payload): array
    {
        $info = $this->writeFile('submitted/'.$recordId.'/record.json', json_encode($payload, JSON_UNESCAPED_SLASHES));
        $this->assertSame($sha, $info['sha256']);

        return $info;
    }

    private function writeFile(string $relative, string $content): array
    {
        $abs = $this->artifactRoot.'/'.str_replace('\\', '/', $relative);
        File::ensureDirectoryExists(dirname($abs));
        file_put_contents($abs, $content);

        return ['relative_path' => str_replace('\\', '/', $relative), 'sha256' => hash('sha256', $content)];
    }

    private function makeValidationRun(AnnotationRecord $record, string $intent, string $state, ?string $durablePath, ?string $durableSha, ?string $inputSha, bool $isValid = true): AnnotationValidationRun
    {
        return AnnotationValidationRun::query()->create([
            'run_id' => 'run-'.Str::uuid()->toString(),
            'annotation_record_id' => $record->id,
            'submission_intent_id' => $intent,
            'b1_version' => 'b1',
            'input_artifact_path' => $durablePath ?? 'submitted/'.$record->record_id.'/staging/'.$intent.'.json',
            'input_artifact_sha256' => $inputSha ?? hash('sha256', 'x'),
            'input_evidence_state' => $state,
            'durable_input_evidence_path' => $durablePath,
            'durable_input_evidence_sha256' => $durableSha,
            'status' => 'completed',
            'is_valid' => $isValid,
            'is_export_eligible_canonical' => $isValid,
            'error_count' => $isValid ? 0 : 1,
            'warning_count' => 0,
            'export_block_count' => $isValid ? 0 : 1,
            'timed_out' => false,
            'executed_by_user_id' => $record->created_by_user_id,
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function snapshot(AnnotationArtifactStore $store, AnnotationRecord $record): array
    {
        $inspection = $store->inspectRecordArtifacts((string) $record->record_id);

        return [
            'record' => $record->fresh()->only(['workflow_status', 'artifact_state', 'submission_state', 'submission_intent_id', 'submission_staging_path', 'submission_staging_sha256', 'submitted_json_path', 'submitted_artifact_sha256', 'submission_finalized_at', 'current_draft_path', 'current_draft_sha256']),
            'inspection' => $this->normalizeInspection($inspection),
            'audit_count' => AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count(),
            'run_count' => AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count(),
        ];
    }

    private function normalizeInspection(array $inspection): array
    {
        unset($inspection['promotion_lock']);
        foreach (['draft_versions', 'staging_artifacts', 'promotion_temps'] as $k) {
            foreach (($inspection[$k] ?? []) as $i => $item) {
                unset($inspection[$k][$i]['modified_at']);
            }
        }
        if (isset($inspection['final_artifact'])) {
            unset($inspection['final_artifact']['modified_at']);
        }

        return $inspection;
    }

    private function assertSnapshotUnchanged(AnnotationArtifactStore $store, AnnotationRecord $record, array $before): void
    {
        $this->assertSame($before, $this->snapshot($store, $record));
    }

    private function findFinding(array $report, string $type): array
    {
        foreach ($report['findings'] as $f) {
            if (($f['anomaly_type'] ?? null) === $type) {
                return $f;
            }
        }
        $this->fail('Finding not found: '.$type);
    }

    public function test_a4_conflicting_final_artifact_is_never_overwritten(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-conflict');
        $intent = 'intent-a4-conflict';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $conflicting = $this->writeFile('submitted/'.$record->record_id.'/record.json', json_encode(['v' => 'conflict'], JSON_UNESCAPED_SLASHES));
        $tmp = $this->writeFile('submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('a', 32).'.tmp', json_encode(['v' => 1], JSON_UNESCAPED_SLASHES));
        $this->assertSame($staging['sha256'], $tmp['sha256']);
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'draft', 'artifact_state' => 'draft', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record);
        $svc = new AnnotationReconciliationService($store);
        $report = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);

        $this->assertNotEmpty($report['repairs'], json_encode($report['repairs']));
        $this->assertContains($report['repairs'][0]['status'], ['skipped', 'failed', 'deferred'], json_encode($report['repairs']));
        $this->assertContains($report['repairs'][0]['reason'] ?? '', ['conflicting_final', 'final_hash_mismatch', 'exception', 'intent_or_staging_mismatch']);
        $this->assertSame($conflicting['sha256'], hash_file('sha256', $this->artifactRoot.'/submitted/'.$record->record_id.'/record.json'));
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $fresh = $record->fresh();
        $this->assertNull($fresh->submitted_json_path);
        $this->assertSame('promotion_pending', $fresh->submission_state);
        $this->assertSame($conflicting['sha256'], hash_file('sha256', $this->artifactRoot.'/submitted/'.$record->record_id.'/record.json'));
        $this->assertSame(0, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
    }

    public function test_a5_wrong_submission_intent_is_rejected(): void
    {
        [$record, $run] = $this->makeA5Candidate();
        $record->forceFill(['submission_intent_id' => 'different-intent'])->save();
        $run->forceFill(['submission_intent_id' => 'intent-a5-repair'])->save();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record->fresh());
        $report = (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertTrue(collect($report['repairs'])->every(fn ($r) => in_array($r['status'], ['skipped', 'failed', 'deferred'], true)) || $report['repairs'] === []);
        $fresh = $record->fresh();
        $this->assertSame('promotion_pending', $fresh->submission_state);
        $this->assertNull($fresh->submitted_json_path);
        $this->assertSame($before['inspection'], $this->normalizeInspection($store->inspectRecordArtifacts((string) $record->record_id)));
    }

    public function test_a5_wrong_input_artifact_path_is_rejected(): void
    {
        [$record, $run] = $this->makeA5Candidate();
        $run->forceFill(['input_artifact_path' => 'submitted/'.$record->record_id.'/staging/other.json'])->save();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record->fresh());
        $report = (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertTrue(collect($report['repairs'])->every(fn ($r) => in_array($r['status'], ['skipped', 'failed', 'deferred'], true)) || $report['repairs'] === []);
        $fresh = $record->fresh();
        $this->assertNull($fresh->submitted_json_path);
        $this->assertNotSame('finalized', $fresh->submission_state);
        $this->assertSame($before['inspection'], $this->normalizeInspection($store->inspectRecordArtifacts((string) $record->record_id)));
        $this->assertSame($before['audit_count'], AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
    }

    public function test_a5_wrong_input_artifact_sha256_is_rejected(): void
    {
        [$record, $run] = $this->makeA5Candidate();
        $run->forceFill(['input_artifact_sha256' => hash('sha256', 'wrong')])->save();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record->fresh());
        $report = (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertTrue(collect($report['repairs'])->every(fn ($r) => in_array($r['status'], ['skipped', 'failed', 'deferred'], true)) || $report['repairs'] === []);
        $fresh = $record->fresh();
        $this->assertNull($fresh->submitted_json_path);
        $this->assertNotSame('finalized', $fresh->submission_state);
        $this->assertSame($before['inspection'], $this->normalizeInspection($store->inspectRecordArtifacts((string) $record->record_id)));
        $this->assertSame($before['audit_count'], AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
    }

    public function test_a5_missing_or_ambiguous_validation_evidence_is_rejected(): void
    {
        [$record, $run] = $this->makeA5Candidate();
        // Make run non-correlatable (ambiguous/missing evidence) by breaking intent and path/hash binding.
        $run->forceFill(['submission_intent_id' => 'other-intent', 'input_artifact_path' => 'submitted/'.$record->record_id.'/staging/other-intent.json'])->save();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record->fresh());
        $report = (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertTrue(collect($report['repairs'])->every(fn ($r) => in_array($r['status'], ['skipped', 'failed', 'deferred'], true)) || $report['repairs'] === []);
        $fresh = $record->fresh();
        $this->assertNull($fresh->submitted_json_path);
        $this->assertNotSame('finalized', $fresh->submission_state);
        $this->assertSame($before['inspection'], $this->normalizeInspection($store->inspectRecordArtifacts((string) $record->record_id)));
        $this->assertSame($before['audit_count'], AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
    }

    public function test_apply_mode_lock_timeout_produces_deferred_status_and_zero_mutation(): void
    {
        [$record] = $this->makeA5Candidate();
        $store = $this->makeLockTimeoutStore();
        $inspectionStore = $this->makeStore();
        $before = $this->snapshot($inspectionStore, $record->fresh());
        $report = (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        // Detection is report-only; repair candidate may be deferred by lock timeout when apply is attempted.
        $repairs = $report['repairs'];
        $this->assertTrue($repairs !== [] || $report['findings'] !== []);
        if ($repairs !== []) {
            $this->assertSame('deferred', $repairs[0]['status']);
            $this->assertSame('lock_timeout', $repairs[0]['reason']);
        }
        $this->assertSnapshotUnchanged($inspectionStore, $record, $before);
    }

    public function test_failed_apply_preserves_database_files_audit_and_validation_runs(): void
    {
        [$record, $run] = $this->makeA5Candidate();
        // Corrupt final to force verification/hash mismatch; repair should not mutate DB/audits/runs or overwrite file.
        $finalPath = $this->artifactRoot.'/submitted/'.$record->record_id.'/record.json';
        file_put_contents($finalPath, '{"corrupt":true}');
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record->fresh());
        $beforeHash = hash_file('sha256', $finalPath);
        $report = (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertTrue(collect($report['repairs'])->every(fn ($r) => in_array($r['status'], ['skipped', 'failed', 'deferred'], true)) || $report['repairs'] === []);
        $this->assertSame($beforeHash, hash_file('sha256', $finalPath));
        $fresh = $record->fresh();
        $this->assertNull($fresh->submitted_json_path);
        $this->assertNotSame('finalized', $fresh->submission_state);
        $this->assertSame($before['audit_count'], AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame($before['run_count'], AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_promotion_temp_not_cleaned_before_durable_evidence_commit(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-evidence-order');
        $intent = 'intent-a4-evidence-order';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $tmp = $this->writeFile('submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('b', 32).'.tmp', json_encode(['v' => 1], JSON_UNESCAPED_SLASHES));
        $this->assertSame($staging['sha256'], $tmp['sha256']);
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        // Make run ineligible for evidence update by using mismatched evidence state not updatable? use missing_evidence but still has durable sha/path null; promote method allows missing_evidence.
        // Instead, force evidence update failure by making run belong to different intent after prechecks? Not possible. Use a run that cannot be updated: set input_evidence_state to unexpected state.
        $run->forceFill(['input_evidence_state' => 'finalized_invalid_state'])->save();
        $record->forceFill([
            'workflow_status' => 'draft', 'artifact_state' => 'draft', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $store = $this->makeStore();
        $svc = new AnnotationReconciliationService($store);
        $report = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        // If finalization/evidence update failed, temp must remain.
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $fresh = $record->fresh();
        // Either not finalized or finalized but temp still present because cleanup only after evidence commit; assert temp exists.
        $this->assertTrue(is_file($this->artifactRoot.'/'.$tmp['relative_path']));
    }

    public function test_repeated_successful_apply_is_idempotent(): void
    {
        [$record] = $this->makeA5Candidate();
        $svc = new AnnotationReconciliationService($this->makeStore());
        $first = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $second = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $third = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertNotEmpty($first['repairs']);
        $this->assertTrue(collect($second['repairs'])->every(fn ($r) => in_array($r['status'], ['applied', 'idempotent', 'skipped'], true)));
        $this->assertTrue(collect($third['repairs'])->every(fn ($r) => in_array($r['status'], ['applied', 'idempotent', 'skipped'], true)));
        $fresh = $record->fresh();
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame(1, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
    }

    public function test_interrupted_recovery_can_be_safely_retried(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-interrupted');
        $intent = 'intent-a4-interrupted';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $final = $this->writeFinal($record->record_id, $staging['sha256'], ['v' => 1]);
        $tmp = $this->writeFile('submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('c', 32).'.tmp', json_encode(['v' => 1], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'validated', 'artifact_state' => 'submitted_finalized', 'submission_state' => 'finalized',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
            'submitted_json_path' => $final['relative_path'], 'submitted_artifact_sha256' => $final['sha256'], 'submission_finalized_at' => now(),
        ])->save();
        $svc = new AnnotationReconciliationService($this->makeStore());
        $first = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $second = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertSame('finalized', $record->fresh()->submission_state);
        $this->assertSame('final_artifact', $run->fresh()->input_evidence_state);
        $this->assertTrue(collect($second['repairs'])->every(fn ($r) => in_array($r['status'], ['applied', 'idempotent', 'skipped'], true)) || $second['repairs'] === []);
        // temp may be cleaned by lifecycle reconciliation; it must not block idempotent recovery
        $this->assertFileExists($this->artifactRoot.'/submitted/'.$record->record_id.'/record.json');
    }

    public function test_completed_invalid_b1_run_remains_supported(): void
    {
        [$record] = $this->makeDraftRecord('rec-a5-invalid');
        $intent = 'intent-a5-invalid';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $final = $this->writeFinal($record->record_id, $staging['sha256'], ['v' => 1]);
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['is_valid' => false, 'is_export_eligible_canonical' => false])->save();
        $record->forceFill([
            'workflow_status' => 'draft', 'artifact_state' => 'draft', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $svc = new AnnotationReconciliationService($this->makeStore());
        $report = $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertNotEmpty($report['repairs'], json_encode($report['repairs']));
        $fresh = $record->fresh();
        $this->assertSame('finalized', $fresh->submission_state, json_encode($report['repairs']));
        $this->assertSame('validation_failed', $fresh->workflow_status);
        $this->assertSame(false, (bool) $fresh->latest_b1_valid);
    }

    public function test_infra_failed_run_cannot_authorize_promotion(): void
    {
        [$record] = $this->makeDraftRecord('rec-a4-infra');
        $intent = 'intent-a4-infra';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $tmp = $this->writeFile('submitted/'.$record->record_id.'/.record.json.promote-'.str_repeat('d', 32).'.tmp', json_encode(['v' => 1], JSON_UNESCAPED_SLASHES));
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['status' => 'infra_failed', 'is_valid' => null])->save();
        $record->forceFill([
            'workflow_status' => 'draft', 'artifact_state' => 'draft', 'submission_state' => 'promotion_pending',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
        ])->save();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record->fresh());
        $report = (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertTrue(collect($report['repairs'])->every(fn ($r) => in_array($r['status'], ['skipped', 'failed', 'deferred'], true)));
        $this->assertFileExists($this->artifactRoot.'/'.$tmp['relative_path']);
        $this->assertFileDoesNotExist($this->artifactRoot.'/submitted/'.$record->record_id.'/record.json');
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_no_b1_rerun_occurs_during_reconciliation(): void
    {
        [$record] = $this->makeA5Candidate();
        $svc = new AnnotationReconciliationService($this->makeStore());
        $beforeRuns = AnnotationValidationRun::query()->count();
        $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $svc->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertSame($beforeRuns, AnnotationValidationRun::query()->count());
    }

    public function test_reconciliation_public_api_requires_trusted_principal_and_mode(): void
    {
        $service = new \ReflectionClass(AnnotationReconciliationService::class);
        foreach (['reportRecord', 'reportAll', 'reconcileRecord', 'reconcileAll'] as $method) {
            $params = $service->getMethod($method)->getParameters();
            $names = array_map(fn ($p) => $p->getName(), $params);
            $this->assertContains('principal', $names, $method.' must require principal');
            $this->assertContains('mode', $names, $method.' must require mode');
            $this->assertNotContains('actorUserId', $names, $method.' must not accept raw actor id');
            $this->assertNotContains('apply', $names, $method.' must not accept bool apply');
        }
    }

    public function test_report_methods_reject_apply_before_scan_or_mutation(): void
    {
        [$record] = $this->makeA5Candidate();
        $store = $this->makeCountingStore();
        $svc = new AnnotationReconciliationService($store);
        $principal = $this->adminPrincipal();
        $before = $this->snapshot($this->makeStore(), $record->fresh());

        foreach (['reportRecord', 'reportAll'] as $method) {
            try {
                if ($method === 'reportRecord') {
                    $svc->reportRecord($record->record_id, $principal, ReconciliationMode::APPLY);
                } else {
                    $svc->reportAll($principal, ReconciliationMode::APPLY);
                }
                $this->fail($method.' must reject APPLY');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('APPLY', $e->getMessage());
            }
        }

        $this->assertSame(0, $store->scanCalls);
        $this->assertSame($before['inspection'], $this->normalizeInspection($this->makeStore()->inspectRecordArtifacts((string) $record->record_id)));
        $this->assertSame($before['audit_count'], AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame($before['record']['submission_state'], $record->fresh()->submission_state);
    }

    public function test_report_methods_allow_inspection_modes_without_repairs_or_new_audits(): void
    {
        [$record] = $this->makeA5Candidate();
        $store = $this->makeStore();
        $svc = new AnnotationReconciliationService($store);
        $principal = $this->adminPrincipal();
        $beforeAudit = AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count();
        $before = $this->snapshot($store, $record->fresh());

        foreach ([ReconciliationMode::REPORT_ONLY, ReconciliationMode::AUDIT_WRITING_INSPECTION] as $mode) {
            $single = $svc->reportRecord($record->record_id, $principal, $mode);
            $all = $svc->reportAll($principal, $mode);
            $this->assertSame([], $single['repairs']);
            $this->assertNotEmpty($single['findings']);
            $this->assertSame([], $all['records'][0]['repairs'] ?? []);
            $this->assertSame(0, $all['summary']['repairs_applied']);
        }

        $this->assertSame($beforeAudit, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_reconcile_inspection_modes_do_not_repair_or_write_reconciliation_audits(): void
    {
        [$record] = $this->makeA5Candidate();
        $store = $this->makeStore();
        $svc = new AnnotationReconciliationService($store);
        $principal = $this->adminPrincipal();
        $beforeAudit = AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count();
        $before = $this->snapshot($store, $record->fresh());

        foreach ([ReconciliationMode::REPORT_ONLY, ReconciliationMode::AUDIT_WRITING_INSPECTION] as $mode) {
            $single = $svc->reconcileRecord($record->record_id, $principal, $mode);
            $all = $svc->reconcileAll($principal, $mode);
            $this->assertSame([], $single['repairs']);
            $this->assertNotEmpty($single['findings']);
            $this->assertSame(0, $all['summary']['repairs_applied']);
            $this->assertSame([], $all['records'][0]['repairs'] ?? []);
        }

        $this->assertSame('draft', $record->fresh()->workflow_status);
        $this->assertNull($record->fresh()->submitted_json_path);
        $this->assertSame($beforeAudit, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSnapshotUnchanged($store, $record, $before);
    }

    public function test_only_apply_mode_reaches_repair_methods(): void
    {
        [$record] = $this->makeA5Candidate();
        $svc = new AnnotationReconciliationService($this->makeStore());
        $principal = $this->adminPrincipal();

        $reportOnly = $svc->reconcileRecord($record->record_id, $principal, ReconciliationMode::REPORT_ONLY);
        $this->assertSame([], $reportOnly['repairs']);
        $this->assertNull($record->fresh()->submitted_json_path);

        $inspection = $svc->reconcileRecord($record->record_id, $principal, ReconciliationMode::AUDIT_WRITING_INSPECTION);
        $this->assertSame([], $inspection['repairs']);
        $this->assertNull($record->fresh()->submitted_json_path);

        $applied = $svc->reconcileRecord($record->record_id, $principal, ReconciliationMode::APPLY);
        $this->assertNotEmpty($applied['repairs']);
        $this->assertSame('finalized', $record->fresh()->submission_state);
    }

    public function test_reconciliation_evidence_promotion_requires_trusted_principal_and_preserves_ordinary_lifecycle_behavior(): void
    {
        [$record] = $this->makeDraftRecord('rec-a6-authz');
        $intent = 'intent-a6-authz';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $final = $this->writeFinal($record->record_id, $staging['sha256'], ['v' => 1]);
        $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $record->forceFill([
            'workflow_status' => 'validated',
            'artifact_state' => 'submitted_finalized',
            'submission_state' => 'finalized',
            'submission_intent_id' => $intent,
            'submission_staging_path' => $staging['relative_path'],
            'submission_staging_sha256' => $staging['sha256'],
            'submitted_json_path' => $final['relative_path'],
            'submitted_artifact_sha256' => $final['sha256'],
            'submission_finalized_at' => now(),
        ])->save();

        $lifecycle = app(AnnotationRecordLifecycleService::class);
        $this->assertFalse((new \ReflectionMethod($lifecycle, 'promoteValidationRunEvidenceToFinalArtifact'))->isPublic());
        $this->assertTrue((new \ReflectionMethod($lifecycle, 'promoteFinalizedValidationRunEvidenceForReconciliation'))->isPublic());

        $admin = $this->makeResearchAdmin();
        $principal = $this->principalFor($admin);
        $admin->forceFill(['role' => User::ROLE_ANNOTATOR])->save();

        try {
            $lifecycle->promoteFinalizedValidationRunEvidenceForReconciliation($record->fresh(), $run->fresh(), $principal);
            $this->fail('Expected authorization exception.');
        } catch (AnnotationLifecycleAuthorizationException $e) {
            $this->assertStringContainsString('research_admin', strtolower($e->getMessage()));
        }

        $runFresh = $run->fresh();
        $this->assertSame('staged_path', $runFresh->input_evidence_state);
        $this->assertSame($staging['relative_path'], $runFresh->durable_input_evidence_path);

        $authorizedAdmin = $this->makeResearchAdmin();
        $authorizedPrincipal = $this->principalFor($authorizedAdmin);
        $this->assertTrue($lifecycle->promoteFinalizedValidationRunEvidenceForReconciliation($record->fresh(), $run->fresh(), $authorizedPrincipal));
        $this->assertSame('final_artifact', $run->fresh()->input_evidence_state);
    }

    public function test_reconciliation_factory_rejects_non_research_admin_users(): void
    {
        $factory = new TrustedReconciliationPrincipalFactory;
        $annotator = User::factory()->create(['role' => User::ROLE_ANNOTATOR]);
        $reviewer = User::factory()->create(['role' => User::ROLE_REVIEWER]);

        foreach ([$annotator, $reviewer] as $user) {
            try {
                $factory->make($user);
                $this->fail('Expected authorization exception for role '.$user->role);
            } catch (AnnotationLifecycleAuthorizationException $e) {
                $this->assertStringContainsString('research_admin', strtolower($e->getMessage()));
            }
        }
    }

    public function test_reconciliation_principal_cannot_authorize_after_role_downgrade(): void
    {
        [$record] = $this->makeA5Candidate();
        $admin = $this->makeResearchAdmin();
        $principal = $this->principalFor($admin);
        $admin->forceFill(['role' => User::ROLE_ANNOTATOR])->save();
        $store = $this->makeCountingStore();
        $before = $this->snapshot($this->makeStore(), $record->fresh());

        $this->expectException(AnnotationLifecycleAuthorizationException::class);
        try {
            (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $principal, ReconciliationMode::APPLY);
        } finally {
            $this->assertSame(0, $store->scanCalls);
            $this->assertNull($record->fresh()->submitted_json_path);
            $this->assertSame($before['audit_count'], AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
            $this->assertSame($before['inspection'], $this->normalizeInspection($this->makeStore()->inspectRecordArtifacts((string) $record->record_id)));
        }
    }

    public function test_all_public_methods_require_current_research_admin_authorization(): void
    {
        [$record] = $this->makeA5Candidate();
        $admin = $this->makeResearchAdmin();
        $principal = $this->principalFor($admin);
        $admin->forceFill(['role' => User::ROLE_ANNOTATOR])->save();
        $store = $this->makeCountingStore();
        $svc = new AnnotationReconciliationService($store);
        $before = $this->snapshot($this->makeStore(), $record->fresh());

        $calls = [
            fn () => $svc->reportRecord($record->record_id, $principal, ReconciliationMode::REPORT_ONLY),
            fn () => $svc->reportAll($principal, ReconciliationMode::REPORT_ONLY),
            fn () => $svc->reconcileRecord($record->record_id, $principal, ReconciliationMode::REPORT_ONLY),
            fn () => $svc->reconcileAll($principal, ReconciliationMode::REPORT_ONLY),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected authorization exception.');
            } catch (AnnotationLifecycleAuthorizationException $e) {
                $this->assertStringContainsString('research_admin', strtolower($e->getMessage()));
            }
        }

        $this->assertSame(0, $store->scanCalls);
        $this->assertSame($before['audit_count'], AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame($before['inspection'], $this->normalizeInspection($this->makeStore()->inspectRecordArtifacts((string) $record->record_id)));
        $this->assertNull($record->fresh()->submitted_json_path);
    }

    public function test_reconciliation_allows_research_admin_and_records_user_audit_attribution(): void
    {
        [$record] = $this->makeA5Candidate();
        $admin = $this->makeResearchAdmin();
        $svc = new AnnotationReconciliationService($this->makeStore());
        $report = $svc->reconcileRecord($record->record_id, $this->principalFor($admin), ReconciliationMode::APPLY);
        $this->assertNotEmpty($report['repairs']);
        $this->assertContains($report['repairs'][0]['status'], ['applied', 'idempotent']);
        $fresh = $record->fresh();
        $this->assertSame('finalized', $fresh->submission_state);
        $audit = AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('user', $audit->actor_type);
        $this->assertSame($admin->id, $audit->actor_user_id);
    }

    public function test_missing_evidence_is_not_restored_to_final_artifact_by_reconciliation(): void
    {
        [$record] = $this->makeDraftRecord('rec-a6-missing');
        $intent = 'intent-a6-missing';
        $staging = $this->writeStaging($record->record_id, $intent, ['v' => 1]);
        $final = $this->writeFinal($record->record_id, $staging['sha256'], ['v' => 1]);
        $run = $this->makeValidationRun($record, $intent, 'missing_evidence', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $run->forceFill(['durable_input_evidence_path' => null, 'durable_input_evidence_sha256' => null])->save();
        $record->forceFill([
            'workflow_status' => 'validated', 'artifact_state' => 'submitted_finalized', 'submission_state' => 'finalized',
            'submission_intent_id' => $intent, 'submission_staging_path' => $staging['relative_path'], 'submission_staging_sha256' => $staging['sha256'],
            'submitted_json_path' => $final['relative_path'], 'submitted_artifact_sha256' => $final['sha256'], 'submission_finalized_at' => now(),
        ])->save();
        $store = $this->makeStore();
        $before = $this->snapshot($store, $record->fresh());
        $report = (new AnnotationReconciliationService($store))->reconcileRecord($record->record_id, $this->adminPrincipal(), ReconciliationMode::APPLY);
        $this->assertTrue(collect($report['repairs'])->every(fn ($r) => in_array($r['status'], ['skipped', 'failed', 'deferred', 'idempotent'], true)) || $report['repairs'] === []);
        $run = $run->fresh();
        $this->assertSame('missing_evidence', $run->input_evidence_state);
        $this->assertNull($run->durable_input_evidence_path);
        $this->assertNull($run->durable_input_evidence_sha256);
        $this->assertSame($staging['relative_path'], $run->input_artifact_path);
        $this->assertSame($staging['sha256'], $run->input_artifact_sha256);
        $this->assertSame($before['inspection'], $this->normalizeInspection($store->inspectRecordArtifacts((string) $record->record_id)));
    }

    public function test_reconciliation_finalization_parity_completed_valid_and_invalid_and_idempotent_retry(): void
    {
        foreach ([true, false] as $isValid) {
            [$record] = $this->makeDraftRecord($isValid ? 'rec-parity-valid' : 'rec-parity-invalid');
            $intent = $isValid ? 'intent-parity-valid' : 'intent-parity-invalid';
            $staging = $this->writeStaging($record->record_id, $intent, ['v' => $isValid ? 1 : 2]);
            $final = $this->writeFinal($record->record_id, $staging['sha256'], ['v' => $isValid ? 1 : 2]);
            $run = $this->makeValidationRun($record, $intent, 'staged_path', $staging['relative_path'], $staging['sha256'], $staging['sha256'], $isValid);
            $record->forceFill([
                'workflow_status' => 'draft',
                'artifact_state' => 'draft',
                'submission_state' => 'b1_completed',
                'submission_intent_id' => $intent,
                'submission_staging_path' => $staging['relative_path'],
                'submission_staging_sha256' => $staging['sha256'],
            ])->save();

            $admin = $this->makeResearchAdmin();
            $principal = $this->principalFor($admin);
            $svc = new AnnotationReconciliationService($this->makeStore());
            $first = $svc->reconcileRecord($record->record_id, $principal, ReconciliationMode::APPLY);
            $this->assertNotEmpty($first['repairs'], json_encode($first));
            $fresh = $record->fresh();
            $expectedWorkflow = $isValid ? 'validated' : 'validation_failed';
            $this->assertSame('finalized', $fresh->submission_state, json_encode($first['repairs'] ?? []));
            $this->assertSame('submitted_finalized', $fresh->artifact_state);
            $this->assertSame($expectedWorkflow, $fresh->workflow_status);
            $this->assertSame($final['relative_path'], $fresh->submitted_json_path);
            $this->assertSame($final['sha256'], $fresh->submitted_artifact_sha256);
            $this->assertSame($admin->id, $fresh->submitted_by_user_id);
            $this->assertNotNull($fresh->submitted_at);
            $this->assertNotNull($fresh->submission_finalized_at);
            $this->assertSame($run->id, $fresh->latest_validation_run_id);
            $this->assertSame($isValid, (bool) $fresh->latest_b1_valid);
            $this->assertGreaterThanOrEqual(2, (int) $fresh->lock_version);
            $runFresh = $run->fresh();
            $this->assertSame('final_artifact', $runFresh->input_evidence_state);
            $this->assertSame($final['relative_path'], $runFresh->durable_input_evidence_path);
            $this->assertSame($final['sha256'], $runFresh->durable_input_evidence_sha256);

            $second = $svc->reconcileRecord($record->record_id, $principal, ReconciliationMode::APPLY);
            $this->assertTrue(collect($second['repairs'])->every(fn ($r) => in_array($r['status'], ['applied', 'idempotent', 'skipped'], true)) || $second['repairs'] === []);
            $after = $record->fresh();
            $this->assertSame($fresh->lock_version, $after->lock_version);
            $this->assertSame($fresh->submitted_artifact_sha256, $after->submitted_artifact_sha256);
            $this->assertSame($fresh->workflow_status, $after->workflow_status);
            $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
        }
    }
}
