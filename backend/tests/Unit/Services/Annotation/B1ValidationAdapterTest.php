<?php

namespace Tests\Unit\Services\Annotation;

use App\Services\Annotation\B1ProcessRunner;
use App\Services\Annotation\B1ValidationAdapter;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class B1ValidationAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'annotation.b1_python_bin' => 'python',
            'annotation.b1_script_path' => base_path('../research/phase-13.3/annotation_pipeline_step8b/scripts/validate_step8b_records.py'),
            'annotation.b1_schema_path' => base_path('../research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json'),
            'annotation.b1_inventory_path' => base_path('../research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv'),
            'annotation.b1_taxonomy_path' => base_path('../research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json'),
            'annotation.b1_decisions_path' => base_path('../research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json'),
            'annotation.b1_timeout_seconds' => 60,
            'annotation.b1_workdir' => base_path('..'),
            'annotation.b1_temp_dir' => storage_path('app/b1-adapter-unit-tests'),
        ]);
        File::deleteDirectory(storage_path('app/b1-adapter-unit-tests'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/b1-adapter-unit-tests'));
        parent::tearDown();
    }

    public function test_missing_python_returns_503_result(): void
    {
        config(['annotation.b1_python_bin' => 'missing-python']);
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturn(['exit_code' => 1, 'stdout' => '', 'stderr' => 'not found', 'timed_out' => false, 'duration_ms' => 1]);
        $adapter = new B1ValidationAdapter($runner, config('annotation'));
        $result = $adapter->validateRecord(['record_id' => 'R1']);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('B1_PYTHON_UNAVAILABLE', $result->payload['infrastructure_error']['code']);
    }

    public function test_missing_script_returns_503_result(): void
    {
        config(['annotation.b1_script_path' => base_path('../missing-script.py')]);
        $adapter = new B1ValidationAdapter(new B1ProcessRunner(), config('annotation'));
        $result = $adapter->validateRecord(['record_id' => 'R1']);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('B1_SCRIPT_MISSING', $result->payload['infrastructure_error']['code']);
    }

    public function test_corrupt_report_returns_503_result(): void
    {
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {
            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
            $outDir = $command[array_search('--out-dir', $command, true) + 1];
            File::ensureDirectoryExists($outDir);
            File::put($outDir.DIRECTORY_SEPARATOR.'step8b_validation_report.json', '{not-json');
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
        });
        $adapter = new B1ValidationAdapter($runner, config('annotation'));
        $result = $adapter->validateRecord(['record_id' => 'R1']);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('B1_REPORT_CORRUPT', $result->payload['infrastructure_error']['code']);
    }

    public function test_timeout_returns_503_result(): void
    {
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {
            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
            return ['exit_code' => null, 'stdout' => '', 'stderr' => '', 'timed_out' => true, 'duration_ms' => 60000];
        });
        $adapter = new B1ValidationAdapter($runner, config('annotation'));
        $result = $adapter->validateRecord(['record_id' => 'R1']);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('B1_TIMEOUT', $result->payload['infrastructure_error']['code']);
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
                'summary' => ['records_checked' => 1, 'valid_records' => 0, 'invalid_records' => 1, 'export_eligible_canonical_records' => 0, 'error_count' => 1, 'warning_count' => 0, 'export_block_count' => 0, 'issues_by_code' => []],
                'results' => [['record_id' => 'R1', 'recognition_method' => 'individual', 'verification_status' => 'confirmed', 'valid' => false, 'export_eligible_canonical' => false, 'export_block_codes' => [], 'resolved_ingredient_labels' => []]],
                'issues' => [['severity' => 'error', 'code' => 'E-SCHEMA', 'record_id' => 'R1', 'image_id' => '', 'field' => 'x', 'message' => 'bad', 'details' => []]],
            ]));
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
        });
        $adapter = new B1ValidationAdapter($runner, config('annotation'));
        $result = $adapter->validateRecord(['record_id' => 'R1']);
        $this->assertSame(200, $result->httpStatus);
        $this->assertFalse($result->payload['b1']['valid']);
    }

    public function test_unexpected_exit_returns_503(): void
    {
        $runner = $this->mock(B1ProcessRunner::class);
        $runner->shouldReceive('run')->andReturnUsing(function (array $command) {
            if (in_array('--version', $command, true) || in_array('-c', $command, true)) {
                return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
            }
            return ['exit_code' => 2, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'duration_ms' => 1];
        });
        $adapter = new B1ValidationAdapter($runner, config('annotation'));
        $result = $adapter->validateRecord(['record_id' => 'R1']);
        $this->assertSame(503, $result->httpStatus);
        $this->assertSame('B1_PROCESS_FAILED', $result->payload['infrastructure_error']['code']);
    }
}
