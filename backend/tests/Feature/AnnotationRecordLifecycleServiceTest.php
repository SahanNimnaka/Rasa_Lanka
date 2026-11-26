<?php

namespace Tests\Feature;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\AnnotationValidationRun;
use App\Models\User;
use App\Services\Annotation\AnnotationRecordLifecycleService;
use App\Services\Annotation\AnnotationValidationPersistenceService;
use App\Services\Annotation\B1ProcessRunner;
use App\Services\Annotation\B1ValidationAdapter;
use App\Services\Annotation\B1ValidationResult;
use App\Services\Annotation\Exceptions\AnnotationLifecycleAuthorizationException;
use App\Services\Annotation\Exceptions\AnnotationLifecycleConflictException;
use App\Services\Annotation\Exceptions\AnnotationLifecycleValidationException;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
use App\Services\Annotation\Storage\Exceptions\ArtifactLockException;
use App\Services\Annotation\Storage\Exceptions\ArtifactStorageException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AnnotationRecordLifecycleServiceTest extends TestCase
{
    private string $artifactRoot;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);

        $this->artifactRoot = storage_path('framework/testing/annotation-lifecycle-'.str_replace('.', '-', uniqid('', true)));
        File::deleteDirectory($this->artifactRoot);
        File::ensureDirectoryExists($this->artifactRoot);
        $this->configureStore($this->artifactRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->artifactRoot);
        parent::tearDown();
    }

    public function test_create_draft_persists_initial_state_artifact_and_audit(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        $result = $service->createDraft($actor, ['label' => 'ginger', 'images' => ['IMG-1']], 'rec-001');

        $record = $result['record'];
        $this->assertInstanceOf(AnnotationRecord::class, $record);
        $this->assertSame('rec-001', $record->record_id);
        $this->assertSame('draft', $record->workflow_status);
        $this->assertSame('draft', $record->artifact_state);
        $this->assertSame('none', $record->submission_state);
        $this->assertSame(1, $record->lock_version);
        $this->assertSame($actor->id, $record->created_by_user_id);
        $this->assertSame($result['artifact']['draft_version_id'], $record->current_draft_version_id);
        $this->assertSame($result['artifact']['relative_path'], $record->current_draft_path);
        $this->assertSame($result['artifact']['sha256'], $record->current_draft_sha256);

        $this->assertFileExists($this->artifactRoot.'/'.$result['artifact']['relative_path']);
        $this->assertSame(
            $result['artifact']['sha256'],
            hash_file('sha256', $this->artifactRoot.'/'.$result['artifact']['relative_path'])
        );

        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'actor_user_id' => $actor->id,
            'actor_type' => 'user',
            'action' => 'created',
            'to_workflow_status' => 'draft',
        ]);
    }

    public function test_save_draft_writes_new_version_increments_lock_and_preserves_old_version(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $created = $service->createDraft($actor, ['v' => 1], 'rec-002');
        $record = $created['record'];
        $oldPath = $this->artifactRoot.'/'.$record->current_draft_path;
        $oldSha = $record->current_draft_sha256;
        $oldVersion = $record->current_draft_version_id;

        $saved = $service->saveDraft($record, $actor, 1, ['v' => 2]);
        $updated = $saved['record'];

        $this->assertSame('draft', $updated->workflow_status);
        $this->assertSame(2, $updated->lock_version);
        $this->assertNotSame($oldVersion, $updated->current_draft_version_id);
        $this->assertNotSame($oldSha, $updated->current_draft_sha256);
        $this->assertFileExists($oldPath);
        $this->assertSame($oldSha, hash_file('sha256', $oldPath));
        $this->assertFileExists($this->artifactRoot.'/'.$updated->current_draft_path);
        $this->assertSame($updated->current_draft_sha256, hash_file('sha256', $this->artifactRoot.'/'.$updated->current_draft_path));

        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'draft_saved',
            'from_workflow_status' => 'draft',
            'to_workflow_status' => 'draft',
        ]);
    }

    public function test_save_draft_rejects_stale_lock_version_without_overwriting_current_draft(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $created = $service->createDraft($actor, ['v' => 1], 'rec-003');
        $record = $created['record'];
        $currentPath = $record->current_draft_path;
        $currentSha = $record->current_draft_sha256;
        $currentVersion = $record->current_draft_version_id;

        $service->saveDraft($record->fresh(), $actor, 1, ['v' => 2]);

        try {
            $service->saveDraft($record->fresh(), $actor, 1, ['v' => 3]);
            $this->fail('Expected stale lock conflict.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('Stale lock_version', $e->getMessage());
        }

        $fresh = $record->fresh();
        $this->assertSame(2, $fresh->lock_version);
        $this->assertNotSame($currentVersion, $fresh->current_draft_version_id);
        $this->assertNotSame($currentPath, $fresh->current_draft_path);
        $this->assertNotSame($currentSha, $fresh->current_draft_sha256);
        $this->assertDatabaseCount('annotation_review_actions', 2); // created + one successful save
    }

    public function test_save_draft_rejects_non_draft_record(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $created = $service->createDraft($actor, ['v' => 1], 'rec-004');
        $record = $created['record'];
        $record->forceFill(['workflow_status' => 'validated', 'artifact_state' => 'submitted_finalized'])->save();

        $this->expectException(AnnotationLifecycleConflictException::class);
        $service->saveDraft($record->fresh(), $actor, 1, ['v' => 2]);
    }

    public function test_unauthorized_discard_is_rejected(): void
    {
        $owner = $this->annotationUser();
        $other = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-005')['record'];

        $this->expectException(AnnotationLifecycleAuthorizationException::class);
        $service->discardDraft($record, $other, 1, 'cleanup');
    }

    public function test_reviewer_cannot_discard_another_users_draft(): void
    {
        $owner = $this->annotationUser();
        $reviewer = $this->annotationUser(User::ROLE_REVIEWER);
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-006')['record'];

        $this->expectException(AnnotationLifecycleAuthorizationException::class);
        $service->discardDraft($record, $reviewer, 1, 'reviewer should not discard');
    }

    public function test_empty_discard_reason_is_rejected(): void
    {
        $owner = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-007')['record'];

        $this->expectException(AnnotationLifecycleValidationException::class);
        $service->discardDraft($record, $owner, 1, '   ');
    }

    public function test_owner_can_discard_draft_with_audit(): void
    {
        $owner = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-008')['record'];

        $discarded = $service->discardDraft($record, $owner, 1, 'no longer needed');

        $this->assertSame('discarded', $discarded->workflow_status);
        $this->assertSame('no longer needed', $discarded->discard_reason);
        $this->assertNotNull($discarded->discarded_at);
        $this->assertSame(2, $discarded->lock_version);
        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'draft_discarded',
            'from_workflow_status' => 'draft',
            'to_workflow_status' => 'discarded',
            'comment' => 'no longer needed',
        ]);
    }

    public function test_research_admin_can_discard_draft(): void
    {
        $owner = $this->annotationUser();
        $admin = $this->annotationUser(User::ROLE_RESEARCH_ADMIN);
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-009')['record'];

        $discarded = $service->discardDraft($record, $admin, 1, 'admin cleanup');

        $this->assertSame('discarded', $discarded->workflow_status);
        $this->assertSame($admin->id, AnnotationReviewAction::query()->where('action', 'draft_discarded')->value('actor_user_id'));
    }

    public function test_discarded_record_cannot_be_edited_or_discarded_again(): void
    {
        $owner = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-010')['record'];
        $discarded = $service->discardDraft($record, $owner, 1, 'done');

        try {
            $service->saveDraft($discarded, $owner, 2, ['v' => 2]);
            $this->fail('Expected save on discarded record to fail.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('draft', strtolower($e->getMessage()));
        }

        try {
            $service->discardDraft($discarded->fresh(), $owner, 2, 'again');
            $this->fail('Expected second discard to fail.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('draft', strtolower($e->getMessage()));
        }
    }

    public function test_filesystem_failure_during_create_leaves_database_unchanged(): void
    {
        $actor = $this->annotationUser();
        $store = new class($this->artifactRoot) extends AnnotationArtifactStore
        {
            public function __construct(string $root)
            {
                $this->configure($root);
                parent::__construct();
            }

            private function configure(string $root): void
            {
                config([
                    'filesystems.disks.annotation' => [
                        'driver' => 'local',
                        'root' => $root,
                        'throw' => false,
                    ],
                    'annotation.annotation_storage_disk' => 'annotation',
                    'annotation.annotation_storage_driver' => 'local',
                ]);
            }

            public function writeDraftVersion(string $recordId, string $content, ?string $draftVersionId = null): array
            {
                throw new ArtifactStorageException('simulated filesystem failure');
            }
        };

        $service = new AnnotationRecordLifecycleService($store);

        try {
            $service->createDraft($actor, ['v' => 1], 'rec-011');
            $this->fail('Expected filesystem failure.');
        } catch (ArtifactStorageException $e) {
            $this->assertSame('simulated filesystem failure', $e->getMessage());
        }

        $this->assertDatabaseCount('annotation_records', 0);
        $this->assertDatabaseCount('annotation_review_actions', 0);
    }

    public function test_db_failure_after_artifact_write_leaves_previous_current_draft_unchanged(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $created = $service->createDraft($actor, ['v' => 1], 'rec-012');
        $record = $created['record'];
        $beforePath = $record->current_draft_path;
        $beforeSha = $record->current_draft_sha256;
        $beforeVersion = $record->current_draft_version_id;
        $beforeLock = $record->lock_version;

        AnnotationReviewAction::creating(function (AnnotationReviewAction $action): void {
            if ($action->action === 'draft_saved') {
                throw new \RuntimeException('simulated audit insert failure');
            }
        });

        try {
            $service->saveDraft($record->fresh(), $actor, 1, ['v' => 2]);
            $this->fail('Expected DB failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated audit insert failure', $e->getMessage());
        } finally {
            AnnotationReviewAction::clearBootedModels();
        }

        $fresh = $record->fresh();
        $this->assertSame($beforePath, $fresh->current_draft_path);
        $this->assertSame($beforeSha, $fresh->current_draft_sha256);
        $this->assertSame($beforeVersion, $fresh->current_draft_version_id);
        $this->assertSame($beforeLock, $fresh->lock_version);
        $this->assertFileExists($this->artifactRoot.'/'.$beforePath);
        $this->assertDatabaseCount('annotation_review_actions', 1); // only created
    }

    public function test_audit_insert_failure_rolls_back_discard_mutation(): void
    {
        $owner = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-013')['record'];

        AnnotationReviewAction::creating(function (AnnotationReviewAction $action): void {
            if ($action->action === 'draft_discarded') {
                throw new \RuntimeException('simulated discard audit failure');
            }
        });

        try {
            $service->discardDraft($record->fresh(), $owner, 1, 'reason');
            $this->fail('Expected discard failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated discard audit failure', $e->getMessage());
        } finally {
            AnnotationReviewAction::clearBootedModels();
        }

        $fresh = $record->fresh();
        $this->assertSame('draft', $fresh->workflow_status);
        $this->assertNull($fresh->discarded_at);
        $this->assertNull($fresh->discard_reason);
        $this->assertSame(1, $fresh->lock_version);
    }

    public function test_no_filesystem_operation_runs_inside_db_transaction_during_save(): void
    {
        $actor = $this->annotationUser();
        $levels = [];
        $store = $this->makeInstrumentedStore($levels);
        $service = new AnnotationRecordLifecycleService($store);
        $record = $service->createDraft($actor, ['v' => 1], 'rec-014')['record'];
        $levels = []; // reset after create

        $service->saveDraft($record->fresh(), $actor, 1, ['v' => 2]);

        $this->assertNotEmpty($levels);
        foreach ($levels as $level) {
            $this->assertSame(0, $level, 'Filesystem artifact operations must not run inside DB transaction.');
        }
    }

    public function test_create_draft_does_not_run_filesystem_operations_inside_db_transaction(): void
    {
        $actor = $this->annotationUser();
        $levels = [];
        $store = $this->makeInstrumentedStore($levels);
        $service = new AnnotationRecordLifecycleService($store);

        $service->createDraft($actor, ['v' => 1], 'rec-015');

        $this->assertNotEmpty($levels);
        foreach ($levels as $level) {
            $this->assertSame(0, $level);
        }
    }

    public function test_research_admin_cannot_save_another_users_draft(): void
    {
        $owner = $this->annotationUser();
        $admin = $this->annotationUser(User::ROLE_RESEARCH_ADMIN);
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-016')['record'];
        $beforePath = $record->current_draft_path;
        $beforeSha = $record->current_draft_sha256;
        $beforeVersion = $record->current_draft_version_id;
        $beforeLock = $record->lock_version;
        $artifactCount = count(File::allFiles($this->artifactRoot.'/drafts/rec-016/versions'));

        try {
            $service->saveDraft($record->fresh(), $admin, 1, ['v' => 2]);
            $this->fail('Expected authorization exception.');
        } catch (AnnotationLifecycleAuthorizationException $e) {
            $this->assertStringContainsString('Only the draft owner may edit', $e->getMessage());
        }

        $fresh = $record->fresh();
        $this->assertSame($beforePath, $fresh->current_draft_path);
        $this->assertSame($beforeSha, $fresh->current_draft_sha256);
        $this->assertSame($beforeVersion, $fresh->current_draft_version_id);
        $this->assertSame($beforeLock, $fresh->lock_version);
        $this->assertCount($artifactCount, File::allFiles($this->artifactRoot.'/drafts/rec-016/versions'));
        $this->assertDatabaseMissing('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'draft_saved',
        ]);
    }

    public function test_unauthorized_save_by_non_owner_non_admin_is_rejected(): void
    {
        $owner = $this->annotationUser();
        $other = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-017')['record'];

        $this->expectException(AnnotationLifecycleAuthorizationException::class);
        $service->saveDraft($record->fresh(), $other, 1, ['v' => 2]);
    }

    public function test_discard_rejects_stale_lock_version(): void
    {
        $owner = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-018')['record'];
        $service->saveDraft($record->fresh(), $owner, 1, ['v' => 2]);

        try {
            $service->discardDraft($record->fresh(), $owner, 1, 'stale');
            $this->fail('Expected stale lock conflict.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('Stale lock_version', $e->getMessage());
        }

        $fresh = $record->fresh();
        $this->assertSame('draft', $fresh->workflow_status);
        $this->assertSame(2, $fresh->lock_version);
        $this->assertNull($fresh->discarded_at);
    }

    public function test_discard_rejects_submission_in_progress_states(): void
    {
        $owner = $this->annotationUser();
        $service = $this->makeService();

        foreach (['staging_written', 'b1_completed', 'promotion_pending'] as $idx => $state) {
            $record = $service->createDraft($owner, ['v' => 1], 'rec-019-'.$idx)['record'];
            $record->forceFill(['submission_state' => $state])->save();

            try {
                $service->discardDraft($record->fresh(), $owner, 1, 'reason');
                $this->fail('Expected conflict for state '.$state);
            } catch (AnnotationLifecycleConflictException $e) {
                $this->assertStringContainsString('submission intent is in progress', $e->getMessage());
            }
        }
    }

    public function test_owner_can_discard_draft_after_b1_infra_failure_with_audit(): void
    {
        $owner = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-020')['record'];
        $record->forceFill(['submission_state' => 'b1_infra_failed'])->save();

        $discarded = $service->discardDraft($record->fresh(), $owner, 1, 'infra failed, abandoning');

        $this->assertSame('discarded', $discarded->workflow_status);
        $this->assertSame(2, $discarded->lock_version);
        $this->assertNotNull($discarded->discarded_at);
        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'draft_discarded',
            'comment' => 'infra failed, abandoning',
            'from_workflow_status' => 'draft',
            'to_workflow_status' => 'discarded',
        ]);
        $this->assertDatabaseHas('annotation_records', ['id' => $record->id]);
    }

    public function test_duplicate_record_id_is_rejected_before_corrupting_existing_namespace(): void
    {
        $owner = $this->annotationUser();
        $service = $this->makeService();
        $first = $service->createDraft($owner, ['v' => 1], 'rec-021')['record'];
        $existingFiles = collect(File::allFiles($this->artifactRoot.'/drafts/rec-021/versions'))->map(fn ($f) => $f->getFilename())->all();

        try {
            $service->createDraft($owner, ['v' => 2], 'rec-021');
            $this->fail('Expected duplicate record_id conflict.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('record_id already exists', $e->getMessage());
        }

        $afterFiles = collect(File::allFiles($this->artifactRoot.'/drafts/rec-021/versions'))->map(fn ($f) => $f->getFilename())->all();
        $this->assertSame($existingFiles, $afterFiles);
        $this->assertDatabaseCount('annotation_records', 1);
        $this->assertSame($first->id, AnnotationRecord::query()->where('record_id', 'rec-021')->value('id'));
    }

    public function test_concurrent_record_id_collision_is_translated_to_domain_conflict(): void
    {
        $owner = $this->annotationUser();
        $this->configureStore($this->artifactRoot);
        $levels = [];
        $store = new class($this->artifactRoot) extends AnnotationArtifactStore
        {
            public function __construct(private string $testRoot)
            {
                config([
                    'filesystems.disks.annotation' => ['driver' => 'local', 'root' => $testRoot, 'throw' => false],
                    'annotation.annotation_storage_disk' => 'annotation',
                    'annotation.annotation_storage_driver' => 'local',
                ]);
                parent::__construct();
            }

            public function writeDraftVersion(string $recordId, string $content, ?string $draftVersionId = null): array
            {
                $result = parent::writeDraftVersion($recordId, $content, $draftVersionId);
                // Simulate concurrent insert after artifact write but before service DB tx.
                if ($recordId === 'rec-022' && ! AnnotationRecord::query()->where('record_id', 'rec-022')->exists()) {
                    $user = User::factory()->create();
                    $r = new AnnotationRecord;
                    $r->record_id = 'rec-022';
                    $r->forceFill([
                        'schema_version' => 'human-recognition-v1',
                        'recognition_method' => 'individual',
                        'verification_status' => 'pending_confirmation',
                        'workflow_status' => 'draft',
                        'artifact_state' => 'draft',
                        'submission_state' => 'none',
                        'created_by_user_id' => $user->id,
                        'is_export_eligible_canonical' => false,
                        'lock_version' => 1,
                    ]);
                    $r->save();
                }

                return $result;
            }
        };

        $service = new AnnotationRecordLifecycleService($store);

        try {
            $service->createDraft($owner, ['v' => 1], 'rec-022');
            $this->fail('Expected conflict.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('record_id already exists', $e->getMessage());
        }

        $this->assertDatabaseCount('annotation_records', 1);
    }

    public function test_create_db_failure_after_artifact_write_leaves_no_db_rows(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        AnnotationReviewAction::creating(function (AnnotationReviewAction $action): void {
            if ($action->action === 'created') {
                throw new \RuntimeException('simulated create audit failure');
            }
        });

        try {
            $service->createDraft($actor, ['v' => 1], 'rec-023');
            $this->fail('Expected DB failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated create audit failure', $e->getMessage());
        } finally {
            AnnotationReviewAction::clearBootedModels();
        }

        $this->assertDatabaseCount('annotation_records', 0);
        $this->assertDatabaseCount('annotation_review_actions', 0);
        // Orphan draft artifact may remain for reconciliation; this is acceptable per spec.
        $this->assertDirectoryExists($this->artifactRoot.'/drafts/rec-023/versions');
    }

    public function test_invalid_verification_status_is_rejected(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        $this->expectException(AnnotationLifecycleValidationException::class);
        $service->createDraft($actor, ['v' => 1], 'rec-024', 'bogus_status');
    }

    public function test_invalid_recognition_method_is_rejected(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        $this->expectException(AnnotationLifecycleValidationException::class);
        $service->createDraft($actor, ['v' => 1], 'rec-025', 'pending_confirmation', 'bogus_method');
    }

    public function test_create_and_save_reject_user_without_annotation_role(): void
    {
        $user = User::factory()->create(); // default role null/none
        $user->forceFill(['role' => 'viewer'])->save();
        $user = $user->fresh();
        $service = $this->makeService();

        try {
            $service->createDraft($user, ['v' => 1], 'rec-026');
            $this->fail('Expected auth failure.');
        } catch (AnnotationLifecycleAuthorizationException $e) {
            $this->assertStringContainsString('not authorized', $e->getMessage());
        }

        $owner = $this->annotationUser();
        $record = $service->createDraft($owner, ['v' => 1], 'rec-027')['record'];
        $this->expectException(AnnotationLifecycleAuthorizationException::class);
        $service->saveDraft($record->fresh(), $user, 1, ['v' => 2]);
    }

    public function test_unsupported_driver_fails_clearly(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        $original = DB::getDefaultConnection();
        config(['database.connections.sqlsrv_fake' => [
            'driver' => 'sqlsrv',
            'host' => '127.0.0.1',
            'database' => 'fake',
            'username' => 'fake',
            'password' => 'fake',
        ]]);
        DB::purge('sqlsrv_fake');
        DB::setDefaultConnection('sqlsrv_fake');

        try {
            $service->createDraft($actor, ['v' => 1], 'rec-028');
            $this->fail('Expected unsupported driver exception.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('Unsupported database driver', $e->getMessage());
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    private function makeService(): AnnotationRecordLifecycleService
    {
        return new AnnotationRecordLifecycleService($this->makeStore());
    }

    private function makeStore(): AnnotationArtifactStore
    {
        $this->configureStore($this->artifactRoot);

        return new AnnotationArtifactStore;
    }

    /**
     * @param  list<int>  $levels
     */
    private function makeInstrumentedStore(array &$levels): AnnotationArtifactStore
    {
        $this->configureStore($this->artifactRoot);

        return new class($levels) extends AnnotationArtifactStore
        {
            /** @var list<int> */
            public array $txLevels;

            /**
             * @param  list<int>  $levels
             */
            public function __construct(array &$levels)
            {
                parent::__construct();
                $this->txLevels = &$levels;
            }

            public function writeDraftVersion(string $recordId, string $content, ?string $draftVersionId = null): array
            {
                $this->txLevels[] = DB::transactionLevel();

                return parent::writeDraftVersion($recordId, $content, $draftVersionId);
            }

            public function verifyStoredArtifact(string $relativePath, string $expectedSha256): array
            {
                $this->txLevels[] = DB::transactionLevel();

                return parent::verifyStoredArtifact($relativePath, $expectedSha256);
            }
        };
    }

    private function configureStore(string $root): void
    {
        config([
            'filesystems.disks.annotation' => [
                'driver' => 'local',
                'root' => $root,
                'throw' => false,
            ],
            'annotation.annotation_storage_disk' => 'annotation',
            'annotation.annotation_storage_driver' => 'local',
        ]);
    }

    public function test_create_submission_intent_from_none_writes_exact_staging_and_persists_intent(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $payload = $this->validIndividualDraftPayload('rec-100');
        $record = $service->createDraft($actor, $payload, 'rec-100')['record'];

        $result = $service->createSubmissionIntent($record->fresh(), $actor, 1);

        $updated = $result['record'];
        $this->assertSame('staging_written', $updated->submission_state);
        $this->assertSame('draft', $updated->workflow_status);
        $this->assertSame('draft', $updated->artifact_state);
        $this->assertSame(2, $updated->lock_version);
        $this->assertNotNull($updated->submission_intent_id);
        $this->assertStringStartsWith('si-', $updated->submission_intent_id);
        $this->assertSame($result['submission_intent_id'], $updated->submission_intent_id);
        $this->assertSame($result['staging']['relative_path'], $updated->submission_staging_path);
        $this->assertSame($result['candidate_sha256'], $updated->submission_staging_sha256);
        $this->assertNotNull($updated->submission_started_at);
        $this->assertNull($updated->submitted_json_path);
        $this->assertNull($updated->submitted_at);
        $this->assertNull($updated->submission_finalized_at);

        $expectedPath = 'submitted/rec-100/staging/'.$updated->submission_intent_id.'.json';
        $this->assertSame($expectedPath, $updated->submission_staging_path);
        $this->assertFileExists($this->artifactRoot.'/'.$expectedPath);

        $bytes = File::get($this->artifactRoot.'/'.$expectedPath);
        $this->assertSame(hash('sha256', $bytes), $updated->submission_staging_sha256);
        $this->assertSame($bytes, $this->expectedCandidateJson($record->fresh(), $payload));

        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'submission_intent_created',
            'from_workflow_status' => 'draft',
            'to_workflow_status' => 'draft',
        ]);
        $this->assertDatabaseCount('annotation_validation_runs', 0);
        $this->assertDatabaseCount('annotation_validation_issues', 0);
    }

    public function test_create_submission_intent_rejects_unauthorized_role(): void
    {
        $owner = $this->annotationUser();
        $viewer = User::factory()->create();
        $viewer->forceFill(['role' => 'viewer'])->save();
        $viewer = $viewer->fresh();
        $service = $this->makeService();
        $record = $service->createDraft($owner, $this->validIndividualDraftPayload('rec-101'), 'rec-101')['record'];

        $this->expectException(AnnotationLifecycleAuthorizationException::class);
        $service->createSubmissionIntent($record->fresh(), $viewer, 1);
    }

    public function test_create_submission_intent_allows_reviewer_owner_per_spec_matrix(): void
    {
        $reviewer = $this->annotationUser(User::ROLE_REVIEWER);
        $service = $this->makeService();
        $record = $service->createDraft($reviewer, $this->validIndividualDraftPayload('rec-102'), 'rec-102')['record'];

        $result = $service->createSubmissionIntent($record->fresh(), $reviewer, 1);

        $this->assertSame('staging_written', $result['record']->submission_state);
        $this->assertSame($reviewer->id, AnnotationReviewAction::query()->where('action', 'submission_intent_created')->value('actor_user_id'));
    }

    public function test_create_submission_intent_rejects_stale_lock_version(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-103'), 'rec-103')['record'];
        $service->saveDraft($record->fresh(), $actor, 1, $this->validIndividualDraftPayload('rec-103', ['notes' => 'updated']));

        $this->expectException(AnnotationLifecycleConflictException::class);
        $service->createSubmissionIntent($record->fresh(), $actor, 1);
    }

    public function test_create_submission_intent_rejects_missing_draft_content(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-104'), 'rec-104')['record'];
        $record->forceFill(['current_draft_path' => null, 'current_draft_sha256' => null, 'current_draft_version_id' => null])->save();

        $this->expectException(AnnotationLifecycleValidationException::class);
        $service->createSubmissionIntent($record->fresh(), $actor, 1);
    }

    public function test_create_submission_intent_rejects_discarded_and_finalized_records(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $discarded = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-105'), 'rec-105')['record'];
        $discarded = $service->discardDraft($discarded, $actor, 1, 'done');

        try {
            $service->createSubmissionIntent($discarded->fresh(), $actor, 2);
            $this->fail('Expected discarded conflict.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('draft', strtolower($e->getMessage()));
        }

        $finalized = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-106'), 'rec-106')['record'];
        $finalized->forceFill([
            'artifact_state' => 'submitted_finalized',
            'workflow_status' => 'validated',
            'submission_state' => 'finalized',
            'submitted_json_path' => 'submitted/rec-106/record.json',
            'submitted_artifact_sha256' => str_repeat('a', 64),
            'submission_finalized_at' => now(),
        ])->save();

        $this->expectException(AnnotationLifecycleConflictException::class);
        $service->createSubmissionIntent($finalized->fresh(), $actor, 1);
    }

    public function test_create_submission_intent_rejects_in_progress_states(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        foreach (['staging_written', 'b1_completed', 'promotion_pending'] as $idx => $state) {
            $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-107-'.$idx), 'rec-107-'.$idx)['record'];
            $record->forceFill(['submission_state' => $state, 'submission_intent_id' => 'si-existing'])->save();

            try {
                $service->createSubmissionIntent($record->fresh(), $actor, 1);
                $this->fail('Expected in-progress conflict for '.$state);
            } catch (AnnotationLifecycleConflictException $e) {
                $this->assertStringContainsString('already in progress', $e->getMessage());
            }
        }
    }

    public function test_retry_from_b1_infra_failed_creates_new_intent_and_preserves_old_audit(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-108'), 'rec-108')['record'];
        $first = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];
        $firstIntent = $first->submission_intent_id;
        $firstPath = $first->submission_staging_path;
        $firstSha = $first->submission_staging_sha256;

        $first->forceFill(['submission_state' => 'b1_infra_failed'])->save();
        // Keep old submission intent metadata as failed-attempt context; retry must issue new intent.
        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'submission_intent_created',
        ]);

        $second = $service->createSubmissionIntent($first->fresh(), $actor, 2)['record'];

        $this->assertSame('staging_written', $second->submission_state);
        $this->assertNotSame($firstIntent, $second->submission_intent_id);
        $this->assertNotSame($firstPath, $second->submission_staging_path);
        $this->assertSame($firstSha, $second->submission_staging_sha256);
        $this->assertFileExists($this->artifactRoot.'/'.$firstPath);
        $this->assertFileExists($this->artifactRoot.'/'.$second->submission_staging_path);
        $this->assertSame(2, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_intent_created')->count());
    }

    public function test_staging_failure_before_intent_persistence_leaves_record_unchanged(): void
    {
        $actor = $this->annotationUser();
        $levels = [];
        $store = new class($this->artifactRoot) extends AnnotationArtifactStore
        {
            public function __construct(string $root)
            {
                config([
                    'filesystems.disks.annotation' => ['driver' => 'local', 'root' => $root, 'throw' => false],
                    'annotation.annotation_storage_disk' => 'annotation',
                    'annotation.annotation_storage_driver' => 'local',
                ]);
                parent::__construct();
            }

            public function writeSubmissionStaging(string $recordId, string $submissionIntentId, string $content): array
            {
                throw new ArtifactStorageException('simulated staging failure');
            }
        };
        $service = new AnnotationRecordLifecycleService($store);
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-109'), 'rec-109')['record'];

        try {
            $service->createSubmissionIntent($record->fresh(), $actor, 1);
            $this->fail('Expected staging failure.');
        } catch (ArtifactStorageException $e) {
            $this->assertSame('simulated staging failure', $e->getMessage());
        }

        $fresh = $record->fresh();
        $this->assertSame('none', $fresh->submission_state);
        $this->assertNull($fresh->submission_intent_id);
        $this->assertNull($fresh->submission_staging_path);
        $this->assertNull($fresh->submission_staging_sha256);
        $this->assertSame(1, $fresh->lock_version);
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_intent_created']);
    }

    public function test_db_failure_after_staging_write_leaves_no_active_intent_or_audit_row(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-110'), 'rec-110')['record'];

        AnnotationReviewAction::creating(function (AnnotationReviewAction $action): void {
            if ($action->action === 'submission_intent_created') {
                throw new \RuntimeException('simulated submission audit failure');
            }
        });

        try {
            $service->createSubmissionIntent($record->fresh(), $actor, 1);
            $this->fail('Expected DB failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated submission audit failure', $e->getMessage());
        } finally {
            AnnotationReviewAction::clearBootedModels();
        }

        $fresh = $record->fresh();
        $this->assertSame('none', $fresh->submission_state);
        $this->assertNull($fresh->submission_intent_id);
        $this->assertNull($fresh->submission_staging_path);
        $this->assertNull($fresh->submission_staging_sha256);
        $this->assertSame(1, $fresh->lock_version);
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_intent_created']);
        $this->assertDatabaseCount('annotation_validation_runs', 0);
        // Orphan staged artifact may remain for reconciliation; intent is not durably active.
        $this->assertDirectoryExists($this->artifactRoot.'/submitted/rec-110/staging');
    }

    public function test_submission_intent_does_not_run_filesystem_operations_inside_db_transaction(): void
    {
        $actor = $this->annotationUser();
        $levels = [];
        $this->configureStore($this->artifactRoot);
        $store = new class($levels) extends AnnotationArtifactStore
        {
            /** @var list<int> */
            public array $txLevels;

            /** @param list<int> $levels */
            public function __construct(array &$levels)
            {
                parent::__construct();
                $this->txLevels = &$levels;
            }

            public function writeSubmissionStaging(string $recordId, string $submissionIntentId, string $content): array
            {
                $this->txLevels[] = DB::transactionLevel();

                return parent::writeSubmissionStaging($recordId, $submissionIntentId, $content);
            }

            public function verifyStoredArtifact(string $relativePath, string $expectedSha256): array
            {
                $this->txLevels[] = DB::transactionLevel();

                return parent::verifyStoredArtifact($relativePath, $expectedSha256);
            }

            public function resolveRelativePath(string $relativePath, bool $mustExist = true): string
            {
                $this->txLevels[] = DB::transactionLevel();

                return parent::resolveRelativePath($relativePath, $mustExist);
            }
        };
        $service = new AnnotationRecordLifecycleService($store);
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-111'), 'rec-111')['record'];
        $levels = [];

        $service->createSubmissionIntent($record->fresh(), $actor, 1);

        $this->assertNotEmpty($levels);
        foreach ($levels as $level) {
            $this->assertSame(0, $level, 'Filesystem artifact operations must not run inside DB transaction.');
        }
    }

    public function test_submission_intent_creation_is_atomic_against_concurrent_state_change(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-112'), 'rec-112')['record'];

        DB::transaction(function () use ($record, $service, $actor): void {
            $locked = AnnotationRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->assertSame('draft', $locked->workflow_status);

            $other = DB::connection()->table('annotation_records')->where('id', $record->id)->update([
                'submission_state' => 'staging_written',
                'submission_intent_id' => 'si-other',
                'lock_version' => 2,
                'updated_at' => now(),
            ]);
            $this->assertSame(1, $other);

            try {
                $service->createSubmissionIntent($record->fresh(), $actor, 1);
                $this->fail('Expected conflict from stale/changed state.');
            } catch (AnnotationLifecycleConflictException $e) {
                $msg = strtolower($e->getMessage());
                $this->assertTrue(
                    str_contains($msg, 'lock_version') || str_contains($msg, 'submission state changed') || str_contains($msg, 'in progress'),
                    'Unexpected conflict: '.$e->getMessage()
                );
            }
        });

        $fresh = $record->fresh();
        $this->assertSame('staging_written', $fresh->submission_state);
        $this->assertSame('si-other', $fresh->submission_intent_id);
    }

    public function test_create_submission_intent_rejects_other_authorized_annotator_non_owner(): void
    {
        $owner = $this->annotationUser();
        $otherAnnotator = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($owner, $this->validIndividualDraftPayload('rec-120'), 'rec-120')['record'];

        $this->expectException(AnnotationLifecycleAuthorizationException::class);
        try {
            $service->createSubmissionIntent($record->fresh(), $otherAnnotator, 1);
        } finally {
            $fresh = $record->fresh();
            $this->assertSame('none', $fresh->submission_state);
            $this->assertNull($fresh->submission_intent_id);
            $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_intent_created']);
        }
    }

    public function test_submission_candidate_rejects_missing_or_malformed_provenance(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $base = $this->validIndividualDraftPayload('rec-121');

        foreach ([
            ['provenance' => null],
            ['provenance' => 'bad'],
            ['provenance' => ['source_type' => 'historical_individual_inspection']],
            ['provenance' => array_merge($base['provenance'], ['source_type' => 'invalid'])],
            ['provenance' => array_merge($base['provenance'], ['is_human_ground_truth' => 'true'])],
        ] as $i => $override) {
            $rid = 'rec-121-'.$i;
            $payload = $this->validIndividualDraftPayload($rid);
            foreach ($override as $k => $v) {
                $payload[$k] = $v;
            }
            $record = $service->createDraft($actor, $payload, $rid)['record'];
            try {
                $service->createSubmissionIntent($record->fresh(), $actor, 1);
                $this->fail('Expected provenance validation failure for case '.$i);
            } catch (AnnotationLifecycleValidationException $e) {
                $this->assertStringContainsStringIgnoringCase('provenance', $e->getMessage());
            }
            $fresh = $record->fresh();
            $this->assertSame('none', $fresh->submission_state);
            $this->assertNull($fresh->submission_intent_id);
            $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_intent_created']);
        }
    }

    public function test_submission_candidate_rejects_missing_or_malformed_individual_payload(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $cases = [
            function ($r) {
                $p = $this->validIndividualDraftPayload($r);
                $p['individual'] = null;

                return $p;
            },
            function ($r) {
                $p = $this->validIndividualDraftPayload($r);
                $p['individual'] = ['image_id' => 'IMG-000001'];

                return $p;
            },
            function ($r) {
                $p = $this->validIndividualDraftPayload($r);
                $p['individual']['image_id'] = 'BAD';

                return $p;
            },
            function ($r) {
                $p = $this->validIndividualDraftPayload($r);
                $p['individual']['relative_path'] = '';

                return $p;
            },
        ];
        foreach ($cases as $i => $make) {
            $rid = 'rec-122-'.$i;
            $record = $service->createDraft($actor, $make($rid), $rid)['record'];
            try {
                $service->createSubmissionIntent($record->fresh(), $actor, 1);
                $this->fail('Expected individual validation failure for case '.$i);
            } catch (AnnotationLifecycleValidationException $e) {
                $this->assertStringContainsStringIgnoringCase('individual', $e->getMessage());
            }
            $this->assertSame('none', $record->fresh()->submission_state);
            $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_intent_created']);
        }
    }

    public function test_submission_candidate_rejects_missing_or_malformed_group_payload(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $make = function (string $rid, callable $mutate): array {
            $p = $this->validGroupDraftPayload($rid);
            $mutate($p);

            return $p;
        };
        $cases = [
            $make('rec-123-0', fn (&$p) => $p['group'] = null),
            $make('rec-123-1', fn (&$p) => $p['group'] = ['group_id' => 'g1']),
            $make('rec-123-2', fn (&$p) => $p['group']['image_ids'] = 'bad'),
            $make('rec-123-3', function (&$p) {
                $p['group']['image_ids'] = ['IMG-000001'];
                $p['group']['image_count'] = 2;
            }),
        ];
        foreach ($cases as $i => $payload) {
            $rid = 'rec-123-'.$i;
            $payload['record_id'] = $rid;
            $record = $service->createDraft($actor, $payload, $rid, 'pending_confirmation', 'group')['record'];
            try {
                $service->createSubmissionIntent($record->fresh(), $actor, 1);
                $this->fail('Expected group validation failure for case '.$i);
            } catch (AnnotationLifecycleValidationException $e) {
                $this->assertStringContainsStringIgnoringCase('group', $e->getMessage());
            }
            $this->assertSame('none', $record->fresh()->submission_state);
            $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_intent_created']);
        }
    }

    public function test_submission_candidate_rejects_recognition_method_payload_conflicts(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $individual = $this->validIndividualDraftPayload('rec-124');
        $individual['group'] = $this->validGroupDraftPayload('rec-124')['group'];
        $r1 = $service->createDraft($actor, $individual, 'rec-124')['record'];
        try {
            $service->createSubmissionIntent($r1->fresh(), $actor, 1);
            $this->fail('Expected conflict.');
        } catch (AnnotationLifecycleValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('group', $e->getMessage());
        }
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $r1->id, 'action' => 'submission_intent_created']);

        $group = $this->validGroupDraftPayload('rec-125');
        $group['individual'] = $this->validIndividualDraftPayload('rec-125')['individual'];
        $r2 = $service->createDraft($actor, $group, 'rec-125', 'pending_confirmation', 'group')['record'];
        try {
            $service->createSubmissionIntent($r2->fresh(), $actor, 1);
            $this->fail('Expected conflict.');
        } catch (AnnotationLifecycleValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('individual', $e->getMessage());
        }
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $r2->id, 'action' => 'submission_intent_created']);
    }

    public function test_submission_candidate_rejects_duplicate_labels_and_unknown_fields(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        $dup = $this->validIndividualDraftPayload('rec-126', ['ingredient_labels' => ['ginger', 'ginger']]);
        $r1 = $service->createDraft($actor, $dup, 'rec-126')['record'];
        try {
            $service->createSubmissionIntent($r1->fresh(), $actor, 1);
            $this->fail('Expected duplicate rejection.');
        } catch (AnnotationLifecycleValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('duplicate', $e->getMessage());
        }
        $this->assertSame('none', $r1->fresh()->submission_state);
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $r1->id, 'action' => 'submission_intent_created']);

        $unknown = $this->validIndividualDraftPayload('rec-127');
        $unknown['unexpected'] = 'value';
        $r2 = $service->createDraft($actor, $unknown, 'rec-127')['record'];
        try {
            $service->createSubmissionIntent($r2->fresh(), $actor, 1);
            $this->fail('Expected unknown field rejection.');
        } catch (AnnotationLifecycleValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('unsupported field', $e->getMessage());
        }
        $this->assertSame('none', $r2->fresh()->submission_state);
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $r2->id, 'action' => 'submission_intent_created']);

        $unknownNested = $this->validIndividualDraftPayload('rec-128');
        $unknownNested['provenance']['extra'] = 'x';
        $r3 = $service->createDraft($actor, $unknownNested, 'rec-128')['record'];
        try {
            $service->createSubmissionIntent($r3->fresh(), $actor, 1);
            $this->fail('Expected nested unknown field rejection.');
        } catch (AnnotationLifecycleValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('unsupported field', $e->getMessage());
        }
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $r3->id, 'action' => 'submission_intent_created']);
    }

    public function test_submission_candidate_rejects_group_image_count_mismatch_and_invalid_image_id(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        $mismatch = $this->validGroupDraftPayload('rec-129', ['group' => array_merge($this->validGroupDraftPayload('rec-129')['group'], ['image_ids' => ['IMG-000001', 'IMG-000002'], 'image_count' => 1])]);
        $r1 = $service->createDraft($actor, $mismatch, 'rec-129', 'pending_confirmation', 'group')['record'];
        try {
            $service->createSubmissionIntent($r1->fresh(), $actor, 1);
            $this->fail('Expected image_count mismatch.');
        } catch (AnnotationLifecycleValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('image_count', $e->getMessage());
        }
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $r1->id, 'action' => 'submission_intent_created']);

        $badId = $this->validGroupDraftPayload('rec-130', ['group' => array_merge($this->validGroupDraftPayload('rec-130')['group'], ['image_ids' => ['bad-id'], 'image_count' => 1])]);
        $r2 = $service->createDraft($actor, $badId, 'rec-130', 'pending_confirmation', 'group')['record'];
        try {
            $service->createSubmissionIntent($r2->fresh(), $actor, 1);
            $this->fail('Expected invalid image_id.');
        } catch (AnnotationLifecycleValidationException $e) {
            $this->assertStringContainsStringIgnoringCase('image_id', $e->getMessage());
        }
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $r2->id, 'action' => 'submission_intent_created']);
    }

    public function test_submission_candidate_validation_against_step8a_schema_and_b1_adapter_path(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();

        $individualPayload = $this->validIndividualDraftPayload('rec-131', [
            'ingredient_labels' => ['black_pepper_whole'],
            'individual' => [
                'image_id' => 'IMG-000001',
                'relative_path' => 'Black Pepper/black_pepper_00001.jpg',
                'original_folder' => 'Black Pepper',
                'original_filename' => 'black_pepper_00001.jpg',
            ],
        ]);
        $groupPayload = $this->validGroupDraftPayload('rec-132', [
            'ingredient_labels' => ['black_pepper_whole'],
            'group' => [
                'group_id' => 'GRP-black-pepper',
                'original_folder' => 'Black Pepper',
                'proposed_ingredient' => 'black_pepper_whole',
                'image_ids' => ['IMG-000001', 'IMG-000002'],
                'image_count' => 2,
            ],
        ]);

        $individual = $service->createDraft($actor, $individualPayload, 'rec-131')['record'];
        $group = $service->createDraft($actor, $groupPayload, 'rec-132', 'pending_confirmation', 'group')['record'];

        $iResult = $service->createSubmissionIntent($individual->fresh(), $actor, 1);
        $gResult = $service->createSubmissionIntent($group->fresh(), $actor, 1);

        foreach ([$iResult, $gResult] as $result) {
            $absolute = $this->artifactRoot.'/'.$result['staging']['relative_path'];
            $this->assertFileExists($absolute);
            $bytes = File::get($absolute);
            $decoded = json_decode($bytes, true);
            $this->assertIsArray($decoded);
            $this->assertSame('human-recognition-v1', $decoded['schema_version']);
            $this->assertContains($decoded['recognition_method'], ['individual', 'group']);
            $this->assertTrue(array_key_exists('individual', $decoded) xor array_key_exists('group', $decoded));
            $this->assertArrayHasKey('provenance', $decoded);
            $this->assertNull($decoded['reviewer_id']);
            $this->assertNull($decoded['recognition_date']);
        }

        $schemaPath = base_path('../research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json');
        $validator = base_path('../research/phase-13.3/annotation_pipeline_step8a/scripts/validate_human_recognition.py');
        $inventory = base_path('../research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv');
        $taxonomy = base_path('../research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json');
        $python = (string) config('annotation.b1_python_bin', 'python');
        $pythonOk = false;
        $probe = @proc_open([$python, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path('..'));
        if (is_resource($probe)) {
            foreach ($pipes as $p) {
                fclose($p);
            }
            $pythonOk = proc_close($probe) === 0;
        }
        if ($pythonOk && is_file($schemaPath) && is_file($validator) && is_file($inventory) && is_file($taxonomy)) {
            $tmp = storage_path('framework/testing/i6-c1-schema-'.uniqid('', true));
            File::ensureDirectoryExists($tmp);
            File::put($tmp.'/individual.json', File::get($this->artifactRoot.'/'.$iResult['staging']['relative_path']));
            File::put($tmp.'/group.json', File::get($this->artifactRoot.'/'.$gResult['staging']['relative_path']));
            $cmd = [$python, $validator, '--records', $tmp, '--schema', $schemaPath, '--inventory', $inventory, '--taxonomy', $taxonomy];
            $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path('..'));
            $stdout = '';
            $stderr = '';
            if (is_resource($proc)) {
                $stdout = (string) stream_get_contents($pipes[1]);
                $stderr = (string) stream_get_contents($pipes[2]);
                foreach ($pipes as $p) {
                    fclose($p);
                }
                $exit = proc_close($proc);
                $this->assertSame(0, $exit, $stdout.$stderr);
                $this->assertStringContainsString('Errors: 0', $stdout.$stderr);
            }
            File::deleteDirectory($tmp);
        } else {
            $this->addWarning('Step 8A Python/schema validator or reference files not executable/available in this environment; schema validation was limited to structural assertions.');
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validIndividualDraftPayload(string $recordId, array $overrides = []): array
    {
        $payload = [
            'record_id' => $recordId,
            'schema_version' => 'human-recognition-v1',
            'recognition_method' => 'individual',
            'verification_status' => 'pending_confirmation',
            'ingredient_labels' => ['cardamom_pod'],
            'uncertain_labels' => [],
            'out_of_scope_labels' => [],
            'reviewer_id' => null,
            'recognition_date' => null,
            'notes' => 'draft note',
            'provenance' => [
                'source_type' => 'historical_individual_inspection',
                'source_reference' => 'ref-1',
                'evidence_summary' => 'evidence',
                'is_human_ground_truth' => false,
            ],
            'individual' => [
                'image_id' => 'IMG-000001',
                'relative_path' => 'Cardamom/img.jpg',
                'original_folder' => 'Cardamom',
                'original_filename' => 'img.jpg',
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validGroupDraftPayload(string $recordId, array $overrides = []): array
    {
        $payload = [
            'record_id' => $recordId,
            'schema_version' => 'human-recognition-v1',
            'recognition_method' => 'group',
            'verification_status' => 'pending_confirmation',
            'ingredient_labels' => ['cardamom_pod'],
            'uncertain_labels' => [],
            'out_of_scope_labels' => [],
            'reviewer_id' => null,
            'recognition_date' => null,
            'notes' => 'group draft note',
            'provenance' => [
                'source_type' => 'historical_group_inspection',
                'source_reference' => 'ref-group-1',
                'evidence_summary' => 'group evidence',
                'is_human_ground_truth' => false,
            ],
            'group' => [
                'group_id' => 'GRP-cardamom',
                'original_folder' => 'Cardamom',
                'proposed_ingredient' => 'cardamom_pod',
                'image_ids' => ['IMG-000001', 'IMG-000002'],
                'image_count' => 2,
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    /**
     * @param  array<string,mixed>  $draftPayload
     */
    private function expectedCandidateJson(AnnotationRecord $record, array $draftPayload): string
    {
        $candidate = [
            'schema_version' => 'human-recognition-v1',
            'record_id' => $record->record_id,
            'recognition_method' => $record->recognition_method,
            'verification_status' => $record->verification_status,
            'ingredient_labels' => array_values($draftPayload['ingredient_labels']),
            'uncertain_labels' => array_values($draftPayload['uncertain_labels']),
            'out_of_scope_labels' => array_values($draftPayload['out_of_scope_labels']),
            'reviewer_id' => $draftPayload['reviewer_id'],
            'recognition_date' => $draftPayload['recognition_date'],
            'notes' => $draftPayload['notes'],
            'provenance' => $draftPayload['provenance'],
        ];
        if ($record->recognition_method === 'individual') {
            $candidate['individual'] = $draftPayload['individual'];
        } else {
            $candidate['group'] = $draftPayload['group'];
        }

        return json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
    }

    private function annotationUser(string $role = User::ROLE_ANNOTATOR): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    public function test_execute_submission_intent_validation_completed_valid_transitions_and_persists(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-001'), 'rec-c2-001')['record'];
        $intent = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];
        $this->bindCompletedB1Persistence(true, [], [], 'b1-test');

        $result = $service->executeSubmissionIntentValidation($intent->fresh(), $actor, 2, 'b1-test');
        $updated = $result['record'];
        $run = $result['run'];
        if ($updated->submission_state !== 'b1_completed') {
            fwrite(STDERR, json_encode(['status' => $run->status, 'infra' => $run->infra_error_code, 'ok' => $result['b1_result']->ok, 'payload' => $result['b1_result']->payload])."\n");
        }

        $this->assertSame('b1_completed', $updated->submission_state);
        $this->assertSame('draft', $updated->workflow_status);
        $this->assertSame('draft', $updated->artifact_state);
        $this->assertSame(3, $updated->lock_version);
        $this->assertNull($updated->submitted_json_path);
        $this->assertNull($updated->submitted_artifact_sha256);
        $this->assertNull($updated->submission_finalized_at);
        $this->assertSame($intent->submission_intent_id, $updated->submission_intent_id);
        $this->assertSame($intent->submission_staging_path, $updated->submission_staging_path);
        $this->assertSame($intent->submission_staging_sha256, $updated->submission_staging_sha256);

        $this->assertSame('completed', $run->status);
        $this->assertTrue((bool) $run->is_valid);
        $this->assertSame($intent->submission_staging_path, $run->input_artifact_path);
        $this->assertSame($intent->submission_staging_sha256, $run->input_artifact_sha256);
        $this->assertSame('staged_path', $run->input_evidence_state);
        $this->assertDatabaseHas('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'validation_completed']);
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_infra_failed']);
    }

    public function test_execute_submission_intent_validation_completed_invalid_uses_b1_completed_and_validation_failed(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-002'), 'rec-c2-002')['record'];
        $intent = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];
        $errors = [['severity' => 'error', 'code' => 'ERR_X', 'record_id' => 'rec-c2-002', 'image_id' => null, 'field' => 'ingredient_labels', 'message' => 'bad', 'details' => []]];
        $this->bindCompletedB1Persistence(false, $errors, [], 'b1-test');

        $result = $service->executeSubmissionIntentValidation($intent->fresh(), $actor, 2, 'b1-test');
        $updated = $result['record'];
        $run = $result['run'];
        $this->assertSame('b1_completed', $updated->submission_state);
        $this->assertSame(3, $updated->lock_version);
        $this->assertSame('completed', $run->status);
        $this->assertFalse((bool) $run->is_valid);
        $this->assertSame(1, $run->error_count);
        $this->assertDatabaseHas('annotation_validation_issues', ['validation_run_id' => $run->id, 'code' => 'ERR_X']);
        $this->assertDatabaseHas('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'validation_failed']);
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_infra_failed']);
    }

    public function test_execute_submission_intent_validation_od01_infra_failure_persists_and_transitions(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-003'), 'rec-c2-003')['record'];
        $intent = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];
        $stagingPath = $intent->submission_staging_path;
        $stagingSha = $intent->submission_staging_sha256;
        $this->bindInfraB1Persistence('B1_PROCESS_FAILED', false, 2);

        $result = $service->executeSubmissionIntentValidation($intent->fresh(), $actor, 2, 'b1-test');
        $updated = $result['record'];
        $run = $result['run'];

        $this->assertSame('b1_infra_failed', $updated->submission_state);
        $this->assertSame('draft', $updated->workflow_status);
        $this->assertSame('draft', $updated->artifact_state);
        $this->assertSame(3, $updated->lock_version);
        $this->assertNull($updated->submitted_json_path);
        $this->assertNull($updated->submitted_artifact_sha256);
        $this->assertNull($updated->submission_finalized_at);
        $this->assertSame($stagingPath, $updated->submission_staging_path);
        $this->assertSame($stagingSha, $updated->submission_staging_sha256);
        $this->assertFileExists($this->artifactRoot.'/'.$stagingPath);

        $this->assertSame('infra_failed', $run->status);
        $this->assertSame('B1_PROCESS_FAILED', $run->infra_error_code);
        $this->assertSame($stagingPath, $run->input_artifact_path);
        $this->assertSame($stagingSha, $run->input_artifact_sha256);
        $this->assertSame('staged_path', $run->input_evidence_state);
        $this->assertDatabaseHas('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'validation_infra_failed']);
        $this->assertDatabaseHas('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_infra_failed']);
    }

    public function test_execute_submission_intent_validation_rejects_preconditions(): void
    {
        $owner = $this->annotationUser();
        $other = $this->annotationUser();
        $service = $this->makeService();
        $this->bindCompletedB1Persistence();

        $record = $service->createDraft($owner, $this->validIndividualDraftPayload('rec-c2-004'), 'rec-c2-004')['record'];
        try {
            $service->executeSubmissionIntentValidation($record->fresh(), $owner, 1);
            $this->fail();
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('staging_written', $e->getMessage());
        }

        $intent = $service->createSubmissionIntent($record->fresh(), $owner, 1)['record'];
        try {
            $service->executeSubmissionIntentValidation($intent->fresh(), $other, 2);
            $this->fail();
        } catch (AnnotationLifecycleAuthorizationException $e) {
            $this->assertStringContainsString('owner', strtolower($e->getMessage()));
        }
        try {
            $service->executeSubmissionIntentValidation($intent->fresh(), $owner, 1);
            $this->fail();
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('lock_version', strtolower($e->getMessage()));
        }
    }

    public function test_execute_submission_intent_validation_rejects_wrong_state_and_duplicate_intent(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $this->bindCompletedB1Persistence();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-005'), 'rec-c2-005')['record'];
        $intent = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];
        $service->executeSubmissionIntentValidation($intent->fresh(), $actor, 2);

        $completed = $record->fresh();
        $this->assertSame('b1_completed', $completed->submission_state);
        try {
            $service->executeSubmissionIntentValidation($completed, $actor, 3);
            $this->fail();
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('staging_written', $e->getMessage());
        }
        $this->assertSame(1, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
    }

    public function test_execute_submission_intent_validation_callback_mismatch_rolls_back_phase_b(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-006'), 'rec-c2-006')['record'];
        $intent = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];

        $runner = new class extends B1ProcessRunner
        {
            public function run(array $command, string $cwd, int $timeoutSeconds, ?array $env = null): array
            {
                $reportPath = null;
                $recordPath = null;
                for ($i = 0; $i < count($command); $i++) {
                    if ($command[$i] === '--out' && isset($command[$i + 1])) {
                        $reportPath = $command[$i + 1].DIRECTORY_SEPARATOR.'step8b_validation_report.json';
                    }
                    if ($command[$i] === '--records' && isset($command[$i + 1])) {
                        $recordPath = $command[$i + 1];
                    }
                }
                $record = is_string($recordPath) && is_file($recordPath) ? json_decode((string) file_get_contents($recordPath), true) : [];
                $record = is_array($record) ? $record : [];
                $rid = $record['record_id'] ?? 'rec';
                $payload = ['schema_version' => 'b1-validation-report-v1', 'summary' => ['records_checked' => 1, 'valid_records' => 1, 'invalid_records' => 0, 'export_eligible_canonical_records' => 1, 'error_count' => 0, 'warning_count' => 0, 'export_block_count' => 0, 'issues_by_code' => []], 'results' => [['record_id' => $rid, 'source' => is_string($recordPath) ? basename($recordPath) : $rid.'.json', 'recognition_method' => $record['recognition_method'] ?? null, 'verification_status' => $record['verification_status'] ?? null, 'valid' => true, 'export_eligible_canonical' => true, 'export_block_codes' => [], 'resolved_ingredient_labels' => array_values(is_array($record['ingredient_labels'] ?? null) ? $record['ingredient_labels'] : []), 'field_checks' => ['recognition_method' => true, 'verification_status' => true, 'ingredient_labels' => true, 'uncertain_labels' => true, 'out_of_scope_labels' => true, 'label_conflicts' => true, 'individual_payload' => true, 'group_payload' => true, 'provenance' => true, 'taxonomy_membership' => true, 'image_inventory_refs' => true, 'image_folder_consistency' => true, 'no_extra_fields' => true]]], 'issues' => []];
                if (is_string($reportPath)) {
                    @mkdir(dirname($reportPath), 0777, true);
                    file_put_contents($reportPath, json_encode($payload));
                }

                return ['exit_code' => 0, 'stdout' => '{}', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
        };
        $adapter = new B1ValidationAdapter($runner, config('annotation'));
        $persistence = new class($adapter, new AnnotationArtifactStore) extends AnnotationValidationPersistenceService
        {
            public function persistStagedSubmissionAttempt(AnnotationRecord $record, string $submissionIntentId, string $stagedRelativePath, string $stagedSha256, ?int $executedByUserId = null, string $actorType = 'user', ?string $b1Version = null, ?\Closure $withinPhaseBTransaction = null): array
            {
                $wrapped = function (AnnotationValidationRun $run, B1ValidationResult $b1Result, AnnotationRecord $stale) use ($withinPhaseBTransaction, $record): void {
                    if ($withinPhaseBTransaction) {
                        $withinPhaseBTransaction($run, $b1Result, $stale);
                    }
                    DB::table('annotation_records')->where('id', $record->id)->update(['lock_version' => 99, 'updated_at' => now()]);
                    throw new AnnotationLifecycleConflictException('simulated callback mismatch after mutation');
                };

                return parent::persistStagedSubmissionAttempt($record, $submissionIntentId, $stagedRelativePath, $stagedSha256, $executedByUserId, $actorType, $b1Version, $wrapped);
            }
        };
        $this->app->instance(AnnotationValidationPersistenceService::class, $persistence);

        try {
            $service->executeSubmissionIntentValidation($intent->fresh(), $actor, 2);
            $this->fail('Expected conflict.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('mismatch', strtolower($e->getMessage()));
        }

        $fresh = $record->fresh();
        $this->assertSame('staging_written', $fresh->submission_state);
        $this->assertSame(2, $fresh->lock_version);
        $this->assertDatabaseCount('annotation_validation_runs', 0);
        $this->assertDatabaseCount('annotation_validation_issues', 0);
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'validation_completed']);
        $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_infra_failed']);
    }

    public function test_execute_submission_intent_validation_retry_after_od01_preserves_history(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-007'), 'rec-c2-007')['record'];
        $intent1 = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];
        $oldIntentId = $intent1->submission_intent_id;
        $oldPath = $intent1->submission_staging_path;
        $this->bindInfraB1Persistence('B1_TIMEOUT', true, null);

        $failed = $service->executeSubmissionIntentValidation($intent1->fresh(), $actor, 2)['record'];
        $this->assertSame('b1_infra_failed', $failed->submission_state);
        $this->assertFileExists($this->artifactRoot.'/'.$oldPath);

        $intent2 = $service->createSubmissionIntent($failed->fresh(), $actor, 3)['record'];
        $this->assertNotSame($oldIntentId, $intent2->submission_intent_id);
        $this->bindCompletedB1Persistence(true);

        $completed = $service->executeSubmissionIntentValidation($intent2->fresh(), $actor, 4)['record'];
        $this->assertSame('b1_completed', $completed->submission_state);
        $this->assertSame(2, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame(1, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->where('status', 'infra_failed')->count());
        $this->assertSame(1, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->where('status', 'completed')->count());
        $this->assertFileExists($this->artifactRoot.'/'.$oldPath);
    }

    public function test_execute_submission_intent_validation_rejects_staging_hash_mismatch_and_mismatched_intent_metadata(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $this->bindCompletedB1Persistence();

        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-008'), 'rec-c2-008')['record'];
        $intent = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];
        File::put($this->artifactRoot.'/'.$intent->submission_staging_path, '{"tampered":true}'.PHP_EOL);
        try {
            $service->executeSubmissionIntentValidation($intent->fresh(), $actor, 2);
            $this->fail('Expected hash mismatch.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('hash', strtolower($e->getMessage()));
        }
        $this->assertSame('staging_written', $record->fresh()->submission_state);
        $this->assertDatabaseCount('annotation_validation_runs', 0);

        $record2 = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-009'), 'rec-c2-009')['record'];
        $intent2 = $service->createSubmissionIntent($record2->fresh(), $actor, 1)['record'];
        $intent2->forceFill(['submission_staging_path' => 'submitted/rec-c2-009/staging/si-other.json'])->save();
        try {
            $service->executeSubmissionIntentValidation($intent2->fresh(), $actor, 2);
            $this->fail('Expected staging path mismatch.');
        } catch (\Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame('staging_written', $record2->fresh()->submission_state);
    }

    public function test_execute_submission_intent_validation_rejects_no_active_intent_and_wrong_state(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $this->bindCompletedB1Persistence();
        $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-010'), 'rec-c2-010')['record'];
        try {
            $service->executeSubmissionIntentValidation($record->fresh(), $actor, 1);
            $this->fail();
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('staging_written', $e->getMessage());
        }

        $record->forceFill(['submission_state' => 'b1_infra_failed'])->save();
        try {
            $service->executeSubmissionIntentValidation($record->fresh(), $actor, 1);
            $this->fail();
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('staging_written', $e->getMessage());
        }
    }

    public function test_execute_submission_intent_validation_callback_detects_intent_state_and_lock_mismatch(): void
    {
        $actor = $this->annotationUser();
        $service = $this->makeService();
        $baseRunner = new class extends B1ProcessRunner
        {
            public function run(array $command, string $cwd, int $timeoutSeconds, ?array $env = null): array
            {
                $reportPath = null;
                $recordPath = null;
                for ($i = 0; $i < count($command); $i++) {
                    if ($command[$i] === '--out' && isset($command[$i + 1])) {
                        $reportPath = $command[$i + 1].DIRECTORY_SEPARATOR.'step8b_validation_report.json';
                    } if ($command[$i] === '--records' && isset($command[$i + 1])) {
                        $recordPath = $command[$i + 1];
                    }
                }
                $record = is_string($recordPath) && is_file($recordPath) ? json_decode((string) file_get_contents($recordPath), true) : [];
                $record = is_array($record) ? $record : [];
                $rid = $record['record_id'] ?? 'rec';
                $payload = ['schema_version' => 'b1-validation-report-v1', 'summary' => ['records_checked' => 1, 'valid_records' => 1, 'invalid_records' => 0, 'export_eligible_canonical_records' => 1, 'error_count' => 0, 'warning_count' => 0, 'export_block_count' => 0, 'issues_by_code' => []], 'results' => [['record_id' => $rid, 'source' => is_string($recordPath) ? basename($recordPath) : $rid.'.json', 'recognition_method' => $record['recognition_method'] ?? null, 'verification_status' => $record['verification_status'] ?? null, 'valid' => true, 'export_eligible_canonical' => true, 'export_block_codes' => [], 'resolved_ingredient_labels' => array_values(is_array($record['ingredient_labels'] ?? null) ? $record['ingredient_labels'] : []), 'field_checks' => ['recognition_method' => true, 'verification_status' => true, 'ingredient_labels' => true, 'uncertain_labels' => true, 'out_of_scope_labels' => true, 'label_conflicts' => true, 'individual_payload' => true, 'group_payload' => true, 'provenance' => true, 'taxonomy_membership' => true, 'image_inventory_refs' => true, 'image_folder_consistency' => true, 'no_extra_fields' => true]]], 'issues' => []];
                if (is_string($reportPath)) {
                    @mkdir(dirname($reportPath), 0777, true);
                    file_put_contents($reportPath, json_encode($payload));
                }

                return ['exit_code' => 0, 'stdout' => '{}', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
        };

        foreach (['lock', 'intent', 'state'] as $case) {
            $record = $service->createDraft($actor, $this->validIndividualDraftPayload('rec-c2-011-'.$case), 'rec-c2-011-'.$case)['record'];
            $intent = $service->createSubmissionIntent($record->fresh(), $actor, 1)['record'];
            $adapter = new B1ValidationAdapter($baseRunner, config('annotation'));
            $persistence = new class($adapter, new AnnotationArtifactStore, $case, $record->id) extends AnnotationValidationPersistenceService
            {
                public function __construct(B1ValidationAdapter $a, AnnotationArtifactStore $s, private string $case, private int $recordId)
                {
                    parent::__construct($a, $s);
                }

                public function persistStagedSubmissionAttempt(AnnotationRecord $record, string $submissionIntentId, string $stagedRelativePath, string $stagedSha256, ?int $executedByUserId = null, string $actorType = 'user', ?string $b1Version = null, ?\Closure $withinPhaseBTransaction = null): array
                {
                    $wrapped = function (AnnotationValidationRun $run, B1ValidationResult $b1Result, AnnotationRecord $stale) use ($withinPhaseBTransaction): void {
                        if ($this->case === 'lock') {
                            DB::table('annotation_records')->where('id', $this->recordId)->update(['lock_version' => 99, 'updated_at' => now()]);
                        }
                        if ($this->case === 'intent') {
                            DB::table('annotation_records')->where('id', $this->recordId)->update(['submission_intent_id' => 'si-other', 'updated_at' => now()]);
                        }
                        if ($this->case === 'state') {
                            DB::table('annotation_records')->where('id', $this->recordId)->update(['submission_state' => 'b1_completed', 'updated_at' => now()]);
                        }
                        if ($withinPhaseBTransaction) {
                            $withinPhaseBTransaction($run, $b1Result, $stale);
                        }
                    };

                    return parent::persistStagedSubmissionAttempt($record, $submissionIntentId, $stagedRelativePath, $stagedSha256, $executedByUserId, $actorType, $b1Version, $wrapped);
                }
            };
            $this->app->instance(AnnotationValidationPersistenceService::class, $persistence);
            try {
                $service->executeSubmissionIntentValidation($intent->fresh(), $actor, 2);
                $this->fail('Expected conflict for '.$case);
            } catch (AnnotationLifecycleConflictException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $fresh = $record->fresh();
            $this->assertDatabaseCount('annotation_validation_runs', 0);
            $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'validation_completed']);
            $this->assertDatabaseMissing('annotation_review_actions', ['annotation_record_id' => $record->id, 'action' => 'submission_infra_failed']);
            // restore intent/state/lock for isolation checks not needed; new record per case
            $this->assertNotNull($fresh->id);
        }
    }

    private function configureB1AdapterConfig(): void
    {
        $base = storage_path('framework/testing/b1-lifecycle-'.uniqid('', true));
        File::ensureDirectoryExists($base);
        foreach (['script.py', 'schema.json', 'inventory.csv', 'taxonomy.json', 'decisions.json'] as $f) {
            File::put($base.'/'.$f, '{}');
        }
        config([
            'annotation.b1_python_bin' => 'python',
            'annotation.b1_script_path' => $base.'/script.py',
            'annotation.b1_schema_path' => $base.'/schema.json',
            'annotation.b1_inventory_path' => $base.'/inventory.csv',
            'annotation.b1_taxonomy_path' => $base.'/taxonomy.json',
            'annotation.b1_decisions_path' => $base.'/decisions.json',
            'annotation.b1_timeout_seconds' => 60,
            'annotation.b1_workdir' => base_path('..'),
            'annotation.b1_temp_dir' => $base.'/tmp',
        ]);
    }

    private function bindCompletedB1Persistence(bool $valid = true, array $errors = [], array $warnings = [], ?string $b1Version = 'b1-test'): AnnotationValidationPersistenceService
    {
        $this->configureB1AdapterConfig();
        $runner = new class($valid, $errors, $warnings, $b1Version) extends B1ProcessRunner
        {
            /** @param list<array<string,mixed>> $errors @param list<array<string,mixed>> $warnings */
            public function __construct(private bool $valid, private array $errors, private array $warnings, private ?string $b1Version) {}

            public function run(array $command, string $cwd, int $timeoutSeconds, ?array $env = null): array
            {
                if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                    return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
                }
                $reportPath = null;
                $recordPath = null;
                for ($i = 0; $i < count($command); $i++) {
                    if (($command[$i] === '--out' || $command[$i] === '--out-dir') && isset($command[$i + 1])) {
                        $reportPath = $command[$i + 1].DIRECTORY_SEPARATOR.'step8b_validation_report.json';
                    }
                    if ($command[$i] === '--records' && isset($command[$i + 1])) {
                        $recordPath = $command[$i + 1];
                    }
                }
                $record = is_string($recordPath) && is_file($recordPath) ? json_decode((string) file_get_contents($recordPath), true) : [];
                $record = is_array($record) ? $record : [];
                $recordId = $record['record_id'] ?? 'rec-unknown';
                $issues = $this->errors;
                foreach ($issues as &$issue) {
                    if (is_array($issue) && ! array_key_exists('record_id', $issue)) {
                        $issue['record_id'] = $recordId;
                    }
                }
                unset($issue);
                $payload = [
                    'schema_version' => 'b1-validation-report-v1',
                    'summary' => [
                        'records_checked' => 1,
                        'valid_records' => $this->valid ? 1 : 0,
                        'invalid_records' => $this->valid ? 0 : 1,
                        'export_eligible_canonical_records' => $this->valid ? 1 : 0,
                        'error_count' => count($this->errors),
                        'warning_count' => count($this->warnings),
                        'export_block_count' => $this->valid ? 0 : count($this->errors),
                        'issues_by_code' => [],
                    ],
                    'results' => [[
                        'record_id' => $recordId,
                        'source' => is_string($recordPath) ? basename($recordPath) : $recordId.'.json',
                        'recognition_method' => $record['recognition_method'] ?? null,
                        'verification_status' => $record['verification_status'] ?? null,
                        'valid' => $this->valid,
                        'export_eligible_canonical' => $this->valid,
                        'export_block_codes' => $this->valid ? [] : ['ERR_X'],
                        'resolved_ingredient_labels' => array_values(is_array($record['ingredient_labels'] ?? null) ? $record['ingredient_labels'] : []),
                        'field_checks' => ['recognition_method' => true, 'verification_status' => true, 'ingredient_labels' => true, 'uncertain_labels' => true, 'out_of_scope_labels' => true, 'label_conflicts' => true, 'individual_payload' => true, 'group_payload' => true, 'provenance' => true, 'taxonomy_membership' => true, 'image_inventory_refs' => true, 'image_folder_consistency' => true, 'no_extra_fields' => true],
                    ]],
                    'issues' => array_values($issues),
                ];
                if (is_string($reportPath)) {
                    @mkdir(dirname($reportPath), 0777, true);
                    file_put_contents($reportPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                return ['exit_code' => $this->valid ? 0 : 1, 'stdout' => '{}', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
        };

        $adapter = new B1ValidationAdapter($runner, config('annotation'));
        $service = new AnnotationValidationPersistenceService($adapter, new AnnotationArtifactStore);
        $this->app->instance(AnnotationValidationPersistenceService::class, $service);

        return $service;
    }

    private function makePromotionReadyRecord(bool $valid = true, array $errors = [], array $warnings = [], string $recordIdPrefix = 'rec-promote'): array
    {
        $this->bindCompletedB1Persistence($valid, $errors, $warnings);
        $lifecycle = $this->makeService();
        $user = $this->annotationUser(User::ROLE_ANNOTATOR);
        $recordId = $recordIdPrefix.'-'.substr(bin2hex(random_bytes(4)), 0, 8);
        $record = $lifecycle->createDraft($user, $this->validIndividualDraftPayload($recordId), $recordId)['record'];
        $submitted = $lifecycle->createSubmissionIntent($record, $user, (int) $record->lock_version);
        $validated = $lifecycle->executeSubmissionIntentValidation($submitted['record'], $user, (int) $submitted['record']->lock_version);
        $locked = AnnotationRecord::query()->whereKey($validated['record']->id)->firstOrFail();

        return [$lifecycle, $user, $locked, $validated['run']];
    }

    public function test_promote_and_finalize_submission_intent_succeeds_for_completed_valid_b1(): void
    {
        [$lifecycle, $user, $record, $run] = $this->makePromotionReadyRecord(true);
        $stagingPath = (string) $record->submission_staging_path;
        $stagingSha = (string) $record->submission_staging_sha256;
        $stagingContent = File::get($this->artifactRoot.'/'.$stagingPath);

        $result = $lifecycle->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $runFresh = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();

        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['evidence_updated']);
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame('validated', $fresh->workflow_status);
        $this->assertSame('submitted_finalized', $fresh->artifact_state);
        $this->assertSame((int) $user->id, (int) $fresh->submitted_by_user_id);
        $this->assertNotNull($fresh->submitted_at);
        $this->assertNotNull($fresh->submission_finalized_at);
        $this->assertSame((string) $run->run_id, (string) AnnotationValidationRun::query()->whereKey($fresh->latest_validation_run_id)->value('run_id'));
        $this->assertTrue((bool) $fresh->latest_b1_valid);
        $this->assertTrue((bool) $fresh->is_export_eligible_canonical);
        $this->assertSame($stagingSha, $fresh->submitted_artifact_sha256);
        $this->assertSame($stagingContent, File::get($this->artifactRoot.'/'.$fresh->submitted_json_path));
        $this->assertSame('final_artifact', $runFresh->input_evidence_state);
        $this->assertSame($fresh->submitted_json_path, $runFresh->durable_input_evidence_path);
        $this->assertSame($fresh->submitted_artifact_sha256, $runFresh->durable_input_evidence_sha256);
        $this->assertSame($stagingPath, $runFresh->input_artifact_path);
        $this->assertSame($stagingSha, $runFresh->input_artifact_sha256);
        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'submission_promotion_pending',
        ]);
        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'submission_finalized',
            'to_workflow_status' => 'validated',
        ]);
        $finalizedAudit = AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->firstOrFail();
        $this->assertSame((string) $run->run_id, $finalizedAudit->metadata_json['validation_run_id'] ?? null);
        $this->assertSame(true, $finalizedAudit->metadata_json['b1_valid'] ?? null);
        $this->assertSame(0, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'validation_completed')->count() - 1);
        $this->assertSame(0, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'validation_failed')->count());
    }

    public function test_promote_and_finalize_submission_intent_succeeds_for_completed_invalid_b1(): void
    {
        [$lifecycle, $user, $record, $run] = $this->makePromotionReadyRecord(false, [[
            'code' => 'ERR_X',
            'severity' => 'error',
            'record_id' => 'rec-x',
            'field' => 'ingredient_labels',
            'message' => 'Invalid',
        ]]);

        $lifecycle->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $runFresh = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();

        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame('validation_failed', $fresh->workflow_status);
        $this->assertSame('submitted_finalized', $fresh->artifact_state);
        $this->assertFalse((bool) $fresh->latest_b1_valid);
        $this->assertFalse((bool) $fresh->is_export_eligible_canonical);
        $this->assertSame('completed', $runFresh->status);
        $this->assertFalse((bool) $runFresh->is_valid);
        $this->assertDatabaseHas('annotation_review_actions', [
            'annotation_record_id' => $record->id,
            'action' => 'submission_finalized',
            'to_workflow_status' => 'validation_failed',
        ]);
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'validation_failed')->count());
    }

    public function test_promote_rejects_b1_infra_failed_and_staging_written_states(): void
    {
        $this->bindInfraB1Persistence();
        $lifecycle = $this->makeService();
        $user = $this->annotationUser(User::ROLE_ANNOTATOR);
        $recordId = 'rec-promote-infra-'.substr(bin2hex(random_bytes(4)), 0, 8);
        $record = $lifecycle->createDraft($user, $this->validIndividualDraftPayload($recordId), $recordId)['record'];
        $submitted = $lifecycle->createSubmissionIntent($record, $user, (int) $record->lock_version);

        try {
            $lifecycle->promoteAndFinalizeSubmissionIntent($submitted['record'], $user, (int) $submitted['record']->lock_version);
            $this->fail('Expected staging_written rejection.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('not eligible', $e->getMessage());
        }

        $validated = $lifecycle->executeSubmissionIntentValidation($submitted['record'], $user, (int) $submitted['record']->lock_version);
        try {
            $lifecycle->promoteAndFinalizeSubmissionIntent($validated['record'], $user, (int) $validated['record']->lock_version);
            $this->fail('Expected b1_infra_failed rejection.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('not eligible', $e->getMessage());
        }

        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame('b1_infra_failed', $fresh->submission_state);
        $this->assertSame('draft', $fresh->workflow_status);
        $this->assertNull($fresh->submitted_json_path);
    }

    public function test_promote_rejects_missing_intent_mismatch_and_missing_completed_run(): void
    {
        [$lifecycle, $user, $record] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-a');
        AnnotationRecord::query()->whereKey($record->id)->update(['submission_intent_id' => null]);
        $missingIntent = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        try {
            $lifecycle->promoteAndFinalizeSubmissionIntent($missingIntent, $user, (int) $missingIntent->lock_version);
            $this->fail('Expected missing intent rejection.');
        } catch (AnnotationLifecycleValidationException|AnnotationLifecycleConflictException $e) {
            $this->assertTrue(true);
        }

        [$lifecycle2, $user2, $record2, $run2] = $this->makePromotionReadyRecord(true);
        AnnotationValidationRun::query()->whereKey($run2->id)->update(['submission_intent_id' => 'si-other']);
        try {
            $lifecycle2->promoteAndFinalizeSubmissionIntent($record2, $user2, (int) $record2->lock_version);
            $this->fail('Expected intent/run mismatch rejection.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
        }

        [$lifecycle3, $user3, $record3, $run3] = $this->makePromotionReadyRecord(true);
        AnnotationValidationRun::query()->whereKey($run3->id)->update(['status' => 'infra_failed']);
        try {
            $lifecycle3->promoteAndFinalizeSubmissionIntent($record3, $user3, (int) $record3->lock_version);
            $this->fail('Expected incomplete run rejection.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('not completed', $e->getMessage());
        }
    }

    public function test_promote_retry_from_promotion_pending_without_final_artifact_completes_without_duplicate_audits_or_b1_rerun(): void
    {
        [$lifecycle, $user, $record, $run] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-pending');
        $stagingPath = (string) $record->submission_staging_path;
        $stagingSha = (string) $record->submission_staging_sha256;
        $stagingContent = File::get($this->artifactRoot.'/'.$stagingPath);

        AnnotationRecord::query()->whereKey($record->id)->update([
            'submission_state' => 'promotion_pending',
            'lock_version' => (int) $record->lock_version + 1,
            'updated_at' => now(),
        ]);
        $pending = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame('promotion_pending', $pending->submission_state);
        $this->assertFileExists($this->artifactRoot.'/'.$stagingPath);
        $this->assertFileDoesNotExist($this->artifactRoot.'/submitted/'.$record->record_id.'/record.json');

        $validationRunCountBefore = AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count();
        $promotionPendingAuditsBefore = AnnotationReviewAction::query()
            ->where('annotation_record_id', $record->id)
            ->where('action', 'submission_promotion_pending')
            ->count();
        $finalizedAuditsBefore = AnnotationReviewAction::query()
            ->where('annotation_record_id', $record->id)
            ->where('action', 'submission_finalized')
            ->count();

        $result = $lifecycle->promoteAndFinalizeSubmissionIntent($pending, $user, (int) $pending->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $runFresh = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();

        $this->assertFalse($result['idempotent']);
        $this->assertTrue($result['evidence_updated']);
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame('validated', $fresh->workflow_status);
        $this->assertSame('submitted_finalized', $fresh->artifact_state);
        $this->assertSame($stagingSha, $fresh->submitted_artifact_sha256);
        $this->assertSame($stagingContent, File::get($this->artifactRoot.'/'.(string) $fresh->submitted_json_path));
        $this->assertSame(hash('sha256', $stagingContent), hash_file('sha256', $this->artifactRoot.'/'.(string) $fresh->submitted_json_path));
        $this->assertSame('final_artifact', $runFresh->input_evidence_state);
        $this->assertSame($fresh->submitted_json_path, $runFresh->durable_input_evidence_path);
        $this->assertSame($fresh->submitted_artifact_sha256, $runFresh->durable_input_evidence_sha256);
        $this->assertSame($validationRunCountBefore, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame($promotionPendingAuditsBefore, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_promotion_pending')->count());
        $this->assertSame($finalizedAuditsBefore + 1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
    }

    public function test_promote_rejects_stale_lock_discarded_and_already_finalized_without_idempotent_match(): void
    {
        [$lifecycle, $user, $record] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-a');
        try {
            $lifecycle->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version - 1);
            $this->fail('Expected stale lock rejection.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('Stale lock_version', $e->getMessage());
        }

        [$lifecycle2, $user2, $record2] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-b');
        AnnotationRecord::query()->whereKey($record2->id)->update(['discarded_at' => now(), 'discard_reason' => 'x']);
        $discarded = AnnotationRecord::query()->whereKey($record2->id)->firstOrFail();
        try {
            $lifecycle2->promoteAndFinalizeSubmissionIntent($discarded, $user2, (int) $discarded->lock_version);
            $this->fail('Expected discarded rejection.');
        } catch (AnnotationLifecycleConflictException $e) {
            $this->assertStringContainsString('Discarded', $e->getMessage());
        }

        [$lifecycle3, $user3, $record3] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-c');
        $lifecycle3->promoteAndFinalizeSubmissionIntent($record3, $user3, (int) $record3->lock_version);
        $finalized = AnnotationRecord::query()->whereKey($record3->id)->firstOrFail();
        File::put($this->artifactRoot.'/'.$finalized->submitted_json_path, '{"tampered":true}');
        try {
            $lifecycle3->promoteAndFinalizeSubmissionIntent($finalized, $user3, (int) $finalized->lock_version);
            $this->fail('Expected inconsistent finalized rejection.');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }
    }

    public function test_promote_handles_existing_matching_final_artifact_and_conflicting_final_artifact(): void
    {
        [$lifecycle, $user, $record] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-a');
        $store = new AnnotationArtifactStore;
        $published = $store->publishFinalSubmittedArtifact(
            (string) $record->record_id,
            (string) $record->submission_staging_path,
            (string) $record->submission_staging_sha256,
            (string) $record->submission_staging_sha256,
            false
        );
        $this->assertFalse($published['idempotent']);

        $result = $lifecycle->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame($published['sha256'], $fresh->submitted_artifact_sha256);
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());

        [$lifecycle2, $user2, $record2] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-b');
        $conflictPath = $this->artifactRoot.'/submitted/'.$record2->record_id.'/record.json';
        File::ensureDirectoryExists(dirname($conflictPath));
        File::put($conflictPath, '{"different":true}');
        try {
            $lifecycle2->promoteAndFinalizeSubmissionIntent($record2, $user2, (int) $record2->lock_version);
            $this->fail('Expected conflicting final hash failure.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('different hash', strtolower($e->getMessage()));
        }
        $fresh2 = AnnotationRecord::query()->whereKey($record2->id)->firstOrFail();
        $this->assertSame('promotion_pending', $fresh2->submission_state);
        $this->assertSame('draft', $fresh2->workflow_status);
        $this->assertNull($fresh2->submitted_json_path);
        $this->assertSame('{"different":true}', File::get($conflictPath));
    }

    public function test_promote_retry_after_final_publication_before_db_finalization_is_safe(): void
    {
        [$lifecycle, $user, $record] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-a');
        $store = new AnnotationArtifactStore;
        $store->publishFinalSubmittedArtifact(
            (string) $record->record_id,
            (string) $record->submission_staging_path,
            (string) $record->submission_staging_sha256,
            (string) $record->submission_staging_sha256,
            false
        );
        AnnotationRecord::query()->whereKey($record->id)->update([
            'submission_state' => 'promotion_pending',
            'lock_version' => (int) $record->lock_version + 1,
        ]);
        $pending = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();

        $result = $lifecycle->promoteAndFinalizeSubmissionIntent($pending, $user, (int) $pending->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame('validated', $fresh->workflow_status);
        $this->assertNotNull($fresh->submitted_json_path);
        $this->assertTrue($result['evidence_updated']);
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
    }

    public function test_promote_retry_after_finalization_before_evidence_update_is_evidence_only(): void
    {
        [$lifecycle, $user, $record, $run] = $this->makePromotionReadyRecord(true);
        $result = $lifecycle->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
        $finalized = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $lockAfterFinalize = (int) $finalized->lock_version;

        AnnotationValidationRun::query()->whereKey($run->id)->update([
            'input_evidence_state' => 'staged_path',
            'durable_input_evidence_path' => null,
            'durable_input_evidence_sha256' => null,
        ]);
        $tmpPath = $this->artifactRoot.'/submitted/'.$record->record_id.'/.record.json.promote-retry.tmp';
        File::put($tmpPath, File::get($this->artifactRoot.'/'.$finalized->submitted_json_path));

        $retry = $lifecycle->promoteAndFinalizeSubmissionIntent($finalized, $user, $lockAfterFinalize);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $runFresh = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();

        $this->assertTrue($retry['idempotent']);
        $this->assertTrue($retry['evidence_updated']);
        $this->assertSame($lockAfterFinalize, (int) $fresh->lock_version);
        $this->assertSame('final_artifact', $runFresh->input_evidence_state);
        $this->assertSame($fresh->submitted_json_path, $runFresh->durable_input_evidence_path);
        $this->assertFalse(File::exists($tmpPath));
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
    }

    public function test_repeated_finalized_call_is_idempotent_and_does_not_duplicate_audits(): void
    {
        [$lifecycle, $user, $record] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-a');
        $first = $lifecycle->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
        $finalized = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $second = $lifecycle->promoteAndFinalizeSubmissionIntent($finalized, $user, (int) $finalized->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame((int) $finalized->lock_version, (int) $fresh->lock_version);
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_promotion_pending')->count());
    }

    public function test_repeated_finalized_call_is_idempotent_and_does_not_rerun_b1_or_duplicate_audits(): void
    {
        [$lifecycle, $user, $record] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-c');
        $first = $lifecycle->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
        $finalized = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $validationRunCount = AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count();
        $auditCount = AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count();
        $second = $lifecycle->promoteAndFinalizeSubmissionIntent($finalized, $user, (int) $finalized->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame((int) $finalized->lock_version, (int) $fresh->lock_version);
        $this->assertSame($validationRunCount, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame($auditCount, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
    }

    public function test_finalization_tx_failure_allows_safe_retry_without_duplicate_audit(): void
    {
        [$lifecycleBase, $user, $record, $run] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-txf');
        $store = new AnnotationArtifactStore;
        $stagingPath = (string) $record->submission_staging_path;
        $stagingSha = (string) $record->submission_staging_sha256;
        $store->publishFinalSubmittedArtifact((string) $record->record_id, $stagingPath, $stagingSha, $stagingSha, false);
        AnnotationRecord::query()->whereKey($record->id)->update([
            'submission_state' => 'promotion_pending',
            'lock_version' => (int) $record->lock_version + 1,
        ]);
        $pending = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();

        $failFinalize = true;
        AnnotationReviewAction::creating(function (AnnotationReviewAction $action) use (&$failFinalize, $record): void {
            if ($failFinalize && $action->annotation_record_id === $record->id && $action->action === 'submission_finalized') {
                throw new \RuntimeException('Injected finalization failure.');
            }
        });

        $threw = null;
        try {
            $lifecycleBase->promoteAndFinalizeSubmissionIntent($pending, $user, (int) $pending->lock_version);
        } catch (\Throwable $e) {
            $threw = $e;
        }
        AnnotationReviewAction::clearBootedModels();
        $this->assertInstanceOf(\RuntimeException::class, $threw);
        $this->assertSame('Injected finalization failure.', $threw?->getMessage());

        $afterFail = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $this->assertSame('promotion_pending', $afterFail->submission_state);
        $this->assertNull($afterFail->submitted_json_path);
        $this->assertSame(0, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
        $this->assertFileExists($this->artifactRoot.'/submitted/'.$record->record_id.'/record.json');

        $failFinalize = false;
        $retry = $lifecycleBase->promoteAndFinalizeSubmissionIntent($afterFail, $user, (int) $afterFail->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $runFresh = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame('validated', $fresh->workflow_status);
        $this->assertSame('final_artifact', $runFresh->input_evidence_state);
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
        $this->assertSame(1, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
        $this->assertFalse($retry['idempotent'] && $retry['record']->submission_state !== 'finalized');
    }

    public function test_evidence_tx_failure_after_finalization_allows_evidence_only_retry(): void
    {
        [$lifecycleBase, $user, $record, $run] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-evf');
        $store = new class extends AnnotationArtifactStore
        {
            public bool $failEvidenceVerify = false;

            public string $finalPath = '';

            public function verifyStoredArtifact(string $relativePath, string $expectedSha256): array
            {
                if ($this->failEvidenceVerify && $relativePath === $this->finalPath) {
                    $this->failEvidenceVerify = false; // fail once inside evidence tx
                    throw new ArtifactStorageException('Injected evidence verification failure.');
                }

                return parent::verifyStoredArtifact($relativePath, $expectedSha256);
            }
        };
        $svc = new AnnotationRecordLifecycleService($store);
        // precompute final path by publishing through normal flow? easier: run promotion, intercept by setting flag when final exists.
        // Instead, wrap with a store that toggles after finalization commit by checking record state in verifyStoredArtifact.
        $store2 = new class extends AnnotationArtifactStore
        {
            public bool $failed = false;

            public function verifyStoredArtifact(string $relativePath, string $expectedSha256): array
            {
                if (! $this->failed && str_contains($relativePath, '/record.json')) {
                    $recordId = basename(dirname($relativePath));
                    $rec = AnnotationRecord::query()->where('record_id', $recordId)->first();
                    if ($rec && $rec->submission_state === 'finalized') {
                        $this->failed = true;
                        throw new ArtifactStorageException('Injected evidence verification failure.');
                    }
                }

                return parent::verifyStoredArtifact($relativePath, $expectedSha256);
            }
        };
        $svc2 = new AnnotationRecordLifecycleService($store2);

        try {
            $svc2->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
            $this->fail('Expected evidence failure.');
        } catch (ArtifactStorageException $e) {
            $this->assertStringContainsString('Injected evidence verification failure', $e->getMessage());
        }

        $finalized = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $runAfter = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();
        $this->assertSame('finalized', $finalized->submission_state);
        $this->assertSame('validated', $finalized->workflow_status);
        $this->assertSame('staged_path', $runAfter->input_evidence_state);
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
        $this->assertFileExists($this->artifactRoot.'/'.(string) $finalized->submitted_json_path);

        $retry = $lifecycleBase->promoteAndFinalizeSubmissionIntent($finalized, $user, (int) $finalized->lock_version);
        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $runFresh = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();
        $this->assertTrue($retry['idempotent']);
        $this->assertTrue($retry['evidence_updated']);
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame('final_artifact', $runFresh->input_evidence_state);
        $this->assertSame($fresh->submitted_json_path, $runFresh->durable_input_evidence_path);
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());
        $this->assertSame(1, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
    }

    public function test_cleanup_failure_after_evidence_commit_is_nonfatal_and_retryable(): void
    {
        [$lifecycle, $user, $record, $run] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-clean');
        $store = new class extends AnnotationArtifactStore
        {
            public bool $failCleanup = true;

            public function cleanupPromotionTempAfterEvidenceCommit(string $recordId, string $promotionTmpRelativePath, string $expectedSha256, ?string $secondExpectedSha256 = null): array
            {
                if ($this->failCleanup) {
                    throw new ArtifactStorageException('Injected cleanup failure.');
                }

                return parent::cleanupPromotionTempAfterEvidenceCommit($recordId, $promotionTmpRelativePath, $expectedSha256, $secondExpectedSha256);
            }
        };
        $svc = new AnnotationRecordLifecycleService($store);
        $beforeRuns = AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count();

        try {
            $svc->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
            $this->fail('Expected cleanup failure.');
        } catch (ArtifactStorageException $e) {
            $this->assertStringContainsString('Injected cleanup failure', $e->getMessage());
        }

        $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
        $runFresh = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();
        $this->assertSame('finalized', $fresh->submission_state);
        $this->assertSame('validated', $fresh->workflow_status);
        $this->assertSame('final_artifact', $runFresh->input_evidence_state);
        $this->assertFileExists($this->artifactRoot.'/'.(string) $fresh->submitted_json_path);
        $this->assertFileExists($this->artifactRoot.'/'.(string) $fresh->submission_staging_path);
        $this->assertNotEmpty(glob($this->artifactRoot.'/submitted/'.$record->record_id.'/.record.json.promote-*.tmp') ?: []);
        $this->assertSame($beforeRuns, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame(1, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->where('action', 'submission_finalized')->count());

        $store->failCleanup = false;
        $retry = $svc->promoteAndFinalizeSubmissionIntent($fresh, $user, (int) $fresh->lock_version);
        $this->assertTrue($retry['idempotent']);
        $this->assertSame($beforeRuns, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame([], glob($this->artifactRoot.'/submitted/'.$record->record_id.'/.record.json.promote-*.tmp') ?: []);
    }

    public function test_promote_nested_store_operations_reuse_promotion_lock_and_cleanup_waits_for_evidence_commit(): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(10);
        }

        try {
            [$lifecycleBase, $user, $record, $run] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-reentrant');
            $store = new class extends AnnotationArtifactStore
            {
                public int $lockEntries = 0;

                public int $maxLockEntries = 0;

                public bool $publishObservedOuterLock = false;

                public bool $cleanupObservedOuterLock = false;

                public bool $cleanupObservedEvidenceCommitted = false;

                public ?int $lockEntriesAfterCallback = null;

                public function withRecordPromotionLock(string $recordId, callable $callback): mixed
                {
                    $this->lockEntries++;
                    $this->maxLockEntries = max($this->maxLockEntries, $this->lockEntries);
                    try {
                        return parent::withRecordPromotionLock($recordId, $callback);
                    } finally {
                        $this->lockEntries--;
                        $this->lockEntriesAfterCallback = $this->lockEntries;
                    }
                }

                public function publishFinalSubmittedArtifact(string $recordId, string $stagingRelativePath, string $expectedSha256, ?string $secondExpectedSha256 = null, bool $cleanupPromotionTemp = true): array
                {
                    $this->publishObservedOuterLock = $this->lockEntries > 0;

                    return parent::publishFinalSubmittedArtifact($recordId, $stagingRelativePath, $expectedSha256, $secondExpectedSha256, $cleanupPromotionTemp);
                }

                public function cleanupPromotionTempAfterEvidenceCommit(string $recordId, string $promotionTmpRelativePath, string $expectedSha256, ?string $secondExpectedSha256 = null): array
                {
                    $this->cleanupObservedOuterLock = $this->lockEntries > 0;
                    $this->cleanupObservedEvidenceCommitted = AnnotationValidationRun::query()
                        ->where('annotation_record_id', function ($query) use ($recordId) {
                            $query->select('id')->from('annotation_records')->where('record_id', $recordId)->limit(1);
                        })
                        ->where('input_evidence_state', 'final_artifact')
                        ->exists();

                    return parent::cleanupPromotionTempAfterEvidenceCommit($recordId, $promotionTmpRelativePath, $expectedSha256, $secondExpectedSha256);
                }
            };

            $svc = new AnnotationRecordLifecycleService($store);
            $result = $svc->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);

            $fresh = AnnotationRecord::query()->whereKey($record->id)->firstOrFail();
            $runFresh = AnnotationValidationRun::query()->whereKey($run->id)->firstOrFail();

            $this->assertFalse($result['idempotent']);
            $this->assertTrue($result['evidence_updated']);
            $this->assertSame('finalized', $fresh->submission_state);
            $this->assertSame('final_artifact', $runFresh->input_evidence_state);
            $this->assertTrue($store->publishObservedOuterLock);
            $this->assertTrue($store->cleanupObservedOuterLock);
            $this->assertTrue($store->cleanupObservedEvidenceCommitted);
            $this->assertGreaterThanOrEqual(2, $store->maxLockEntries);
            $this->assertSame(0, $store->lockEntries);
            $this->assertSame(0, $store->lockEntriesAfterCallback);
            $this->assertSame([], glob($this->artifactRoot.'/submitted/'.$record->record_id.'/.record.json.promote-*.tmp') ?: []);
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
        }
    }

    public function test_promote_fails_closed_on_lock_failure_without_db_or_fs_mutation(): void
    {
        [$lifecycle, $user, $record] = $this->makePromotionReadyRecord(true, [], [], 'rec-promote-lock');
        $baseStore = new AnnotationArtifactStore;
        $inspectionBefore = $baseStore->inspectRecordArtifacts((string) $record->record_id);
        $recordBefore = AnnotationRecord::query()->whereKey($record->id)->firstOrFail()->getAttributes();
        $runCountBefore = AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count();
        $auditCountBefore = AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count();

        $failingStore = new class extends AnnotationArtifactStore
        {
            public function withRecordPromotionLock(string $recordId, callable $callback): mixed
            {
                throw new ArtifactLockException('Injected lock failure.');
            }
        };
        $svc = new AnnotationRecordLifecycleService($failingStore);

        try {
            $svc->promoteAndFinalizeSubmissionIntent($record, $user, (int) $record->lock_version);
            $this->fail('Expected lock failure.');
        } catch (ArtifactLockException $e) {
            $this->assertStringContainsString('Injected lock failure', $e->getMessage());
        }

        $recordAfter = AnnotationRecord::query()->whereKey($record->id)->firstOrFail()->getAttributes();
        $inspectionAfter = $baseStore->inspectRecordArtifacts((string) $record->record_id);
        $this->assertSame($recordBefore['submission_state'], $recordAfter['submission_state']);
        $this->assertSame($recordBefore['workflow_status'], $recordAfter['workflow_status']);
        $this->assertSame($recordBefore['artifact_state'], $recordAfter['artifact_state']);
        $this->assertSame($recordBefore['lock_version'], $recordAfter['lock_version']);
        $this->assertNull($recordAfter['submitted_json_path']);
        $this->assertSame($runCountBefore, AnnotationValidationRun::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame($auditCountBefore, AnnotationReviewAction::query()->where('annotation_record_id', $record->id)->count());
        $this->assertSame($inspectionBefore['final_artifact'], $inspectionAfter['final_artifact']);
        $this->assertCount(count($inspectionBefore['promotion_temps']), $inspectionAfter['promotion_temps']);
    }

    private function bindInfraB1Persistence(string $code = 'B1_PROCESS_FAILED', bool $timedOut = false, ?int $exitCode = 2): AnnotationValidationPersistenceService
    {
        $this->configureB1AdapterConfig();
        $runner = new class($code, $timedOut, $exitCode) extends B1ProcessRunner
        {
            public function __construct(private string $code, private bool $timedOut, private ?int $exitCode) {}

            public function run(array $command, string $cwd, int $timeoutSeconds, ?array $env = null): array
            {
                if (in_array('--version', $command, true) || (in_array('-c', $command, true))) {
                    return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
                }

                return ['exit_code' => $this->exitCode, 'stdout' => '', 'stderr' => $this->code, 'timed_out' => $this->timedOut, 'duration_ms' => 5];
            }
        };

        $adapter = new B1ValidationAdapter($runner, config('annotation'));
        $service = new AnnotationValidationPersistenceService($adapter, new AnnotationArtifactStore);
        $this->app->instance(AnnotationValidationPersistenceService::class, $service);

        return $service;
    }
}
