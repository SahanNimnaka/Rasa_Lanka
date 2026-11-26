<?php

namespace App\Services\Annotation\Storage;

use App\Services\Annotation\Storage\Exceptions\ArtifactAlreadyExistsException;
use App\Services\Annotation\Storage\Exceptions\ArtifactHashMismatchException;
use App\Services\Annotation\Storage\Exceptions\ArtifactIntegrityConflictException;
use App\Services\Annotation\Storage\Exceptions\ArtifactLockException;
use App\Services\Annotation\Storage\Exceptions\ArtifactNotFoundException;
use App\Services\Annotation\Storage\Exceptions\ArtifactPathException;
use App\Services\Annotation\Storage\Exceptions\ArtifactStorageException;
use App\Services\Annotation\Storage\Exceptions\ArtifactUnsupportedDriverException;
use Illuminate\Support\Str;

/**
 * B2-S2-I4: protected local annotation artifact storage primitives.
 *
 * This service handles filesystem artifacts only. It does not update database
 * state, perform lifecycle transitions, run B1 validation, or expose HTTP APIs.
 *
 * Locking model: stable per-record OS flock() on a lock file, with bounded wait.
 * Supported execution model: conventional PHP-FPM/CLI process isolation where
 * cross-process serialization is provided by OS file locks. The in-process
 * counter only prevents same-process reentrancy deadlock; it is NOT a blocking
 * in-process mutex and threaded in-process serialization is not claimed.
 */
class AnnotationArtifactStore
{
    private const IDENTIFIER_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/';

    /** @var array<string, int> */
    private static array $processMutexCounts = [];

    private string $diskName;

    private string $driver;

    private string $rootPath;

    private string $draftsPath;

    private string $submittedPath;

    private string $validationReportsPath;

    private string $tempPath;

    private int $lockWaitSeconds;

    /**
     * @param  array<string,mixed>|null  $config
     */
    public function __construct(?array $config = null)
    {
        $config ??= config('annotation', []);

        $this->diskName = $this->stringConfig($config, 'annotation_storage_disk', 'annotation');
        $this->driver = strtolower($this->stringConfig($config, 'annotation_storage_driver', 'local'));
        $this->draftsPath = $this->normalizeRelativeConfigPath($config, 'annotation_draft_versions_path', 'drafts');
        $this->submittedPath = $this->normalizeRelativeConfigPath($config, 'annotation_submitted_path', 'submitted');
        $this->validationReportsPath = $this->normalizeRelativeConfigPath($config, 'annotation_validation_report_path', 'validation-runs');
        $this->tempPath = $this->normalizeRelativeConfigPath($config, 'annotation_temp_path', 'tmp');
        $this->lockWaitSeconds = max(0, (int) ($config['annotation_promotion_lock_wait_seconds'] ?? 5));

        $disk = config('filesystems.disks.'.$this->diskName);
        if (! is_array($disk)) {
            throw new ArtifactUnsupportedDriverException('Annotation artifact disk is not configured.');
        }

        $diskDriver = strtolower((string) ($disk['driver'] ?? ''));
        if ($this->driver !== 'local' || $diskDriver !== 'local') {
            throw new ArtifactUnsupportedDriverException('Annotation artifact storage requires the local driver.');
        }

        $root = (string) ($disk['root'] ?? '');
        if ($root === '') {
            throw new ArtifactStorageException('Annotation artifact root is not configured.');
        }

        $this->rootPath = $this->normalizeAbsolutePath($root);
        $this->ensureDirectory($this->rootPath);
        $this->rootPath = $this->realpathOrFail($this->rootPath);
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * @return array{record_id:string,draft_version_id:string,relative_path:string,sha256:string,bytes:int}
     */
    public function writeDraftVersion(string $recordId, string $content, ?string $draftVersionId = null): array
    {
        $recordId = $this->assertIdentifier($recordId, 'record_id');
        $draftVersionId = $draftVersionId !== null && $draftVersionId !== ''
            ? $this->assertIdentifier($draftVersionId, 'draft_version_id')
            : $this->generateIdentifier();

        $versionRelative = $this->draftVersionRelativePath($recordId, $draftVersionId);
        $versionAbsolute = $this->absolutePathForRelative($versionRelative, false);
        $this->ensureDirectory(dirname($versionAbsolute));

        $tmpRelative = $this->draftTmpRelativePath($recordId);
        $tmpAbsolute = $this->absolutePathForRelative($tmpRelative, false);
        $this->ensureDirectory(dirname($tmpAbsolute));

        try {
            $this->writeFileExclusive($tmpAbsolute, $content);
            $sha256 = $this->hashFile($tmpAbsolute);
            $this->publishExclusiveByHardLink($tmpAbsolute, $versionAbsolute);
            $this->syncDirectory(dirname($versionAbsolute));

            return [
                'record_id' => $recordId,
                'draft_version_id' => $draftVersionId,
                'relative_path' => $versionRelative,
                'sha256' => $sha256,
                'bytes' => strlen($content),
            ];
        } catch (\Throwable $e) {
            try {
                $this->cleanupTempFile($tmpAbsolute);
            } catch (\Throwable $cleanupError) {
                if ($e instanceof ArtifactStorageException) {
                    throw $e;
                }
                throw new ArtifactStorageException('Failed to write draft version; temp cleanup also failed.', 0, $cleanupError);
            }
            if ($e instanceof ArtifactStorageException) {
                throw $e;
            }
            throw new ArtifactStorageException('Failed to write draft version.', 0, $e);
        }
    }

    /**
     * @return array{record_id:string,submission_intent_id:string,relative_path:string,sha256:string,bytes:int,idempotent:bool}
     */
    public function writeSubmissionStaging(string $recordId, string $submissionIntentId, string $content): array
    {
        $recordId = $this->assertIdentifier($recordId, 'record_id');
        $submissionIntentId = $this->assertIdentifier($submissionIntentId, 'submission_intent_id');
        $relative = $this->stagingRelativePath($recordId, $submissionIntentId);
        $absolute = $this->absolutePathForRelative($relative, false);
        $this->ensureDirectory(dirname($absolute));

        if (file_exists($absolute)) {
            $existingSha = $this->hashFile($absolute);
            $newSha = hash('sha256', $content);
            if (! hash_equals($existingSha, $newSha)) {
                throw new ArtifactIntegrityConflictException('Submission staging artifact exists with different bytes.');
            }

            return [
                'record_id' => $recordId,
                'submission_intent_id' => $submissionIntentId,
                'relative_path' => $relative,
                'sha256' => $existingSha,
                'bytes' => filesize($absolute) ?: 0,
                'idempotent' => true,
            ];
        }

        $tmpRelative = $this->submittedPath.'/'.$recordId.'/staging/.tmp-'.$this->generateIdentifier().'.json';
        $tmpAbsolute = $this->absolutePathForRelative($tmpRelative, false);
        $this->ensureDirectory(dirname($tmpAbsolute));

        try {
            $this->writeFileExclusive($tmpAbsolute, $content);
            $sha256 = $this->hashFile($tmpAbsolute);
            $this->publishExclusiveByHardLink($tmpAbsolute, $absolute);
            $this->syncDirectory(dirname($absolute));

            return [
                'record_id' => $recordId,
                'submission_intent_id' => $submissionIntentId,
                'relative_path' => $relative,
                'sha256' => $sha256,
                'bytes' => strlen($content),
                'idempotent' => false,
            ];
        } catch (ArtifactAlreadyExistsException $e) {
            try {
                $this->cleanupTempFile($tmpAbsolute);
            } catch (\Throwable $cleanupError) {
                throw new ArtifactStorageException('Submission staging artifact already exists; temp cleanup failed.', 0, $cleanupError);
            }
            $existingSha = $this->hashFile($absolute);
            $newSha = hash('sha256', $content);
            if (! hash_equals($existingSha, $newSha)) {
                throw new ArtifactIntegrityConflictException('Submission staging artifact exists with different bytes.', 0, $e);
            }

            return [
                'record_id' => $recordId,
                'submission_intent_id' => $submissionIntentId,
                'relative_path' => $relative,
                'sha256' => $existingSha,
                'bytes' => filesize($absolute) ?: 0,
                'idempotent' => true,
            ];
        } catch (\Throwable $e) {
            try {
                $this->cleanupTempFile($tmpAbsolute);
            } catch (\Throwable $cleanupError) {
                if ($e instanceof ArtifactStorageException) {
                    throw $e;
                }
                throw new ArtifactStorageException('Failed to write submission staging artifact; temp cleanup also failed.', 0, $cleanupError);
            }
            if ($e instanceof ArtifactStorageException) {
                throw $e;
            }
            throw new ArtifactStorageException('Failed to write submission staging artifact.', 0, $e);
        }
    }

    /**
     * Publish the immutable final submitted artifact from a verified staging artifact.
     *
     * @return array{record_id:string,relative_path:string,sha256:string,idempotent:bool,source_relative_path:string,promotion_tmp_removed?:bool,promotion_tmp_relative_path?:string}
     */
    public function publishFinalSubmittedArtifact(string $recordId, string $stagingRelativePath, string $expectedSha256, ?string $secondExpectedSha256 = null, bool $cleanupPromotionTemp = true): array
    {
        $recordId = $this->assertIdentifier($recordId, 'record_id');
        $expectedSha256 = $this->assertSha256($expectedSha256, 'expected_sha256');
        $secondExpectedSha256 = $secondExpectedSha256 !== null && $secondExpectedSha256 !== ''
            ? $this->assertSha256($secondExpectedSha256, 'second_expected_sha256')
            : null;

        $stagingRelative = $this->normalizeRelativePath($stagingRelativePath);
        $expectedStagingPrefix = $this->submittedPath.'/'.$recordId.'/staging/';
        if (! str_starts_with($stagingRelative, $expectedStagingPrefix)) {
            throw new ArtifactPathException('Staging artifact must be within the record staging namespace.');
        }

        return $this->withRecordPromotionLock($recordId, function () use ($recordId, $stagingRelative, $expectedSha256, $secondExpectedSha256, $cleanupPromotionTemp): array {
            $stagingAbsolute = $this->absolutePathForRelative($stagingRelative, true);
            if (! is_file($stagingAbsolute) || is_link($stagingAbsolute)) {
                throw new ArtifactNotFoundException('Staging artifact is missing.');
            }

            $stagingSha = $this->hashFile($stagingAbsolute);
            $this->assertHashesMatch($stagingSha, $expectedSha256, 'Staging artifact hash mismatch.');
            if ($secondExpectedSha256 !== null) {
                $this->assertHashesMatch($stagingSha, $secondExpectedSha256, 'Staging artifact hash mismatch.');
            }

            $finalRelative = $this->finalSubmittedRelativePath($recordId);
            $finalAbsolute = $this->absolutePathForRelative($finalRelative, false);
            $this->ensureDirectory(dirname($finalAbsolute));

            if (file_exists($finalAbsolute)) {
                return $this->handleExistingFinal($recordId, $finalRelative, $finalAbsolute, $expectedSha256, $secondExpectedSha256, $stagingRelative);
            }

            $tmpName = '.record.json.promote-'.$this->generateIdentifier().'.tmp';
            $tmpRelative = $this->submittedPath.'/'.$recordId.'/'.$tmpName;
            $tmpAbsolute = $this->absolutePathForRelative($tmpRelative, false);
            $finalPublished = false;

            try {
                $this->copyFileExclusive($stagingAbsolute, $tmpAbsolute);
                $tmpSha = $this->hashFile($tmpAbsolute);
                $this->assertHashesMatch($tmpSha, $expectedSha256, 'Promotion temp hash mismatch.');
                if ($secondExpectedSha256 !== null) {
                    $this->assertHashesMatch($tmpSha, $secondExpectedSha256, 'Promotion temp hash mismatch.');
                }

                $published = $this->linkExclusive($tmpAbsolute, $finalAbsolute);
                if (! $published) {
                    $this->cleanupTempFile($tmpAbsolute);

                    return $this->handleExistingFinal($recordId, $finalRelative, $finalAbsolute, $expectedSha256, $secondExpectedSha256, $stagingRelative);
                }

                $this->syncDirectory(dirname($finalAbsolute));
                $finalSha = $this->hashFile($finalAbsolute);
                $this->assertHashesMatch($finalSha, $expectedSha256, 'Final artifact hash mismatch.');
                if ($secondExpectedSha256 !== null) {
                    $this->assertHashesMatch($finalSha, $secondExpectedSha256, 'Final artifact hash mismatch.');
                }
                $finalPublished = true;

                $tmpCleanup = $cleanupPromotionTemp
                    ? $this->cleanupPublishedPromotionTemp($tmpAbsolute, $finalAbsolute, $expectedSha256, $secondExpectedSha256)
                    : ['removed' => false, 'relative_path' => $tmpRelative];

                return [
                    'record_id' => $recordId,
                    'relative_path' => $finalRelative,
                    'sha256' => $finalSha,
                    'idempotent' => false,
                    'source_relative_path' => $stagingRelative,
                    'promotion_tmp_removed' => $tmpCleanup['removed'],
                    'promotion_tmp_relative_path' => $tmpRelative,
                ];
            } catch (\Throwable $e) {
                if ($finalPublished && is_file($finalAbsolute)) {
                    // Final is already published and verified; do not delete tmp or
                    // report false success. Leave tmp for reconciliation/inspection.
                    if ($e instanceof ArtifactStorageException) {
                        throw $e;
                    }
                    throw new ArtifactStorageException('Final artifact published but post-publish step failed; reconciliation required.', 0, $e);
                }

                $this->cleanupTempFile($tmpAbsolute);
                if ($e instanceof ArtifactStorageException) {
                    throw $e;
                }
                throw new ArtifactStorageException('Failed to publish final submitted artifact.', 0, $e);
            }
        });
    }

    /**
     * @return array{relative_path:string,sha256:string,bytes:int,verified:bool}
     */
    public function verifyStoredArtifact(string $relativePath, string $expectedSha256): array
    {
        $relative = $this->normalizeRelativePath($relativePath);
        $absolute = $this->absolutePathForRelative($relative, true);
        if (! is_file($absolute) || is_link($absolute)) {
            throw new ArtifactNotFoundException('Artifact is missing.');
        }

        $expectedSha256 = $this->assertSha256($expectedSha256, 'expected_sha256');
        $actual = $this->hashFile($absolute);
        if (! hash_equals($actual, $expectedSha256)) {
            throw new ArtifactHashMismatchException('Artifact hash mismatch.');
        }

        return [
            'relative_path' => $relative,
            'sha256' => $actual,
            'bytes' => filesize($absolute) ?: 0,
            'verified' => true,
        ];
    }

    public function readArtifactSha256(string $relativePath): string
    {
        $relative = $this->normalizeRelativePath($relativePath);
        $absolute = $this->absolutePathForRelative($relative, true);
        if (! is_file($absolute) || is_link($absolute)) {
            throw new ArtifactNotFoundException('Artifact is missing.');
        }

        return $this->hashFile($absolute);
    }

    public function artifactExists(string $relativePath): bool
    {
        $relative = $this->normalizeRelativePath($relativePath);
        $absolute = $this->absolutePathForRelative($relative, false);

        return is_file($absolute) && ! is_link($absolute);
    }

    /**
     * Resolve a store-relative path to an absolute path under the protected root.
     * This does not expose arbitrary filesystem access outside the annotation root.
     */
    public function resolveRelativePath(string $relativePath, bool $mustExist = true): string
    {
        return $this->absolutePathForRelative($relativePath, $mustExist);
    }

    /**
     * @return array{record_id:string,run_id:string,file_name:string,relative_path:string,sha256:string,bytes:int,idempotent:bool}
     */
    public function writeValidationReport(string $recordId, string $runId, string $fileName, string $content): array
    {
        $recordId = $this->assertIdentifier($recordId, 'record_id');
        $runId = $this->assertIdentifier($runId, 'run_id');
        $fileName = $this->assertReportFileName($fileName);

        $relative = $this->validationReportRelativePath($recordId, $runId, $fileName);
        $absolute = $this->absolutePathForRelative($relative, false);
        $this->ensureDirectory(dirname($absolute));

        if (file_exists($absolute)) {
            $existingSha = $this->hashFile($absolute);
            $newSha = hash('sha256', $content);
            if (! hash_equals($existingSha, $newSha)) {
                throw new ArtifactIntegrityConflictException('Validation report artifact exists with different bytes.');
            }

            return [
                'record_id' => $recordId,
                'run_id' => $runId,
                'file_name' => $fileName,
                'relative_path' => $relative,
                'sha256' => $existingSha,
                'bytes' => filesize($absolute) ?: 0,
                'idempotent' => true,
            ];
        }

        $tmpRelative = $this->validationReportsPath.'/'.$recordId.'/'.$runId.'/.tmp-'.$this->generateIdentifier().'-'.$fileName;
        $tmpAbsolute = $this->absolutePathForRelative($tmpRelative, false);
        $this->ensureDirectory(dirname($tmpAbsolute));

        try {
            $this->writeFileExclusive($tmpAbsolute, $content);
            $sha256 = $this->hashFile($tmpAbsolute);
            $this->publishExclusiveByHardLink($tmpAbsolute, $absolute);
            $this->syncDirectory(dirname($absolute));

            return [
                'record_id' => $recordId,
                'run_id' => $runId,
                'file_name' => $fileName,
                'relative_path' => $relative,
                'sha256' => $sha256,
                'bytes' => strlen($content),
                'idempotent' => false,
            ];
        } catch (ArtifactAlreadyExistsException $e) {
            try {
                $this->cleanupTempFile($tmpAbsolute);
            } catch (\Throwable $cleanupError) {
                throw new ArtifactStorageException('Validation report artifact already exists; temp cleanup failed.', 0, $cleanupError);
            }
            $existingSha = $this->hashFile($absolute);
            $newSha = hash('sha256', $content);
            if (! hash_equals($existingSha, $newSha)) {
                throw new ArtifactIntegrityConflictException('Validation report artifact exists with different bytes.', 0, $e);
            }

            return [
                'record_id' => $recordId,
                'run_id' => $runId,
                'file_name' => $fileName,
                'relative_path' => $relative,
                'sha256' => $existingSha,
                'bytes' => filesize($absolute) ?: 0,
                'idempotent' => true,
            ];
        } catch (\Throwable $e) {
            try {
                $this->cleanupTempFile($tmpAbsolute);
            } catch (\Throwable $cleanupError) {
                if ($e instanceof ArtifactStorageException) {
                    throw $e;
                }
                throw new ArtifactStorageException('Failed to write validation report artifact; temp cleanup also failed.', 0, $cleanupError);
            }
            if ($e instanceof ArtifactStorageException) {
                throw $e;
            }
            throw new ArtifactStorageException('Failed to write validation report artifact.', 0, $e);
        }
    }

    public function validationReportRelativePath(string $recordId, string $runId, string $fileName = 'step8b_validation_report.json'): string
    {
        $recordId = $this->assertIdentifier($recordId, 'record_id');
        $runId = $this->assertIdentifier($runId, 'run_id');
        $fileName = $this->assertReportFileName($fileName);

        return $this->validationReportsPath.'/'.$recordId.'/'.$runId.'/'.$fileName;
    }

    /**
     * Reconciliation hook: inspect record artifacts without mutation.
     *
     * @return array<string,mixed>
     */
    public function inspectRecordArtifacts(string $recordId): array
    {
        $recordId = $this->assertIdentifier($recordId, 'record_id');

        return [
            'record_id' => $recordId,
            'draft_versions' => $this->listJsonArtifacts($this->draftsPath.'/'.$recordId.'/versions'),
            'staging_artifacts' => $this->listJsonArtifacts($this->submittedPath.'/'.$recordId.'/staging'),
            'promotion_temps' => $this->listPromotionTemps($recordId),
            'final_artifact' => $this->inspectSingleArtifact($this->finalSubmittedRelativePath($recordId)),
            'promotion_lock' => $this->inspectSingleArtifact($this->promotionLockRelativePath($recordId)),
        ];
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function withRecordPromotionLock(string $recordId, callable $callback): mixed
    {
        $recordId = $this->assertIdentifier($recordId, 'record_id');
        if ((self::$processMutexCounts[$recordId] ?? 0) > 0) {
            self::$processMutexCounts[$recordId]++;
            try {
                return $callback();
            } finally {
                self::$processMutexCounts[$recordId]--;
                if ((self::$processMutexCounts[$recordId] ?? 0) <= 0) {
                    unset(self::$processMutexCounts[$recordId]);
                }
            }
        }

        $lockRelative = $this->promotionLockRelativePath($recordId);
        $lockAbsolute = $this->absolutePathForRelative($lockRelative, false);
        $this->ensureDirectory(dirname($lockAbsolute));

        $mutexAcquired = $this->acquireProcessMutex($recordId);
        $handle = null;
        $acquired = false;

        try {
            $handle = @fopen($lockAbsolute, 'c+b');
            if ($handle === false) {
                throw new ArtifactLockException('Unable to open promotion lock file.');
            }

            $deadline = microtime(true) + $this->lockWaitSeconds;
            do {
                $acquired = @flock($handle, LOCK_EX | LOCK_NB);
                if ($acquired) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new ArtifactLockException('Unable to acquire promotion lock within configured timeout.');
                }
                usleep(50000);
            } while (true);

            return $callback();
        } finally {
            if ($acquired && is_resource($handle)) {
                @flock($handle, LOCK_UN);
            }
            if (is_resource($handle)) {
                @fclose($handle);
            }
            $this->releaseProcessMutex($recordId, $mutexAcquired);
        }
    }

    /** @return array{record_id:string,relative_path:string,sha256:string,idempotent:bool,source_relative_path:string,promotion_tmp_removed:bool,promotion_tmp_relative_path:?string} */
    private function handleExistingFinal(string $recordId, string $finalRelative, string $finalAbsolute, string $expectedSha256, ?string $secondExpectedSha256, string $stagingRelative): array
    {
        if (! is_file($finalAbsolute) || is_link($finalAbsolute)) {
            throw new ArtifactIntegrityConflictException('Final artifact path exists but is not a regular file.');
        }

        $finalSha = $this->hashFile($finalAbsolute);
        $matches = hash_equals($finalSha, $expectedSha256)
            && ($secondExpectedSha256 === null || hash_equals($finalSha, $secondExpectedSha256));

        if (! $matches) {
            throw new ArtifactIntegrityConflictException('Final artifact exists with a different hash.');
        }

        return [
            'record_id' => $recordId,
            'relative_path' => $finalRelative,
            'sha256' => $finalSha,
            'idempotent' => true,
            'source_relative_path' => $stagingRelative,
            'promotion_tmp_removed' => false,
            'promotion_tmp_relative_path' => null,
        ];
    }

    private function draftVersionRelativePath(string $recordId, string $draftVersionId): string
    {
        return $this->draftsPath.'/'.$recordId.'/versions/'.$draftVersionId.'.json';
    }

    private function draftTmpRelativePath(string $recordId): string
    {
        return $this->draftsPath.'/'.$recordId.'/tmp/.tmp-'.$this->generateIdentifier().'.json';
    }

    private function stagingRelativePath(string $recordId, string $submissionIntentId): string
    {
        return $this->submittedPath.'/'.$recordId.'/staging/'.$submissionIntentId.'.json';
    }

    private function finalSubmittedRelativePath(string $recordId): string
    {
        return $this->submittedPath.'/'.$recordId.'/record.json';
    }

    private function promotionLockRelativePath(string $recordId): string
    {
        return $this->submittedPath.'/'.$recordId.'/.promotion.lock';
    }

    private function generateIdentifier(): string
    {
        return (string) Str::uuid();
    }

    private function assertIdentifier(string $value, string $field): string
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw new ArtifactPathException('Invalid '.$field.'.');
        }

        return $value;
    }

    private function assertSha256(string $value, string $field): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1) {
            throw new ArtifactStorageException('Invalid '.$field.'.');
        }

        return $value;
    }

    private function assertReportFileName(string $fileName): string
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $fileName) !== 1 || str_contains($fileName, '..')) {
            throw new ArtifactPathException('Invalid report file name.');
        }

        return $fileName;
    }

    private function assertHashesMatch(string $actual, string $expected, string $message): void
    {
        if (! hash_equals(strtolower($actual), strtolower($expected))) {
            throw new ArtifactHashMismatchException($message);
        }
    }

    private function stringConfig(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function normalizeRelativeConfigPath(array $config, string $key, string $default): string
    {
        $value = $this->stringConfig($config, $key, $default);
        $value = str_replace('\\', '/', trim($value));
        $value = trim($value, '/');
        if ($value === '' || str_contains($value, "\0") || str_contains($value, ':') || str_starts_with($value, '..')) {
            throw new ArtifactStorageException('Invalid annotation storage path configuration.');
        }
        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ArtifactStorageException('Invalid annotation storage path configuration.');
            }
        }

        return $value;
    }

    private function normalizeRelativePath(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new ArtifactPathException('Invalid artifact path.');
        }
        if (str_starts_with($relativePath, '\\\\') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $relativePath) === 1 || str_starts_with($relativePath, '/')) {
            throw new ArtifactPathException('Absolute artifact paths are not allowed.');
        }

        $normalized = str_replace('\\', '/', $relativePath);
        $normalized = ltrim($normalized, '/');
        $segments = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new ArtifactPathException('Path traversal is not allowed.');
            }
            $segments[] = $segment;
        }
        if ($segments === []) {
            throw new ArtifactPathException('Invalid artifact path.');
        }

        return implode('/', $segments);
    }

    private function absolutePathForRelative(string $relativePath, bool $mustExist): string
    {
        $relative = $this->normalizeRelativePath($relativePath);
        $absolute = $this->rootPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $this->assertWithinRoot($absolute, $mustExist);

        return $absolute;
    }

    private function assertWithinRoot(string $absolutePath, bool $mustExist): void
    {
        $root = $this->rootPath;
        $normalizedAbsolute = $this->normalizeAbsolutePath($absolutePath);

        $existing = $normalizedAbsolute;
        while (! file_exists($existing)) {
            $parent = dirname($existing);
            if ($parent === $existing) {
                break;
            }
            $existing = $parent;
        }

        $real = realpath($existing);
        if ($real === false) {
            throw new ArtifactPathException('Artifact path cannot be resolved.');
        }
        if (! $this->isWithin($real, $root)) {
            throw new ArtifactPathException('Artifact path escapes the protected root.');
        }

        if ($mustExist) {
            $realTarget = realpath($normalizedAbsolute);
            if ($realTarget === false) {
                throw new ArtifactNotFoundException('Artifact is missing.');
            }
            if (! $this->isWithin($realTarget, $root)) {
                throw new ArtifactPathException('Artifact path escapes the protected root.');
            }
            if (is_link($normalizedAbsolute)) {
                throw new ArtifactPathException('Symlinked artifact paths are not allowed.');
            }
        }
    }

    private function isWithin(string $path, string $root): bool
    {
        $path = rtrim($this->normalizeAbsolutePath($path), DIRECTORY_SEPARATOR);
        $root = rtrim($this->normalizeAbsolutePath($root), DIRECTORY_SEPARATOR);
        $prefix = $root.DIRECTORY_SEPARATOR;

        return $path === $root || str_starts_with(strtolower($path.$prefix[-1]), strtolower($prefix));
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (preg_match('/\A[A-Za-z]:\\\\\z/', $path) === 1 || $path === DIRECTORY_SEPARATOR) {
            return $path;
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (! @mkdir($path, 0777, true) && ! is_dir($path)) {
            throw new ArtifactStorageException('Unable to create artifact directory.');
        }
    }

    private function realpathOrFail(string $path): string
    {
        $real = realpath($path);
        if ($real === false) {
            throw new ArtifactStorageException('Unable to resolve artifact root.');
        }

        return $this->normalizeAbsolutePath($real);
    }

    private function writeFileExclusive(string $absolutePath, string $content): void
    {
        $handle = @fopen($absolutePath, 'xb');
        if ($handle === false) {
            if (file_exists($absolutePath)) {
                throw new ArtifactAlreadyExistsException('Artifact already exists.');
            }
            throw new ArtifactStorageException('Unable to create artifact file.');
        }

        try {
            $written = fwrite($handle, $content);
            if ($written !== strlen($content)) {
                throw new ArtifactStorageException('Unable to write artifact file.');
            }
            fflush($handle);
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            fclose($handle);
        }
    }

    private function copyFileExclusive(string $sourceAbsolute, string $targetAbsolute): void
    {
        $source = @fopen($sourceAbsolute, 'rb');
        if ($source === false) {
            throw new ArtifactNotFoundException('Source artifact is missing.');
        }
        $target = @fopen($targetAbsolute, 'xb');
        if ($target === false) {
            fclose($source);
            if (file_exists($targetAbsolute)) {
                throw new ArtifactAlreadyExistsException('Promotion temp already exists.');
            }
            throw new ArtifactStorageException('Unable to create promotion temp file.');
        }

        try {
            stream_copy_to_stream($source, $target);
            fflush($target);
            if (function_exists('fsync')) {
                @fsync($target);
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function publishExclusiveByHardLink(string $tempAbsolute, string $finalAbsolute): void
    {
        $this->ensureDirectory(dirname($finalAbsolute));
        if (@link($tempAbsolute, $finalAbsolute)) {
            $this->cleanupPublishedTempHardLink($tempAbsolute, $finalAbsolute);

            return;
        }

        if (file_exists($finalAbsolute)) {
            throw new ArtifactAlreadyExistsException('Artifact already exists.');
        }

        throw new ArtifactStorageException('Unable to publish artifact with no-replace hard link.');
    }

    /**
     * @throws ArtifactStorageException
     */
    protected function cleanupPublishedTempHardLink(string $tempAbsolute, string $finalAbsolute): void
    {
        if ($this->unlinkPath($tempAbsolute)) {
            return;
        }

        if (is_file($tempAbsolute) || is_link($tempAbsolute)) {
            $rollbackAttempted = $this->unlinkPath($finalAbsolute);
            $finalStillExists = file_exists($finalAbsolute);
            $tempStillExists = is_file($tempAbsolute) || is_link($tempAbsolute);

            if ($rollbackAttempted && ! $finalStillExists) {
                throw new ArtifactStorageException(
                    'Published artifact temp cleanup failed after hard-link publication; rollback removed newly published destination. tmp='.$tempAbsolute.'; final='.$finalAbsolute.'; final_exists=no; temp_exists='.($tempStillExists ? 'yes' : 'no')
                );
            }

            throw new ArtifactIntegrityConflictException(
                'Published artifact temp cleanup failed after hard-link publication and rollback could not restore a safe state; reconciliation required. tmp='.$tempAbsolute.'; final='.$finalAbsolute.'; final_exists='.($finalStillExists ? 'yes' : 'no').'; temp_exists='.($tempStillExists ? 'yes' : 'no')
            );
        }
    }

    protected function unlinkPath(string $absolutePath): bool
    {
        clearstatcache(true, $absolutePath);

        return @unlink($absolutePath);
    }

    private function linkExclusive(string $tempAbsolute, string $finalAbsolute): bool
    {
        if (@link($tempAbsolute, $finalAbsolute)) {
            return true;
        }
        if (file_exists($finalAbsolute)) {
            return false;
        }
        throw new ArtifactStorageException('Unable to publish final artifact with no-replace hard link.');
    }

    private function hashFile(string $absolutePath): string
    {
        $sha = @hash_file('sha256', $absolutePath);
        if (! is_string($sha) || $sha === '') {
            throw new ArtifactStorageException('Unable to hash artifact.');
        }

        return strtolower($sha);
    }

    private function syncDirectory(string $absolutePath): void
    {
        if (DIRECTORY_SEPARATOR !== '/' && PHP_OS_FAMILY !== 'Linux') {
            return;
        }
        $handle = @fopen($absolutePath, 'r');
        if ($handle === false) {
            return;
        }
        try {
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            fclose($handle);
        }
    }

    private function cleanupTempFile(string $absolutePath): void
    {
        if (! is_file($absolutePath) && ! is_link($absolutePath)) {
            return;
        }

        if (! $this->unlinkPath($absolutePath) && (is_file($absolutePath) || is_link($absolutePath))) {
            throw new ArtifactStorageException('Unable to clean temporary artifact: '.$absolutePath);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function listJsonArtifacts(string $relativeDirectory): array
    {
        $absoluteDir = $this->absolutePathForRelative($relativeDirectory, false);
        if (! is_dir($absoluteDir)) {
            return [];
        }

        $items = [];
        foreach (scandir($absoluteDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ! str_ends_with($entry, '.json')) {
                continue;
            }
            $relative = $relativeDirectory.'/'.$entry;
            $inspection = $this->inspectSingleArtifact($relative);
            if ($inspection !== null) {
                $items[] = $inspection;
            }
        }
        usort($items, fn (array $a, array $b) => strcmp((string) $a['relative_path'], (string) $b['relative_path']));

        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function listPromotionTemps(string $recordId): array
    {
        $dirRelative = $this->submittedPath.'/'.$recordId;
        $dirAbsolute = $this->absolutePathForRelative($dirRelative, false);
        if (! is_dir($dirAbsolute)) {
            return [];
        }

        $items = [];
        foreach (scandir($dirAbsolute) ?: [] as $entry) {
            if (! str_starts_with($entry, '.record.json.promote-') || ! str_ends_with($entry, '.tmp')) {
                continue;
            }
            $inspection = $this->inspectSingleArtifact($dirRelative.'/'.$entry);
            if ($inspection !== null) {
                $items[] = $inspection;
            }
        }
        usort($items, fn (array $a, array $b) => strcmp((string) $a['relative_path'], (string) $b['relative_path']));

        return $items;
    }

    /**
     * @return array{removed:bool,relative_path:string}
     */
    protected function cleanupPublishedPromotionTemp(string $tmpAbsolute, string $finalAbsolute, string $expectedSha256, ?string $secondExpectedSha256): array
    {
        $tmpRelative = $this->relativePathFromAbsolute($tmpAbsolute);

        if (is_file($tmpAbsolute) && ! is_link($tmpAbsolute)) {
            if (! @unlink($tmpAbsolute) && is_file($tmpAbsolute)) {
                throw new ArtifactStorageException('Published final artifact but failed to remove promotion temp; reconciliation required.');
            }
        }

        if (! is_file($finalAbsolute) || is_link($finalAbsolute)) {
            throw new ArtifactStorageException('Final artifact missing after promotion temp cleanup.');
        }

        $finalSha = $this->hashFile($finalAbsolute);
        $this->assertHashesMatch($finalSha, $expectedSha256, 'Final artifact hash mismatch after promotion temp cleanup.');
        if ($secondExpectedSha256 !== null) {
            $this->assertHashesMatch($finalSha, $secondExpectedSha256, 'Final artifact hash mismatch after promotion temp cleanup.');
        }

        return ['removed' => ! is_file($tmpAbsolute), 'relative_path' => $tmpRelative];
    }

    /**
     * Remove a promotion temp hard-link name only after durable evidence commit.
     *
     * @return array{record_id:string,removed:bool,promotion_tmp_relative_path:string,final_relative_path:string,final_sha256:string}
     */
    public function cleanupPromotionTempAfterEvidenceCommit(string $recordId, string $promotionTmpRelativePath, string $expectedSha256, ?string $secondExpectedSha256 = null): array
    {
        $recordId = $this->assertIdentifier($recordId, 'record_id');
        $expectedSha256 = $this->assertSha256($expectedSha256, 'expected_sha256');
        $secondExpectedSha256 = $secondExpectedSha256 !== null && $secondExpectedSha256 !== ''
            ? $this->assertSha256($secondExpectedSha256, 'second_expected_sha256')
            : null;

        $operation = function () use ($recordId, $promotionTmpRelativePath, $expectedSha256, $secondExpectedSha256): array {
            $tmpRelative = $this->normalizeRelativePath($promotionTmpRelativePath);
            $this->assertPromotionTempPathForRecord($recordId, $tmpRelative);

            $finalRelative = $this->finalSubmittedRelativePath($recordId);
            $finalAbsolute = $this->absolutePathForRelative($finalRelative, true);
            if (! is_file($finalAbsolute) || is_link($finalAbsolute)) {
                throw new ArtifactIntegrityConflictException('Final artifact missing or invalid before promotion temp cleanup.');
            }

            $finalSha = $this->hashFile($finalAbsolute);
            $this->assertHashesMatch($finalSha, $expectedSha256, 'Final artifact hash mismatch before promotion temp cleanup.');
            if ($secondExpectedSha256 !== null) {
                $this->assertHashesMatch($finalSha, $secondExpectedSha256, 'Final artifact hash mismatch before promotion temp cleanup.');
            }

            $tmpAbsolute = $this->absolutePathForRelative($tmpRelative, false);
            if (is_file($tmpAbsolute) || is_link($tmpAbsolute)) {
                if (is_link($tmpAbsolute) || ! is_file($tmpAbsolute)) {
                    throw new ArtifactIntegrityConflictException('Promotion temp path is not a regular file.');
                }
                if (! @unlink($tmpAbsolute) && is_file($tmpAbsolute)) {
                    throw new ArtifactStorageException('Failed to remove promotion temp after evidence commit.');
                }
            }

            return [
                'record_id' => $recordId,
                'removed' => ! is_file($tmpAbsolute) && ! is_link($tmpAbsolute),
                'promotion_tmp_relative_path' => $tmpRelative,
                'final_relative_path' => $finalRelative,
                'final_sha256' => $finalSha,
            ];
        };

        if ((self::$processMutexCounts[$recordId] ?? 0) > 0) {
            return $operation();
        }

        return $this->withRecordPromotionLock($recordId, $operation);
    }

    private function assertPromotionTempPathForRecord(string $recordId, string $relativePath): void
    {
        $expectedPrefix = $this->submittedPath.'/'.$recordId.'/.record.json.promote-';
        if (! str_starts_with($relativePath, $expectedPrefix) || ! str_ends_with($relativePath, '.tmp')) {
            throw new ArtifactPathException('Promotion temp path is not valid for the record.');
        }

        $finalRelative = $this->finalSubmittedRelativePath($recordId);
        $stagingPrefix = $this->submittedPath.'/'.$recordId.'/staging/';
        if ($relativePath === $finalRelative || str_starts_with($relativePath, $stagingPrefix)) {
            throw new ArtifactPathException('Refusing to remove staging or final artifact path.');
        }
    }

    private function relativePathFromAbsolute(string $absolutePath): string
    {
        $absolute = $this->normalizeAbsolutePath($absolutePath);
        $root = rtrim($this->rootPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with(strtolower($absolute.DIRECTORY_SEPARATOR), strtolower($root))) {
            throw new ArtifactPathException('Artifact path escapes the protected root.');
        }

        $relative = substr($absolute, strlen($root));

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    /** @return array<string,mixed>|null */
    private function inspectSingleArtifact(string $relativePath): ?array
    {
        $relative = $this->normalizeRelativePath($relativePath);
        $absolute = $this->absolutePathForRelative($relative, false);
        if (! file_exists($absolute)) {
            return [
                'relative_path' => $relative,
                'exists' => false,
            ];
        }
        if (is_link($absolute)) {
            return [
                'relative_path' => $relative,
                'exists' => true,
                'is_symlink' => true,
            ];
        }
        if (! is_file($absolute)) {
            return [
                'relative_path' => $relative,
                'exists' => true,
                'is_file' => false,
            ];
        }

        $sha = null;
        try {
            $sha = $this->hashFile($absolute);
        } catch (\Throwable) {
            $sha = null;
        }

        return [
            'relative_path' => $relative,
            'exists' => true,
            'is_file' => true,
            'bytes' => filesize($absolute) ?: 0,
            'sha256' => $sha,
            'mtime' => filemtime($absolute) ?: null,
        ];
    }

    private function acquireProcessMutex(string $recordId): bool
    {
        self::$processMutexCounts[$recordId] = (self::$processMutexCounts[$recordId] ?? 0) + 1;

        return true;
    }

    private function releaseProcessMutex(string $recordId, bool $acquired): void
    {
        if (! $acquired || ! isset(self::$processMutexCounts[$recordId])) {
            return;
        }
        self::$processMutexCounts[$recordId]--;
        if (self::$processMutexCounts[$recordId] <= 0) {
            unset(self::$processMutexCounts[$recordId]);
        }
    }
}
