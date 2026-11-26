<?php

namespace Tests\Feature;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\User;
use App\Services\Annotation\AnnotationRecordLifecycleService;
use App\Services\Annotation\Exceptions\AnnotationLifecycleAuthorizationException;
use App\Services\Annotation\Exceptions\AnnotationLifecycleConflictException;
use App\Services\Annotation\Exceptions\AnnotationLifecycleValidationException;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
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

        $this->artifactRoot = storage_path('framework/testing/annotation-lifecycle-'.uniqid('', true));
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

    private function annotationUser(string $role = User::ROLE_ANNOTATOR): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }
}
