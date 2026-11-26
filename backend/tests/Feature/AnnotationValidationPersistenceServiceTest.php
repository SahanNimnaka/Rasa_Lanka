<?php

namespace Tests\Feature;

use App\Models\AnnotationRecord;
use App\Models\AnnotationReviewAction;
use App\Models\AnnotationValidationIssue;
use App\Models\AnnotationValidationRun;
use App\Models\User;
use App\Services\Annotation\AnnotationValidationPersistenceService;
use App\Services\Annotation\B1ProcessRunner;
use App\Services\Annotation\B1ValidationAdapter;
use App\Services\Annotation\Storage\AnnotationArtifactStore;
use App\Services\Annotation\Storage\Exceptions\ArtifactHashMismatchException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AnnotationValidationPersistenceServiceTest extends TestCase
{
    private string $artifactRoot;

    private string $b1Temp;

    protected function setUp(): void
    {

        parent::setUp();

        $this->artifactRoot = storage_path('framework/testing/annotation-validation-persistence-'.uniqid('', true));

        $this->b1Temp = storage_path('framework/testing/b1-validation-persistence-'.uniqid('', true));

        File::deleteDirectory($this->artifactRoot);

        File::deleteDirectory($this->b1Temp);

        config([

            'database.default' => 'sqlite',

            'database.connections.sqlite.database' => ':memory:',

            'database.connections.sqlite.foreign_key_constraints' => true,

            'annotation.annotation_storage_disk' => 'annotation',

            'annotation.annotation_storage_driver' => 'local',

            'annotation.annotation_artifact_root' => $this->artifactRoot,

            'annotation.annotation_validation_report_path' => 'validation-runs',

            'annotation.b1_temp_dir' => $this->b1Temp,

            'annotation.b1_timeout_seconds' => 60,

            'annotation.b1_workdir' => base_path('..'),

            'filesystems.disks.annotation' => [

                'driver' => 'local',

                'root' => $this->artifactRoot,

                'serve' => false,

                'throw' => true,

                'report' => false,

            ],

        ]);

        DB::purge('sqlite');

        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);

    }

    protected function tearDown(): void
    {

        File::deleteDirectory($this->artifactRoot);

        File::deleteDirectory($this->b1Temp);

        DB::purge('sqlite');

        parent::tearDown();

    }

    public function test_completed_b1_persists_run_issues_audit_and_exact_report_artifacts(): void
    {

        $record = $this->createRecord();

        $store = new AnnotationArtifactStore;

        $staging = $store->writeSubmissionStaging($record->record_id, 'intent-1', '{"record":"ok"}');

        $adapter = new B1ValidationAdapter($this->completedRunner(), config('annotation'));

        $service = new AnnotationValidationPersistenceService($adapter, $store);

        $result = $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging['relative_path'], $staging['sha256'], $record->created_by_user_id, 'user', 'b1-test');

        $run = $result['run'];

        $this->assertSame('completed', $run->status);

        $this->assertTrue($run->is_valid);

        $this->assertSame($staging['relative_path'], $run->input_artifact_path);

        $this->assertSame($staging['sha256'], $run->input_artifact_sha256);

        $this->assertSame('staged_path', $run->input_evidence_state);

        $this->assertNotNull($run->report_json_path);

        $this->assertNotNull($run->report_json_sha256);

        $this->assertDatabaseCount('annotation_validation_issues', 1);

        $issue = AnnotationValidationIssue::query()->firstOrFail();

        $this->assertSame($run->id, $issue->validation_run_id);

        $this->assertDatabaseHas('annotation_review_actions', [

            'annotation_record_id' => $record->id,

            'action' => 'validation_completed',

            'actor_type' => 'user',

        ]);

        $reportAbs = $store->resolveRelativePath((string) $run->report_json_path, true);

        $reportRaw = File::get($reportAbs);

        $this->assertStringContainsString('"summary"', $reportRaw);

        $this->assertSame(hash('sha256', $reportRaw), $run->report_json_sha256);

    }

    public function test_infra_failure_persists_infra_run_and_audit_atomically(): void
    {

        $record = $this->createRecord();

        $store = new AnnotationArtifactStore;

        $staging = $store->writeSubmissionStaging($record->record_id, 'intent-1', '{"record":"ok"}');

        $adapter = new B1ValidationAdapter($this->timeoutRunner(), config('annotation'));

        $service = new AnnotationValidationPersistenceService($adapter, $store);

        $result = $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging['relative_path'], $staging['sha256'], $record->created_by_user_id);

        $run = $result['run'];

        $this->assertSame('infra_failed', $run->status);

        $this->assertSame('B1_TIMEOUT', $run->infra_error_code);

        $this->assertNull($run->is_valid);

        $this->assertSame($staging['sha256'], $run->input_artifact_sha256);

        $this->assertDatabaseCount('annotation_validation_issues', 0);

        $this->assertDatabaseHas('annotation_review_actions', [

            'annotation_record_id' => $record->id,

            'action' => 'validation_infra_failed',

        ]);

        $this->assertNull($result['report_json']);

    }

    public function test_hash_mismatch_fails_closed_before_persistence(): void
    {

        $record = $this->createRecord();

        $store = new AnnotationArtifactStore;

        $staging = $store->writeSubmissionStaging($record->record_id, 'intent-1', '{"record":"ok"}');

        $adapter = new B1ValidationAdapter($this->completedRunner(), config('annotation'));

        $service = new AnnotationValidationPersistenceService($adapter, $store);

        $this->expectException(ArtifactHashMismatchException::class);

        try {

            $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging['relative_path'], str_repeat('b', 64), $record->created_by_user_id);

        } finally {

            $this->assertDatabaseCount('annotation_validation_runs', 0);

            $this->assertDatabaseCount('annotation_review_actions', 0);

        }

    }

    public function test_duplicate_same_record_and_submission_intent_is_rejected(): void
    {

        $record = $this->createRecord();

        $store = new AnnotationArtifactStore;

        $staging = $store->writeSubmissionStaging($record->record_id, 'intent-1', '{"record":"ok"}');

        $adapter = new B1ValidationAdapter($this->completedRunner(), config('annotation'));

        $service = new AnnotationValidationPersistenceService($adapter, $store);

        $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging['relative_path'], $staging['sha256'], $record->created_by_user_id);

        $this->expectException(RuntimeException::class);

        $this->expectExceptionMessage('validation run already exists');

        $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging['relative_path'], $staging['sha256'], $record->created_by_user_id);

    }

    public function test_database_unique_constraint_rejects_duplicate_non_null_intent(): void
    {

        $record = $this->createRecord();

        $base = $this->baseRunAttributes($record, 'intent-1');

        AnnotationValidationRun::query()->create($base);

        $this->expectException(QueryException::class);

        AnnotationValidationRun::query()->create(array_merge($base, ['run_id' => 'run-2']));

    }

    public function test_different_submission_intents_for_same_record_are_allowed(): void
    {

        $record = $this->createRecord();

        $store = new AnnotationArtifactStore;

        $staging1 = $store->writeSubmissionStaging($record->record_id, 'intent-1', '{"record":"ok"}');

        $staging2 = $store->writeSubmissionStaging($record->record_id, 'intent-2', '{"record":"ok2"}');

        $adapter = new B1ValidationAdapter($this->completedRunner(), config('annotation'));

        $service = new AnnotationValidationPersistenceService($adapter, $store);

        $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging1['relative_path'], $staging1['sha256'], $record->created_by_user_id);

        $service->persistStagedSubmissionAttempt($record, 'intent-2', $staging2['relative_path'], $staging2['sha256'], $record->created_by_user_id);

        $this->assertDatabaseCount('annotation_validation_runs', 2);

    }

    public function test_null_submission_intent_behavior_matches_database_semantics(): void
    {

        $record = $this->createRecord();

        $base = $this->baseRunAttributes($record, null);

        AnnotationValidationRun::query()->create($base);

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {

            // SQLite unique indexes allow multiple NULLs; documented compatibility limitation.

            AnnotationValidationRun::query()->create(array_merge($base, ['run_id' => 'run-2']));

            $this->assertDatabaseCount('annotation_validation_runs', 2);

            return;

        }

        $this->expectException(QueryException::class);

        AnnotationValidationRun::query()->create(array_merge($base, ['run_id' => 'run-2']));

    }

    public function test_duplicate_constraint_violation_is_translated_to_fail_closed_domain_error(): void
    {

        $record = $this->createRecord();

        $store = new AnnotationArtifactStore;

        $staging = $store->writeSubmissionStaging($record->record_id, 'intent-1', '{"record":"ok"}');

        $adapter = new B1ValidationAdapter($this->completedRunner(), config('annotation'));

        $service = new AnnotationValidationPersistenceService($adapter, $store);

        // Simulate race: another attempt wins DB insert after Phase A/precheck window.

        // This is a sequential simulation, not a genuine concurrent multi-process test.

        AnnotationValidationRun::query()->create($this->baseRunAttributes($record, 'intent-1'));

        try {

            $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging['relative_path'], $staging['sha256'], $record->created_by_user_id);

            $this->fail('Expected duplicate-attempt RuntimeException.');

        } catch (RuntimeException $e) {

            $this->assertSame('A validation run already exists for this record and submission intent.', $e->getMessage());

            $this->assertDatabaseCount('annotation_validation_runs', 1);

            $this->assertDatabaseCount('annotation_validation_issues', 0);

            $this->assertDatabaseCount('annotation_review_actions', 0);

        }

    }

    public function test_run_id_unique_violation_is_not_translated_to_duplicate_attempt_error(): void
    {

        $record = $this->createRecord();

        AnnotationValidationRun::query()->create(array_merge($this->baseRunAttributes($record, 'intent-1'), ['run_id' => 'run-1']));

        $service = new AnnotationValidationPersistenceService(

            new B1ValidationAdapter($this->completedRunner(), config('annotation')),

            new AnnotationArtifactStore

        );

        $ref = new \ReflectionClass($service);

        $method = $ref->getMethod('isDuplicateAttemptConstraintViolation');

        $method->setAccessible(true);

        $previous = new \PDOException('UNIQUE constraint failed: annotation_validation_runs.run_id');

        $previous->errorInfo = ['23000', 19, 'UNIQUE constraint failed: annotation_validation_runs.run_id'];

        $e = new QueryException(

            'sqlite',

            'insert into "annotation_validation_runs" ("run_id") values (?)',

            ['run-1'],

            $previous

        );

        $this->assertFalse($method->invoke($service, $e, (int) $record->id, 'intent-1'));

    }

    public function test_foreign_key_violation_is_not_translated_to_duplicate_attempt_error(): void
    {

        $record = $this->createRecord();

        AnnotationValidationRun::query()->create($this->baseRunAttributes($record, 'intent-1'));

        $service = new AnnotationValidationPersistenceService(

            new B1ValidationAdapter($this->completedRunner(), config('annotation')),

            new AnnotationArtifactStore

        );

        $ref = new \ReflectionClass($service);

        $method = $ref->getMethod('isDuplicateAttemptConstraintViolation');

        $method->setAccessible(true);

        $previous = new \PDOException('FOREIGN KEY constraint failed');

        $previous->errorInfo = ['23000', 19, 'FOREIGN KEY constraint failed'];

        $e = new QueryException(

            'sqlite',

            'insert into "annotation_validation_issues" ("validation_run_id") values (?)',

            [99999],

            $previous

        );

        $this->assertFalse($method->invoke($service, $e, (int) $record->id, 'intent-1'));

    }

    public function test_unrelated_database_exceptions_are_not_translated_to_duplicate_errors(): void
    {

        $record = $this->createRecord();

        $store = new AnnotationArtifactStore;

        $staging = $store->writeSubmissionStaging($record->record_id, 'intent-1', '{"record":"ok"}');

        $adapter = new B1ValidationAdapter($this->completedRunner(), config('annotation'));

        $service = new AnnotationValidationPersistenceService($adapter, $store);

        AnnotationReviewAction::creating(function () {

            throw new QueryException('sqlite', 'insert into annotation_review_actions ...', [], new RuntimeException('boom'));
        });

        try {

            $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging['relative_path'], $staging['sha256'], $record->created_by_user_id);

            $this->fail('Expected QueryException.');

        } catch (QueryException $e) {

            $this->assertStringContainsString('annotation_review_actions', $e->getMessage());

        } catch (RuntimeException $e) {

            $this->fail('Unrelated QueryException must not be translated to duplicate RuntimeException: '.$e->getMessage());

        } finally {

            AnnotationReviewAction::clearBootedModels();

        }

        $this->assertDatabaseCount('annotation_validation_runs', 0);

        $this->assertDatabaseCount('annotation_validation_issues', 0);

        $this->assertDatabaseCount('annotation_review_actions', 0);

    }

    public function test_unique_attempt_index_migration_is_present(): void
    {

        $indexes = Schema::getIndexes('annotation_validation_runs');

        $names = array_map(fn ($i) => $i['name'] ?? '', $indexes);

        $this->assertTrue(in_array('annotation_validation_runs_record_intent_unique', $names, true));

    }

    public function test_db_failure_rolls_back_run_issues_and_audit_together(): void
    {

        $record = $this->createRecord();

        $store = new AnnotationArtifactStore;

        $staging = $store->writeSubmissionStaging($record->record_id, 'intent-1', '{"record":"ok"}');

        $adapter = new B1ValidationAdapter($this->completedRunner(), config('annotation'));

        $service = new AnnotationValidationPersistenceService($adapter, $store);

        AnnotationReviewAction::creating(function () {

            throw new RuntimeException('simulated audit failure');
        });

        try {

            $service->persistStagedSubmissionAttempt($record, 'intent-1', $staging['relative_path'], $staging['sha256'], $record->created_by_user_id);

            $this->fail('Expected failure.');

        } catch (RuntimeException $e) {

            $this->assertSame('simulated audit failure', $e->getMessage());

        } finally {

            AnnotationReviewAction::clearBootedModels();

        }

        $this->assertDatabaseCount('annotation_validation_runs', 0);

        $this->assertDatabaseCount('annotation_validation_issues', 0);

        $this->assertDatabaseCount('annotation_review_actions', 0);

        // Report artifacts are deterministic and immutable; if they were written before DB tx failure,

        // they remain inspectable and may be reused idempotently by reconciliation/future safe logic.

        $this->assertNotEmpty(glob($this->artifactRoot.'/validation-runs/'.$record->record_id.'/*/step8b_validation_report.json') ?: []);

    }

    private function createRecord(): AnnotationRecord
    {

        $user = User::factory()->create();

        $record = new AnnotationRecord;

        $record->forceFill([

            'record_id' => 'record-'.str_replace('.', '-', uniqid('', true)),

            'schema_version' => 'human-recognition-v1',

            'recognition_method' => 'individual',

            'verification_status' => 'pending_confirmation',

            'workflow_status' => 'draft',

            'artifact_state' => 'draft',

            'submission_state' => 'staging_written',

            'created_by_user_id' => $user->id,

            'lock_version' => 1,

            'is_export_eligible_canonical' => false,

        ]);

        $record->save();

        return $record->fresh();

    }

    /**
     * @return array<string,mixed>
     */
    private function baseRunAttributes(AnnotationRecord $record, ?string $submissionIntentId): array
    {

        return [

            'run_id' => 'run-1',

            'annotation_record_id' => $record->id,

            'submission_intent_id' => $submissionIntentId,

            'b1_version' => 'b1-test',

            'input_artifact_path' => 'submitted/'.$record->record_id.'/staging/intent.json',

            'input_artifact_sha256' => str_repeat('a', 64),

            'input_evidence_state' => 'staged_path',

            'durable_input_evidence_path' => 'submitted/'.$record->record_id.'/staging/intent.json',

            'durable_input_evidence_sha256' => str_repeat('a', 64),

            'status' => 'infra_failed',

            'infra_error_code' => 'B1_TIMEOUT',

            'infra_error_message' => 'timeout',

            'timed_out' => true,

            'is_valid' => null,

            'is_export_eligible_canonical' => null,

            'error_count' => 0,

            'warning_count' => 0,

            'export_block_count' => 0,

            'executed_by_user_id' => $record->created_by_user_id,

            'executed_at' => now(),

        ];

    }

    private function completedRunner(): B1ProcessRunner
    {

        $runner = $this->mock(B1ProcessRunner::class);

        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {

            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {

                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];

            }

            $outDir = $command[array_search('--out-dir', $command, true) + 1];

            File::ensureDirectoryExists($outDir);

            File::put($outDir.DIRECTORY_SEPARATOR.'step8b_validation_report.json', json_encode([

                'summary' => ['records_checked' => 1, 'valid_records' => 1, 'invalid_records' => 0, 'export_eligible_canonical_records' => 1, 'error_count' => 1, 'warning_count' => 0, 'export_block_count' => 0, 'issues_by_code' => ['E-SCHEMA' => 1]],

                'results' => [['record_id' => 'R1', 'recognition_method' => 'individual', 'verification_status' => 'confirmed', 'valid' => true, 'export_eligible_canonical' => true, 'export_block_codes' => [], 'resolved_ingredient_labels' => []]],

                'issues' => [['severity' => 'error', 'code' => 'E-SCHEMA', 'record_id' => 'R1', 'image_id' => '', 'field' => 'x', 'message' => 'bad', 'details' => ['k' => 'v']]],

            ]));

            File::put($outDir.DIRECTORY_SEPARATOR.'step8b_validation_issues.csv', "severity,code\nerror,E-SCHEMA\n");

            return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 5];

        });

        return $runner;

    }

    private function timeoutRunner(): B1ProcessRunner
    {

        $runner = $this->mock(B1ProcessRunner::class);

        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {

            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {

                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];

            }

            return ['exit_code' => null, 'stdout' => '', 'stderr' => '', 'timed_out' => true, 'duration_ms' => 60000];

        });

        return $runner;

    }

}
