<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\Annotation\B1ProcessRunner;
use App\Services\Annotation\B1ValidationAdapter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class B1ValidationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array',
            'annotation.b1_python_bin' => 'python',
            'annotation.b1_script_path' => base_path('../research/phase-13.3/annotation_pipeline_step8b/scripts/validate_step8b_records.py'),
            'annotation.b1_schema_path' => base_path('../research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json'),
            'annotation.b1_inventory_path' => base_path('../research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv'),
            'annotation.b1_taxonomy_path' => base_path('../research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json'),
            'annotation.b1_decisions_path' => base_path('../research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json'),
            'annotation.b1_timeout_seconds' => 60,
            'annotation.b1_workdir' => base_path('..'),
            'annotation.b1_temp_dir' => storage_path('app/b1-validation-tests'),
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        File::deleteDirectory(storage_path('app/b1-validation-tests'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/b1-validation-tests'));
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_annotator_can_validate_annotation(): void
    {
        $user = $this->makeUser('annotator');
        $response = $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()]);
        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.b1.valid', true);
    }

    public function test_reviewer_can_validate_annotation(): void
    {
        $user = $this->makeUser('reviewer');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])
            ->assertOk()->assertJsonPath('data.b1.valid', true);
    }

    public function test_research_admin_can_validate_annotation(): void
    {
        $user = $this->makeUser('research_admin');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])
            ->assertOk()->assertJsonPath('data.b1.valid', true);
    }

    public function test_unassigned_role_denied(): void
    {
        $user = $this->makeUser(null);
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])->assertStatus(403);
    }

    public function test_unknown_role_denied(): void
    {
        $user = $this->makeUser('unknown_role');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])->assertStatus(403);
    }

    public function test_unauthenticated_request_cannot_validate(): void
    {
        $this->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])->assertStatus(401);
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Mass',
            'email' => 'mass@example.com',
            'password' => 'password123',
            'role' => 'research_admin',
        ]);
        $this->assertNull($user->fresh()->role);
    }

    public function test_self_approval_denied(): void
    {
        $reviewer = $this->makeUser('reviewer');
        $record = (object) ['created_by_user_id' => $reviewer->id];
        $this->assertFalse(Gate::forUser($reviewer)->allows('annotation.approve', $record));
    }

    public function test_admin_self_approval_denied(): void
    {
        $admin = $this->makeUser('research_admin');
        $record = (object) ['created_by_user_id' => $admin->id];
        $this->assertFalse(Gate::forUser($admin)->allows('annotation.approve', $record));
    }

    public function test_reviewer_can_approve_other_users_record(): void
    {
        $reviewer = $this->makeUser('reviewer');
        $record = (object) ['created_by_user_id' => $reviewer->id + 999];
        $this->assertTrue(Gate::forUser($reviewer)->allows('annotation.approve', $record));
    }

    public function test_annotator_cannot_approve(): void
    {
        $annotator = $this->makeUser('annotator');
        $record = (object) ['created_by_user_id' => 999];
        $this->assertFalse(Gate::forUser($annotator)->allows('annotation.approve', $record));
    }

    public function test_real_b1_valid_record_returns_200_exit_0(): void
    {
        $user = $this->makeUser('annotator');
        $response = $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()]);
        $response->assertOk()
            ->assertJsonPath('data.b1.valid', true)
            ->assertJsonPath('data.b1.export_eligible_canonical', true)
            ->assertJsonPath('data.b1.error_count', 0)
            ->assertJsonPath('data.b1.exit_code', 0);
    }

    public function test_real_b1_invalid_record_returns_200_exit_1(): void
    {
        $user = $this->makeUser('annotator');
        $record = $this->validIndividualRecord();
        $record['ingredient_labels'] = ['not_a_real_taxonomy_id'];
        $response = $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $record]);
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.b1.valid', false)
            ->assertJsonPath('data.b1.exit_code', 1);
        $codes = collect($response->json('data.b1.issues'))->pluck('code')->all();
        $this->assertNotEmpty($codes);
    }

    public function test_pending_garlic_export_blocked_real_b1(): void
    {
        $user = $this->makeUser('annotator');
        $record = $this->validIndividualRecord();
        $record['record_id'] = 'HR-IND-PEND-GARLIC';
        $record['ingredient_labels'] = ['Garlic'];
        $response = $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $record]);
        $response->assertOk()->assertJsonPath('data.b1.export_eligible_canonical', false);
        $codes = collect($response->json('data.b1.issues'))->pluck('code')->all();
        $this->assertTrue(collect($codes)->intersect(['E-PENDING-AS-CANONICAL', 'W-PENDING-MAPPING', 'X-PENDING-CANONICAL'])->isNotEmpty());
    }

    public function test_pending_pepper_corn_export_blocked_real_b1(): void
    {
        $user = $this->makeUser('annotator');
        $record = $this->validIndividualRecord();
        $record['record_id'] = 'HR-IND-PEND-PEPPER';
        $record['ingredient_labels'] = ['Pepper Corn'];
        $response = $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $record]);
        $response->assertOk()->assertJsonPath('data.b1.export_eligible_canonical', false);
        $codes = collect($response->json('data.b1.issues'))->pluck('code')->all();
        $this->assertTrue(collect($codes)->intersect(['E-PENDING-AS-CANONICAL', 'W-PENDING-MAPPING', 'X-PENDING-CANONICAL'])->isNotEmpty());
    }

    public function test_group_annotation_export_blocked_real_b1(): void
    {
        $user = $this->makeUser('annotator');
        $response = $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validGroupRecord()]);
        $response->assertOk()->assertJsonPath('data.b1.export_eligible_canonical', false);
        $this->assertContains('X-GROUP-SCOPE', collect($response->json('data.b1.issues'))->pluck('code')->all());
    }

    public function test_missing_python_executable_returns_503(): void
    {
        config(['annotation.b1_python_bin' => 'definitely-missing-python-binary']);
        $user = $this->makeUser('annotator');
        $response = $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()]);
        $response->assertStatus(503)->assertJsonPath('success', false)->assertJsonPath('error.code', 'B1_VALIDATION_UNAVAILABLE');
    }

    public function test_missing_validator_script_returns_503(): void
    {
        config(['annotation.b1_script_path' => base_path('../does-not-exist.py')]);
        $user = $this->makeUser('annotator');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])
            ->assertStatus(503)->assertJsonPath('success', false);
    }

    public function test_process_timeout_returns_503(): void
    {
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {
            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
            return ['exit_code' => null, 'stdout' => '', 'stderr' => 'timeout', 'timed_out' => true, 'duration_ms' => 60000];
        });
        $this->app->instance(B1ValidationAdapter::class, new B1ValidationAdapter($runner, config('annotation')));

        $user = $this->makeUser('annotator');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])
            ->assertStatus(503)->assertJsonPath('error.details.code', 'B1_TIMEOUT');
    }

    public function test_non_zero_unexpected_exit_returns_503(): void
    {
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {
            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
            return ['exit_code' => 2, 'stdout' => '', 'stderr' => 'err', 'timed_out' => false, 'duration_ms' => 10];
        });
        $this->app->instance(B1ValidationAdapter::class, new B1ValidationAdapter($runner, config('annotation')));
        $user = $this->makeUser('annotator');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])
            ->assertStatus(503)->assertJsonPath('error.details.code', 'B1_PROCESS_FAILED');
    }

    public function test_exit_1_with_valid_report_returns_200(): void
    {
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {
            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
            $outDir = $command[array_search('--out-dir', $command, true) + 1];
            File::ensureDirectoryExists($outDir);
            File::put($outDir.DIRECTORY_SEPARATOR.'step8b_validation_report.json', json_encode([
                'summary' => ['records_checked' => 1, 'valid_records' => 0, 'invalid_records' => 1, 'export_eligible_canonical_records' => 0, 'error_count' => 1, 'warning_count' => 0, 'export_block_count' => 0, 'issues_by_code' => ['E-SCHEMA' => 1]],
                'results' => [['record_id' => 'HR-IND-000001', 'recognition_method' => 'individual', 'verification_status' => 'confirmed', 'valid' => false, 'export_eligible_canonical' => false, 'export_block_codes' => [], 'resolved_ingredient_labels' => []]],
                'issues' => [['severity' => 'error', 'code' => 'E-SCHEMA', 'record_id' => 'HR-IND-000001', 'image_id' => '', 'field' => 'ingredient_labels', 'message' => 'bad', 'details' => []]],
            ]));
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 10];
        });
        $this->app->instance(B1ValidationAdapter::class, new B1ValidationAdapter($runner, config('annotation')));
        $user = $this->makeUser('annotator');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])
            ->assertOk()->assertJsonPath('data.b1.valid', false)->assertJsonPath('data.b1.exit_code', 1);
    }

    public function test_missing_report_returns_503(): void
    {
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {
            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'err', 'timed_out' => false, 'duration_ms' => 10];
        });
        $this->app->instance(B1ValidationAdapter::class, new B1ValidationAdapter($runner, config('annotation')));
        $user = $this->makeUser('annotator');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])
            ->assertStatus(503)->assertJsonPath('error.details.code', 'B1_REPORT_MISSING');
    }

    public function test_corrupt_report_returns_503(): void
    {
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {
            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
            $outDir = $command[array_search('--out-dir', $command, true) + 1];
            File::ensureDirectoryExists($outDir);
            File::put($outDir.DIRECTORY_SEPARATOR.'step8b_validation_report.json', '{bad json');
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 10];
        });
        $this->app->instance(B1ValidationAdapter::class, new B1ValidationAdapter($runner, config('annotation')));
        $user = $this->makeUser('annotator');
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()])
            ->assertStatus(503)->assertJsonPath('error.details.code', 'B1_REPORT_CORRUPT');
    }

    public function test_temp_directory_is_isolated_and_cleaned_up(): void
    {
        $tempBase = storage_path('app/b1-validation-tests');
        $user = $this->makeUser('annotator');
        $response = $this->actingAs($user)->postJson('/api/v1/annotations/validate', ['record' => $this->validIndividualRecord()]);
        $response->assertOk();
        $runId = $response->json('data.run_id');
        $this->assertNotEmpty($runId);
        $this->assertDirectoryDoesNotExist($tempBase.DIRECTORY_SEPARATOR.$runId);
    }

    public function test_direct_api_request_cannot_bypass_authorization(): void
    {
        $user = $this->makeUser(null);
        $this->actingAs($user)->postJson('/api/v1/annotations/validate', [
            'record' => $this->validIndividualRecord(),
            'is_export_eligible_canonical' => true,
            'valid' => true,
        ])->assertStatus(403);
    }

    public function test_runtime_health_requires_research_admin(): void
    {
        $user = $this->makeUser('annotator');
        $this->actingAs($user)->getJson('/api/v1/annotations/runtime-health')->assertStatus(403);
    }

    private function makeUser(?string $role): User
    {
        $user = User::factory()->create();
        if ($role !== null) {
            $user->forceFill(['role' => $role])->save();
        }
        return $user->fresh();
    }

    /** @return array<string,mixed> */
    private function validIndividualRecord(): array
    {
        return [
            'schema_version' => 'human-recognition-v1',
            'record_id' => 'HR-IND-000001',
            'recognition_method' => 'individual',
            'verification_status' => 'confirmed',
            'ingredient_labels' => ['cardamom_pod'],
            'uncertain_labels' => [],
            'out_of_scope_labels' => [],
            'reviewer_id' => 'reviewer_a',
            'recognition_date' => '2026-11-24',
            'notes' => 'confirmed from explicit evidence',
            'provenance' => [
                'source_type' => 'historical_individual_inspection',
                'source_reference' => 'doc-1',
                'evidence_summary' => 'explicit visual confirmation',
                'is_human_ground_truth' => true,
            ],
            'individual' => [
                'image_id' => 'IMG-000002',
                'relative_path' => 'Black Pepper/black_pepper_00002.jpg',
                'original_folder' => 'Black Pepper',
                'original_filename' => 'black_pepper_00002.jpg',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function validGroupRecord(): array
    {
        return [
            'schema_version' => 'human-recognition-v1',
            'record_id' => 'HR-GRP-000001',
            'recognition_method' => 'group',
            'verification_status' => 'pending_confirmation',
            'ingredient_labels' => ['cardamom_pod'],
            'uncertain_labels' => [],
            'out_of_scope_labels' => [],
            'reviewer_id' => null,
            'recognition_date' => null,
            'notes' => 'group inspected; needs confirmation',
            'provenance' => [
                'source_type' => 'historical_group_inspection',
                'source_reference' => 'session-x',
                'evidence_summary' => 'owner reported group review',
                'is_human_ground_truth' => false,
            ],
            'group' => [
                'group_id' => 'GRP-cardamom',
                'original_folder' => 'Black Pepper',
                'proposed_ingredient' => 'cardamom_pod',
                'image_ids' => ['IMG-000002'],
                'image_count' => 1,
            ],
        ];
    }
}
