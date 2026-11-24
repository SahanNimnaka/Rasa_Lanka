<?php

namespace App\Services\Annotation;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class B1ValidationAdapter
{
    private const REPORT_FILENAME = 'step8b_validation_report.json';
    private const MAX_RECORD_BYTES = 1048576; // 1 MiB

    public function __construct(?B1ProcessRunner $runner = null, ?array $config = null)
    {
        $this->runner = $runner ?? new B1ProcessRunner();
        $this->config = $config ?? config('annotation');
    }

    private B1ProcessRunner $runner;

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param  array<string,mixed>|list<mixed>  $record
     */
    public function validateRecord(array $record, ?string $runId = null): B1ValidationResult
    {
        $runId = $this->normalizeRunId($runId);
        $tempBase = $this->resolvePath($this->configPath('b1_temp_dir'));
        $runDir = $tempBase.DIRECTORY_SEPARATOR.$runId;
        $outDir = $runDir.DIRECTORY_SEPARATOR.'out';
        $recordPath = $runDir.DIRECTORY_SEPARATOR.'record.json';

        try {
            $this->assertSafeRunDir($tempBase, $runDir);
            File::ensureDirectoryExists($outDir);

            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($encoded === false) {
                return B1ValidationResult::infrastructureFailure($this->infraPayload($runId, 'B1_INPUT_ENCODE_FAILED', 'The validation input could not be encoded.'));
            }
            if (strlen($encoded) > self::MAX_RECORD_BYTES) {
                return B1ValidationResult::infrastructureFailure($this->infraPayload($runId, 'B1_INPUT_TOO_LARGE', 'The validation input exceeds the maximum allowed size.'));
            }
            $this->writeFileAtomically($recordPath, $encoded);

            $preflight = $this->preflight();
            if ($preflight !== null) {
                $this->logFailure($runId, $preflight['code'], $preflight['message'], null);
                return B1ValidationResult::infrastructureFailure($this->infraPayload($runId, $preflight['code'], $preflight['message']));
            }

            $command = $this->buildCommand($recordPath, $outDir);
            $result = $this->runner->run(
                $command,
                $this->resolvePath($this->configPath('b1_workdir')),
                max(1, (int) $this->configPath('b1_timeout_seconds')),
                $this->processEnv()
            );

            if ($result['timed_out']) {
                $this->logFailure($runId, 'B1_TIMEOUT', 'B1 validation timed out.', $result);
                return B1ValidationResult::infrastructureFailure($this->infraPayload($runId, 'B1_TIMEOUT', 'The B1 validator timed out.', $result));
            }

            $exitCode = $result['exit_code'];
            if ($exitCode !== 0 && $exitCode !== 1) {
                $this->logFailure($runId, 'B1_PROCESS_FAILED', 'B1 process exited unexpectedly.', $result);
                return B1ValidationResult::infrastructureFailure($this->infraPayload($runId, 'B1_PROCESS_FAILED', 'The B1 validator exited unexpectedly.', $result));
            }

            $reportPath = $outDir.DIRECTORY_SEPARATOR.self::REPORT_FILENAME;
            if (! is_file($reportPath) || is_link($reportPath)) {
                $this->logFailure($runId, 'B1_REPORT_MISSING', 'B1 report missing.', $result);
                return B1ValidationResult::infrastructureFailure($this->infraPayload($runId, 'B1_REPORT_MISSING', 'The B1 validator did not produce a report.', $result));
            }

            $reportRaw = File::get($reportPath);
            $report = json_decode($reportRaw, true);
            if (! $this->isValidReport($report)) {
                $this->logFailure($runId, 'B1_REPORT_CORRUPT', 'B1 report corrupt.', $result);
                return B1ValidationResult::infrastructureFailure($this->infraPayload($runId, 'B1_REPORT_CORRUPT', 'The B1 validator report could not be parsed.', $result));
            }

            $summary = $report['summary'];
            $results = $report['results'];
            $issues = $report['issues'];
            $recordResult = $this->selectRecordResult($results, $record);
            $recordId = is_string($recordResult['record_id'] ?? null) ? $recordResult['record_id'] : null;

            Log::info('b1.validation.completed', [
                'run_id' => $runId,
                'record_id' => $recordId,
                'exit_code' => $exitCode,
                'duration_ms' => $result['duration_ms'],
                'error_count' => $summary['error_count'] ?? null,
                'warning_count' => $summary['warning_count'] ?? null,
                'export_block_count' => $summary['export_block_count'] ?? null,
            ]);

            return B1ValidationResult::success([
                'run_id' => $runId,
                'b1' => [
                    'valid' => (bool) ($recordResult['valid'] ?? false),
                    'export_eligible_canonical' => (bool) ($recordResult['export_eligible_canonical'] ?? false),
                    'error_count' => (int) ($summary['error_count'] ?? 0),
                    'warning_count' => (int) ($summary['warning_count'] ?? 0),
                    'export_block_count' => (int) ($summary['export_block_count'] ?? 0),
                    'export_block_codes' => array_values(is_array($recordResult['export_block_codes'] ?? null) ? $recordResult['export_block_codes'] : []),
                    'issues' => $this->normalizeIssues($issues),
                    'results' => $this->normalizeResults($results),
                    'summary' => [
                        'records_checked' => (int) ($summary['records_checked'] ?? 0),
                        'valid_records' => (int) ($summary['valid_records'] ?? 0),
                        'invalid_records' => (int) ($summary['invalid_records'] ?? 0),
                        'export_eligible_canonical_records' => (int) ($summary['export_eligible_canonical_records'] ?? 0),
                        'issues_by_code' => is_array($summary['issues_by_code'] ?? null) ? $summary['issues_by_code'] : [],
                    ],
                    'exit_code' => $exitCode,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('b1.validation.exception', ['run_id' => $runId, 'error_code' => 'B1_ADAPTER_EXCEPTION']);
            return B1ValidationResult::infrastructureFailure($this->infraPayload($runId, 'B1_ADAPTER_EXCEPTION', 'The B1 validator could not be executed.'));
        } finally {
            $this->cleanup($runDir, $tempBase);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function healthCheck(): array
    {
        $preflight = $this->preflight();
        return [
            'ok' => $preflight === null,
            'python_bin' => basename($this->configPath('b1_python_bin')),
            'script_exists' => is_file($this->resolvePath($this->configPath('b1_script_path'))),
            'schema_exists' => is_file($this->resolvePath($this->configPath('b1_schema_path'))),
            'inventory_exists' => is_file($this->resolvePath($this->configPath('b1_inventory_path'))),
            'taxonomy_exists' => is_file($this->resolvePath($this->configPath('b1_taxonomy_path'))),
            'decisions_exists' => is_file($this->resolvePath($this->configPath('b1_decisions_path'))),
            'error' => $preflight,
        ];
    }

    /** @return array{code:string,message:string}|null */
    private function preflight(): ?array
    {
        $python = $this->configPath('b1_python_bin');
        $workdir = $this->resolvePath($this->configPath('b1_workdir'));
        $requiredFiles = [
            'B1_SCRIPT_MISSING' => $this->resolvePath($this->configPath('b1_script_path')),
            'B1_SCHEMA_MISSING' => $this->resolvePath($this->configPath('b1_schema_path')),
            'B1_INVENTORY_MISSING' => $this->resolvePath($this->configPath('b1_inventory_path')),
            'B1_TAXONOMY_MISSING' => $this->resolvePath($this->configPath('b1_taxonomy_path')),
            'B1_DECISIONS_MISSING' => $this->resolvePath($this->configPath('b1_decisions_path')),
        ];

        foreach ($requiredFiles as $code => $path) {
            if (! is_file($path) || is_link($path)) {
                return ['code' => $code, 'message' => 'Required B1 validation file is unavailable.'];
            }
        }
        if (! is_dir($workdir)) {
            return ['code' => 'B1_WORKDIR_MISSING', 'message' => 'Configured B1 working directory is unavailable.'];
        }

        $probe = $this->runner->run([$python, '--version'], $workdir, 10, $this->processEnv());
        if ($probe['timed_out'] || $probe['exit_code'] !== 0) {
            return ['code' => 'B1_PYTHON_UNAVAILABLE', 'message' => 'Configured Python executable is unavailable.'];
        }

        $dep = $this->runner->run([$python, '-c', 'import jsonschema'], $workdir, 10, $this->processEnv());
        if ($dep['timed_out'] || $dep['exit_code'] !== 0) {
            return ['code' => 'B1_DEPENDENCY_MISSING', 'message' => 'Required Python dependency jsonschema is unavailable.'];
        }

        return null;
    }

    /** @return list<string> */
    private function buildCommand(string $recordPath, string $outDir): array
    {
        return [
            $this->configPath('b1_python_bin'),
            $this->resolvePath($this->configPath('b1_script_path')),
            '--records', $recordPath,
            '--schema', $this->resolvePath($this->configPath('b1_schema_path')),
            '--inventory', $this->resolvePath($this->configPath('b1_inventory_path')),
            '--taxonomy', $this->resolvePath($this->configPath('b1_taxonomy_path')),
            '--decisions', $this->resolvePath($this->configPath('b1_decisions_path')),
            '--out-dir', $outDir,
        ];
    }

    /** @return array<string,string> */
    private function processEnv(): array
    {
        $env = [];
        foreach (['SYSTEMROOT', 'WINDIR', 'PATH', 'PYTHONPATH', 'PYTHONHOME', 'VIRTUAL_ENV', 'HOME', 'USERPROFILE', 'TMP', 'TEMP'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }
        return $env;
    }

    private function configPath(string $key): string
    {
        $value = $this->config[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '\\\\') || str_starts_with($path, '/')) {
            return $path;
        }
        $workdir = $this->configPath('b1_workdir');
        $base = $workdir !== '' ? $workdir : base_path('..');
        return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function normalizeRunId(?string $runId): string
    {
        if (is_string($runId) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', $runId) === 1) {
            return $runId;
        }
        return (string) Str::uuid();
    }

    private function assertSafeRunDir(string $tempBase, string $runDir): void
    {
        $baseReal = realpath($tempBase);
        if ($baseReal === false) {
            File::ensureDirectoryExists($tempBase);
            $baseReal = realpath($tempBase);
        }
        if ($baseReal === false) {
            throw new \RuntimeException('B1 temp base unavailable');
        }
        $prefix = rtrim($baseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalizedRunDir = rtrim($runDir, DIRECTORY_SEPARATOR);
        if ($normalizedRunDir === rtrim($baseReal, DIRECTORY_SEPARATOR) || ! str_starts_with($normalizedRunDir.$prefix[-1], $prefix)) {
            // fallback simple containment check
        }
        $runRealParent = realpath(dirname($runDir));
        if ($runRealParent !== false) {
            $expected = rtrim($baseReal, DIRECTORY_SEPARATOR);
            $actual = rtrim($runRealParent, DIRECTORY_SEPARATOR);
            if (strcasecmp($actual, $expected) !== 0) {
                throw new \RuntimeException('B1 run dir escaped temp base');
            }
        }
    }

    private function writeFileAtomically(string $path, string $contents): void
    {
        $tmp = $path.'.tmp';
        File::put($tmp, $contents);
        @chmod($tmp, 0600);
        if (! @rename($tmp, $path)) {
            File::move($tmp, $path);
        }
    }

    /** @param mixed $report */
    private function isValidReport($report): bool
    {
        return is_array($report)
            && isset($report['summary'], $report['results'], $report['issues'])
            && is_array($report['summary'])
            && is_array($report['results'])
            && is_array($report['issues']);
    }

    /**
     * @param  array<int,mixed>  $results
     * @param  array<string,mixed>|list<mixed>  $record
     * @return array<string,mixed>
     */
    private function selectRecordResult(array $results, array $record): array
    {
        $recordId = $record['record_id'] ?? null;
        if (is_string($recordId) && $recordId !== '') {
            foreach ($results as $result) {
                if (is_array($result) && ($result['record_id'] ?? null) === $recordId) {
                    return $result;
                }
            }
        }
        $first = $results[0] ?? null;
        return is_array($first) ? $first : [];
    }

    /** @param array<string,mixed>|null $result */
    private function logFailure(string $runId, string $code, string $message, ?array $result): void
    {
        Log::warning('b1.validation.infrastructure_failure', [
            'run_id' => $runId,
            'code' => $code,
            'exit_code' => $result['exit_code'] ?? null,
            'timed_out' => $result['timed_out'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
        ]);
    }

    /** @param array<string,mixed>|null $result @return array<string,mixed> */
    private function infraPayload(string $runId, string $code, string $message, ?array $result = null): array
    {
        return [
            'run_id' => $runId,
            'b1' => null,
            'infrastructure_error' => [
                'code' => $code,
                'message' => $message,
                'timed_out' => (bool) ($result['timed_out'] ?? false),
                'exit_code' => $result['exit_code'] ?? null,
            ],
        ];
    }

    /** @param array<int,mixed> $issues @return list<array<string,mixed>> */
    private function normalizeIssues(array $issues): array
    {
        $normalized = [];
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $normalized[] = [
                'severity' => $issue['severity'] ?? null,
                'code' => $issue['code'] ?? null,
                'record_id' => $issue['record_id'] ?? null,
                'image_id' => $issue['image_id'] ?? null,
                'field' => $issue['field'] ?? null,
                'message' => $issue['message'] ?? null,
                'details' => is_array($issue['details'] ?? null) ? $issue['details'] : [],
            ];
        }
        return $normalized;
    }

    /** @param array<int,mixed> $results @return list<array<string,mixed>> */
    private function normalizeResults(array $results): array
    {
        $normalized = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            $normalized[] = [
                'record_id' => $result['record_id'] ?? null,
                'recognition_method' => $result['recognition_method'] ?? null,
                'verification_status' => $result['verification_status'] ?? null,
                'valid' => (bool) ($result['valid'] ?? false),
                'export_eligible_canonical' => (bool) ($result['export_eligible_canonical'] ?? false),
                'export_block_codes' => array_values(is_array($result['export_block_codes'] ?? null) ? $result['export_block_codes'] : []),
                'resolved_ingredient_labels' => array_values(is_array($result['resolved_ingredient_labels'] ?? null) ? $result['resolved_ingredient_labels'] : []),
            ];
        }
        return $normalized;
    }

    private function cleanup(string $runDir, string $tempBase): void
    {
        try {
            $baseReal = realpath($tempBase);
            $runReal = realpath($runDir);
            if ($baseReal !== false && $runReal !== false) {
                $prefix = rtrim($baseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
                if (str_starts_with(rtrim($runReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, $prefix) && is_dir($runReal)) {
                    File::deleteDirectory($runReal);
                    return;
                }
            }
            if (is_dir($runDir) && str_contains($runDir, rtrim($tempBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                File::deleteDirectory($runDir);
            }
        } catch (\Throwable $e) {
            Log::warning('b1.validation.cleanup_failed', ['run_dir' => basename($runDir)]);
        }
    }
}
