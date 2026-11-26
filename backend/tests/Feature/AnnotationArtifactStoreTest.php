<?php

namespace Tests\Feature;

use App\Services\Annotation\Storage\AnnotationArtifactStore;
use App\Services\Annotation\Storage\Exceptions\ArtifactAlreadyExistsException;
use App\Services\Annotation\Storage\Exceptions\ArtifactHashMismatchException;
use App\Services\Annotation\Storage\Exceptions\ArtifactIntegrityConflictException;
use App\Services\Annotation\Storage\Exceptions\ArtifactLockException;
use App\Services\Annotation\Storage\Exceptions\ArtifactNotFoundException;
use App\Services\Annotation\Storage\Exceptions\ArtifactPathException;
use App\Services\Annotation\Storage\Exceptions\ArtifactStorageException;
use App\Services\Annotation\Storage\Exceptions\ArtifactUnsupportedDriverException;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AnnotationArtifactStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/annotation-artifacts-'.uniqid('', true));
        File::ensureDirectoryExists($this->root);
        $this->configureStore($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_writes_versioned_draft_artifact_with_sha256(): void
    {
        $store = $this->makeStore();
        $content = "{\"record_id\":\"r1\",\"draft\":true}\n";

        $result = $store->writeDraftVersion('record-1', $content, 'draft-v1');

        $this->assertSame('record-1', $result['record_id']);
        $this->assertSame('draft-v1', $result['draft_version_id']);
        $this->assertSame('drafts/record-1/versions/draft-v1.json', $result['relative_path']);
        $this->assertSame(hash('sha256', $content), $result['sha256']);
        $this->assertSame(strlen($content), $result['bytes']);
        $this->assertFileExists($this->root.'/drafts/record-1/versions/draft-v1.json');
        $this->assertSame($content, File::get($this->root.'/drafts/record-1/versions/draft-v1.json'));
    }

    public function test_it_generates_unique_draft_version_identifiers(): void
    {
        $store = $this->makeStore();
        $a = $store->writeDraftVersion('record-1', '{"a":1}');
        $b = $store->writeDraftVersion('record-1', '{"a":2}');

        $this->assertNotSame($a['draft_version_id'], $b['draft_version_id']);
        $this->assertNotSame($a['relative_path'], $b['relative_path']);
        $this->assertFileExists($this->root.'/'.$a['relative_path']);
        $this->assertFileExists($this->root.'/'.$b['relative_path']);
    }

    public function test_existing_draft_version_cannot_be_overwritten(): void
    {
        $store = $this->makeStore();
        $store->writeDraftVersion('record-1', '{"v":1}', 'draft-v1');

        $this->expectException(ArtifactAlreadyExistsException::class);
        $store->writeDraftVersion('record-1', '{"v":2}', 'draft-v1');
    }

    public function test_draft_tmp_file_is_cleaned_after_publish_failure(): void
    {
        $store = $this->makeStore();
        $recordId = 'record-1';
        $versionId = 'draft-v1';
        $target = $this->root.'/drafts/'.$recordId.'/versions/'.$versionId.'.json';
        File::ensureDirectoryExists(dirname($target));
        File::put($target, '{"existing":true}');

        try {
            $store->writeDraftVersion($recordId, '{"new":true}', $versionId);
            $this->fail('Expected existing version exception.');
        } catch (ArtifactAlreadyExistsException $e) {
            $this->assertSame('{"existing":true}', File::get($target));
        }

        $tmpDir = $this->root.'/drafts/'.$recordId.'/tmp';
        $tmps = is_dir($tmpDir) ? glob($tmpDir.'/.tmp-*.json') : [];
        $this->assertSame([], $tmps);
    }

    public function test_it_writes_submission_staging_and_returns_sha256(): void
    {
        $store = $this->makeStore();
        $content = "{\"submitted\":true}\n";
        $result = $store->writeSubmissionStaging('record-1', 'intent-1', $content);

        $this->assertSame('submitted/record-1/staging/intent-1.json', $result['relative_path']);
        $this->assertSame(hash('sha256', $content), $result['sha256']);
        $this->assertFalse($result['idempotent']);
        $this->assertSame($content, File::get($this->root.'/'.$result['relative_path']));
    }

    public function test_submission_staging_is_isolated_by_intent_and_rejects_different_bytes(): void
    {
        $store = $this->makeStore();
        $store->writeSubmissionStaging('record-1', 'intent-1', '{"a":1}');
        $store->writeSubmissionStaging('record-1', 'intent-2', '{"a":2}');

        $this->assertFileExists($this->root.'/submitted/record-1/staging/intent-1.json');
        $this->assertFileExists($this->root.'/submitted/record-1/staging/intent-2.json');

        $same = $store->writeSubmissionStaging('record-1', 'intent-1', '{"a":1}');
        $this->assertTrue($same['idempotent']);

        $this->expectException(ArtifactIntegrityConflictException::class);
        $store->writeSubmissionStaging('record-1', 'intent-1', '{"a":999}');
    }

    public function test_publish_final_is_idempotent_for_same_hash_and_removes_tmp_name(): void
    {
        $store = $this->makeStore();
        $content = '{"final":true}';
        $staging = $store->writeSubmissionStaging('record-1', 'intent-1', $content);

        $first = $store->publishFinalSubmittedArtifact('record-1', $staging['relative_path'], $staging['sha256'], $staging['sha256']);
        $second = $store->publishFinalSubmittedArtifact('record-1', $staging['relative_path'], $staging['sha256'], $staging['sha256']);

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertTrue($first['promotion_tmp_removed'] ?? false);
        $this->assertSame($staging['sha256'], $first['sha256']);
        $this->assertSame($first['sha256'], $second['sha256']);
        $this->assertSame($content, File::get($this->root.'/submitted/record-1/record.json'));
        $this->assertSame([], glob($this->root.'/submitted/record-1/.record.json.promote-*.tmp') ?: []);
        $this->assertFileExists($this->root.'/submitted/record-1/record.json');
    }

    public function test_publish_final_can_defer_promotion_tmp_cleanup_and_cleanup_is_idempotent(): void
    {
        $store = $this->makeStore();
        $content = '{"final":true}';
        $staging = $store->writeSubmissionStaging('record-1', 'intent-1', $content);

        $published = $store->publishFinalSubmittedArtifact('record-1', $staging['relative_path'], $staging['sha256'], $staging['sha256'], false);

        $this->assertFalse($published['idempotent']);
        $this->assertFalse($published['promotion_tmp_removed']);
        $this->assertNotEmpty($published['promotion_tmp_relative_path']);
        $tmpRelative = $published['promotion_tmp_relative_path'];
        $this->assertFileExists($this->root.'/'.$tmpRelative);
        $this->assertSame($content, File::get($this->root.'/submitted/record-1/record.json'));

        $cleanup = $store->cleanupPromotionTempAfterEvidenceCommit('record-1', $tmpRelative, $staging['sha256'], $staging['sha256']);
        $this->assertTrue($cleanup['removed']);
        $this->assertFileDoesNotExist($this->root.'/'.$tmpRelative);
        $this->assertSame($content, File::get($this->root.'/submitted/record-1/record.json'));

        $cleanupAgain = $store->cleanupPromotionTempAfterEvidenceCommit('record-1', $tmpRelative, $staging['sha256'], $staging['sha256']);
        $this->assertTrue($cleanupAgain['removed']);
        $this->assertFileExists($this->root.'/submitted/record-1/record.json');
    }

    public function test_cleanup_promotion_temp_rejects_staging_or_final_paths_and_verifies_final_hash(): void
    {
        $store = $this->makeStore();
        $staging = $store->writeSubmissionStaging('record-1', 'intent-1', '{"final":true}');
        $store->publishFinalSubmittedArtifact('record-1', $staging['relative_path'], $staging['sha256'], $staging['sha256'], false);

        try {
            $store->cleanupPromotionTempAfterEvidenceCommit('record-1', $staging['relative_path'], $staging['sha256']);
            $this->fail('Expected staging path rejection.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Promotion temp path', $e->getMessage());
        }

        try {
            $store->cleanupPromotionTempAfterEvidenceCommit('record-1', 'submitted/record-1/record.json', $staging['sha256']);
            $this->fail('Expected final path rejection.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Promotion temp path', $e->getMessage());
        }

        try {
            $store->cleanupPromotionTempAfterEvidenceCommit('record-1', 'submitted/record-1/.record.json.promote-fake.tmp', hash('sha256', 'wrong'));
            $this->fail('Expected final hash verification failure.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('hash mismatch', strtolower($e->getMessage()));
        }

        $this->assertFileExists($this->root.'/submitted/record-1/record.json');
    }

    public function test_publish_final_fails_closed_for_different_hash_and_keeps_final_unchanged(): void
    {
        $store = $this->makeStore();
        $content = '{"final":true}';
        $staging = $store->writeSubmissionStaging('record-1', 'intent-1', $content);
        $store->publishFinalSubmittedArtifact('record-1', $staging['relative_path'], $staging['sha256']);
        $before = File::get($this->root.'/submitted/record-1/record.json');

        $other = $store->writeSubmissionStaging('record-1', 'intent-2', '{"final":"different"}');

        try {
            $store->publishFinalSubmittedArtifact('record-1', $other['relative_path'], $other['sha256']);
            $this->fail('Expected integrity conflict.');
        } catch (ArtifactIntegrityConflictException $e) {
            $this->assertSame($before, File::get($this->root.'/submitted/record-1/record.json'));
        }
    }

    public function test_final_artifact_remains_unchanged_after_second_publish_attempt(): void
    {
        $store = $this->makeStore();
        $original = '{"final":"original"}';
        $staging = $store->writeSubmissionStaging('record-1', 'intent-1', $original);
        $store->publishFinalSubmittedArtifact('record-1', $staging['relative_path'], $staging['sha256']);
        $before = File::get($this->root.'/submitted/record-1/record.json');

        try {
            $different = $store->writeSubmissionStaging('record-1', 'intent-2', '{"final":"tampered"}');
            $store->publishFinalSubmittedArtifact('record-1', $different['relative_path'], $different['sha256']);
            $this->fail('Expected integrity conflict.');
        } catch (ArtifactIntegrityConflictException $e) {
            // expected
        }

        $this->assertSame($before, File::get($this->root.'/submitted/record-1/record.json'));
    }

    public function test_hash_verification_succeeds_for_matching_artifact(): void
    {
        $store = $this->makeStore();
        $content = '{"hash":"ok"}';
        $draft = $store->writeDraftVersion('record-1', $content, 'v1');

        $result = $store->verifyStoredArtifact($draft['relative_path'], $draft['sha256']);

        $this->assertTrue($result['verified']);
        $this->assertSame($draft['sha256'], $result['sha256']);
    }

    public function test_hash_verification_reports_missing_file(): void
    {
        $store = $this->makeStore();

        $this->expectException(ArtifactNotFoundException::class);
        $store->verifyStoredArtifact('drafts/record-1/versions/missing.json', hash('sha256', 'x'));
    }

    public function test_hash_verification_reports_mismatch(): void
    {
        $store = $this->makeStore();
        $draft = $store->writeDraftVersion('record-1', '{"x":1}', 'v1');

        $this->expectException(ArtifactHashMismatchException::class);
        $store->verifyStoredArtifact($draft['relative_path'], hash('sha256', 'different'));
    }

    public function test_path_traversal_is_rejected(): void
    {
        $store = $this->makeStore();

        $this->expectException(ArtifactPathException::class);
        $store->writeDraftVersion('../escape', '{"x":1}');
    }

    public function test_absolute_path_is_rejected(): void
    {
        $store = $this->makeStore();
        $absolute = $this->root.'/submitted/record-1/staging/intent-1.json';

        $this->expectException(ArtifactPathException::class);
        $store->publishFinalSubmittedArtifact('record-1', $absolute, hash('sha256', 'x'));
    }

    public function test_windows_path_traversal_and_drive_letters_are_rejected(): void
    {
        $store = $this->makeStore();

        try {
            $store->writeDraftVersion('record-1', '{"x":1}', '..\\..\\evil');
            $this->fail('Expected path rejection.');
        } catch (ArtifactPathException $e) {
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }

        $this->expectException(ArtifactPathException::class);
        $store->verifyStoredArtifact('C:\\Windows\\win.ini', hash('sha256', 'x'));
    }

    public function test_symlink_escape_is_rejected_where_supported(): void
    {
        if (! $this->canCreateSymlinks()) {
            $this->markTestSkipped('Symlinks cannot be created in this environment.');
        }

        $store = $this->makeStore();
        $outside = storage_path('framework/testing/annotation-outside-'.uniqid('', true));
        File::ensureDirectoryExists($outside);
        File::put($outside.'/secret.json', '{"secret":true}');
        $recordDir = $this->root.'/drafts/record-1/versions';
        File::ensureDirectoryExists($recordDir);
        $link = $recordDir.'/leak.json';

        try {
            symlink($outside.'/secret.json', $link);
            $this->expectException(ArtifactPathException::class);
            $store->readArtifactSha256('drafts/record-1/versions/leak.json');
        } finally {
            if (is_link($link) || is_file($link)) {
                @unlink($link);
            }
            File::deleteDirectory($outside);
        }
    }

    public function test_same_record_lock_serializes_critical_section(): void
    {
        $store = $this->makeStore();
        $recordId = 'record-lock';
        $lockFile = $this->root.'/submitted/'.$recordId.'/.promotion.lock';
        $counter = $this->root.'/counter.txt';
        $script = $this->lockWorkerScript();

        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $processes[] = new Process([
                PHP_BINARY,
                $script,
                $this->root,
                $recordId,
                $counter,
                '200000',
            ], base_path(), $this->processEnv(), null, 30);
        }

        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
        }

        foreach ($processes as $process) {
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        }

        $this->assertFileExists($lockFile);
        $this->assertSame('2', trim(File::get($counter)));
    }

    public function test_different_records_do_not_block_each_other(): void
    {
        $store = $this->makeStore();
        $started = microtime(true);
        $script = $this->sleepLockScript();

        $p1 = new Process([PHP_BINARY, $script, $this->root, 'record-a', '1'], base_path(), $this->processEnv(), null, 30);
        $p2 = new Process([PHP_BINARY, $script, $this->root, 'record-b', '1'], base_path(), $this->processEnv(), null, 30);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $elapsed = microtime(true) - $started;
        $this->assertTrue($p1->isSuccessful(), $p1->getErrorOutput());
        $this->assertTrue($p2->isSuccessful(), $p2->getErrorOutput());
        $this->assertLessThan(1.8, $elapsed, 'Different record locks should not serialize for ~1s each.');
    }

    public function test_lock_timeout_fails_closed(): void
    {
        $this->configureStore($this->root, 0);
        $store = $this->makeStore();
        $recordId = 'record-timeout';
        $script = $this->holdLockScript();
        $ready = $this->root.'/lock-ready.txt';
        $release = $this->root.'/lock-release.txt';

        $holder = new Process([PHP_BINARY, $script, $this->root, $recordId, $ready, $release], base_path(), $this->processEnv(), null, 30);
        $holder->start();
        $this->waitForFile($ready);

        try {
            $this->expectException(ArtifactLockException::class);
            $store->withRecordPromotionLock($recordId, fn () => true);
        } finally {
            File::put($release, '1');
            $holder->wait();
        }

        $this->assertTrue($holder->isSuccessful(), $holder->getOutput().$holder->getErrorOutput());
    }

    public function test_lock_is_released_after_exception(): void
    {
        $store = $this->makeStore();
        $recordId = 'record-ex';

        try {
            $store->withRecordPromotionLock($recordId, function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $result = $store->withRecordPromotionLock($recordId, fn () => 'ok');
        $this->assertSame('ok', $result);
    }

    public function test_unsupported_driver_fails_closed(): void
    {
        config([
            'annotation.annotation_storage_driver' => 's3',
            'annotation.annotation_storage_disk' => 'annotation',
            'filesystems.disks.annotation.driver' => 's3',
        ]);

        $this->expectException(ArtifactUnsupportedDriverException::class);
        new AnnotationArtifactStore(config('annotation'));
    }

    public function test_lock_capability_failure_is_reported_as_lock_exception(): void
    {
        $store = $this->makeStore();
        $recordId = 'record-lock-fail';
        $lockDir = $this->root.'/submitted/'.$recordId;
        File::ensureDirectoryExists($lockDir);
        $lockPath = $lockDir.'/.promotion.lock';
        File::ensureDirectoryExists($lockPath); // directory at lock file path makes fopen(c+b) fail

        $this->expectException(ArtifactLockException::class);
        $store->withRecordPromotionLock($recordId, fn () => true);
    }

    public function test_failed_promotion_tmp_cleanup_is_explicit_and_inspectable(): void
    {
        $store = new class(config('annotation')) extends AnnotationArtifactStore
        {
            protected function cleanupPublishedPromotionTemp(string $tmpAbsolute, string $finalAbsolute, string $expectedSha256, ?string $secondExpectedSha256): array
            {
                throw new ArtifactStorageException('simulated promotion tmp cleanup failure');
            }
        };

        $content = '{"final":true}';
        $staging = $store->writeSubmissionStaging('record-1', 'intent-1', $content);

        try {
            $store->publishFinalSubmittedArtifact('record-1', $staging['relative_path'], $staging['sha256']);
            $this->fail('Expected cleanup failure.');
        } catch (ArtifactStorageException $e) {
            $this->assertStringContainsString('simulated promotion tmp cleanup failure', $e->getMessage());
        }

        $this->assertFileExists($this->root.'/submitted/record-1/record.json');
        $this->assertSame($content, File::get($this->root.'/submitted/record-1/record.json'));
        $tmps = glob($this->root.'/submitted/record-1/.record.json.promote-*.tmp') ?: [];
        $this->assertCount(1, $tmps);
        $inspection = $store->inspectRecordArtifacts('record-1');
        $this->assertCount(1, $inspection['promotion_temps']);
    }

    public function test_concurrent_draft_publish_same_version_id_never_overwrites(): void
    {
        $script = $this->draftWorkerScript();
        $version = 'same-version';
        $record = 'record-concurrent-draft';
        $procs = [];
        for ($i = 0; $i < 2; $i++) {
            $procs[] = new Process([PHP_BINARY, $script, $this->root, $record, $version, "payload-$i"], base_path(), $this->processEnv(), null, 30);
        }
        foreach ($procs as $p) {
            $p->start();
        }
        foreach ($procs as $p) {
            $p->wait();
        }

        $outputs = array_map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()), $procs);
        $successes = array_filter($outputs, fn ($o) => str_contains($o, 'OK'));
        $conflicts = array_filter($outputs, fn ($o) => str_contains($o, 'ArtifactAlreadyExistsException'));

        $this->assertCount(1, $successes, implode("\n---\n", $outputs));
        $this->assertCount(1, $conflicts, implode("\n---\n", $outputs));
        $final = File::get($this->root.'/drafts/'.$record.'/versions/'.$version.'.json');
        $this->assertTrue($final === 'payload-0' || $final === 'payload-1', 'Final content must be one of the original payloads.');
    }

    public function test_concurrent_final_publish_never_replaces_existing_content(): void
    {
        $store = $this->makeStore();
        $staging = $store->writeSubmissionStaging('record-1', 'intent-1', '{"same":true}');
        $script = $this->finalWorkerScript();

        $procs = [];
        for ($i = 0; $i < 2; $i++) {
            $procs[] = new Process([PHP_BINARY, $script, $this->root, 'record-1', $staging['relative_path'], $staging['sha256']], base_path(), $this->processEnv(), null, 30);
        }
        foreach ($procs as $p) {
            $p->start();
        }
        foreach ($procs as $p) {
            $p->wait();
        }

        $outputs = array_map(fn (Process $p) => trim($p->getOutput().$p->getErrorOutput()), $procs);
        foreach ($procs as $idx => $p) {
            $this->assertTrue($p->isSuccessful(), $outputs[$idx]);
        }
        $this->assertStringContainsString('{"same":true}', File::get($this->root.'/submitted/record-1/record.json'));
        $this->assertSame([], glob($this->root.'/submitted/record-1/.record.json.promote-*.tmp') ?: []);
    }

    public function test_draft_publication_removes_tmp_hard_link_on_success(): void
    {
        $store = $this->makeStore();
        $result = $store->writeDraftVersion('record-1', '{"draft":true}', 'v1');
        $this->assertFileExists($this->root.'/'.$result['relative_path']);
        $this->assertSame([], glob($this->root.'/drafts/record-1/tmp/.tmp-*.json') ?: []);
    }

    public function test_staging_publication_removes_tmp_hard_link_on_success(): void
    {
        $store = $this->makeStore();
        $result = $store->writeSubmissionStaging('record-1', 'intent-1', '{"staged":true}');
        $this->assertFileExists($this->root.'/'.$result['relative_path']);
        $this->assertSame([], glob($this->root.'/submitted/record-1/staging/.tmp-*.json') ?: []);
    }

    public function test_draft_unlink_failure_after_hard_link_rolls_back_and_fails_closed(): void
    {
        $store = new class(config('annotation')) extends AnnotationArtifactStore
        {
            public bool $failTmpUnlink = true;

            public bool $rollbackAttempted = false;

            public ?string $tmp = null;

            public ?string $final = null;

            protected function cleanupPublishedTempHardLink(string $tempAbsolute, string $finalAbsolute): void
            {
                $this->tmp = $tempAbsolute;
                $this->final = $finalAbsolute;
                parent::cleanupPublishedTempHardLink($tempAbsolute, $finalAbsolute);
            }

            protected function unlinkPath(string $absolutePath): bool
            {
                if ($this->failTmpUnlink && $this->tmp !== null && $absolutePath === $this->tmp) {
                    return false;
                }
                if ($this->final !== null && $absolutePath === $this->final) {
                    $this->rollbackAttempted = true;
                }

                return parent::unlinkPath($absolutePath);
            }
        };

        try {
            $store->writeDraftVersion('record-1', '{"draft":true}', 'v1');
            $this->fail('Expected fail-closed exception.');
        } catch (ArtifactStorageException $e) {
            $this->assertStringContainsString('rollback removed newly published destination', $e->getMessage());
            $this->assertStringContainsString((string) $store->tmp, $e->getMessage());
            $this->assertStringContainsString((string) $store->final, $e->getMessage());
        }

        $this->assertTrue($store->rollbackAttempted);
        $this->assertNotNull($store->final);
        $this->assertFileDoesNotExist((string) $store->final);
        $this->assertFileDoesNotExist($this->root.'/drafts/record-1/versions/v1.json');
    }

    public function test_staging_unlink_failure_after_hard_link_rolls_back_and_fails_closed(): void
    {
        $store = new class(config('annotation')) extends AnnotationArtifactStore
        {
            public bool $failTmpUnlink = true;

            public bool $rollbackAttempted = false;

            public ?string $tmp = null;

            public ?string $final = null;

            protected function cleanupPublishedTempHardLink(string $tempAbsolute, string $finalAbsolute): void
            {
                $this->tmp = $tempAbsolute;
                $this->final = $finalAbsolute;
                parent::cleanupPublishedTempHardLink($tempAbsolute, $finalAbsolute);
            }

            protected function unlinkPath(string $absolutePath): bool
            {
                if ($this->failTmpUnlink && $this->tmp !== null && $absolutePath === $this->tmp) {
                    return false;
                }
                if ($this->final !== null && $absolutePath === $this->final) {
                    $this->rollbackAttempted = true;
                }

                return parent::unlinkPath($absolutePath);
            }
        };

        try {
            $store->writeSubmissionStaging('record-1', 'intent-1', '{"staged":true}');
            $this->fail('Expected fail-closed exception.');
        } catch (ArtifactStorageException $e) {
            $this->assertStringContainsString('rollback removed newly published destination', $e->getMessage());
            $this->assertStringContainsString((string) $store->tmp, $e->getMessage());
            $this->assertStringContainsString((string) $store->final, $e->getMessage());
        }

        $this->assertTrue($store->rollbackAttempted);
        $this->assertFileDoesNotExist((string) $store->final);
        $this->assertFileDoesNotExist($this->root.'/submitted/record-1/staging/intent-1.json');
    }

    public function test_rollback_failure_after_temp_unlink_failure_throws_integrity_conflict(): void
    {
        $store = new class(config('annotation')) extends AnnotationArtifactStore
        {
            public ?string $tmp = null;

            public ?string $final = null;

            protected function cleanupPublishedTempHardLink(string $tempAbsolute, string $finalAbsolute): void
            {
                $this->tmp = $tempAbsolute;
                $this->final = $finalAbsolute;
                parent::cleanupPublishedTempHardLink($tempAbsolute, $finalAbsolute);
            }

            protected function unlinkPath(string $absolutePath): bool
            {
                if ($this->tmp !== null && $absolutePath === $this->tmp) {
                    return false;
                }
                if ($this->final !== null && $absolutePath === $this->final) {
                    return false;
                }

                return parent::unlinkPath($absolutePath);
            }
        };

        try {
            $store->writeDraftVersion('record-1', '{"draft":true}', 'v1');
            $this->fail('Expected integrity conflict.');
        } catch (ArtifactIntegrityConflictException $e) {
            $this->assertStringContainsString('reconciliation required', $e->getMessage());
            $this->assertStringContainsString('final_exists=yes', $e->getMessage());
            $this->assertStringContainsString('temp_exists=yes', $e->getMessage());
            $this->assertStringContainsString((string) $store->tmp, $e->getMessage());
            $this->assertStringContainsString((string) $store->final, $e->getMessage());
        }

        $this->assertFileExists((string) $store->final);
        $this->assertFileExists((string) $store->tmp);
    }

    public function test_annotation_disk_is_not_publicly_exposed(): void
    {
        $links = config('filesystems.links', []);
        foreach ($links as $target) {
            $this->assertStringNotContainsString('annotation-artifacts', (string) $target);
        }

        $this->assertFalse(config('filesystems.disks.annotation.serve', false));
        $this->assertSame('local', config('filesystems.disks.annotation.driver'));
        $this->assertStringNotContainsString('app/public', str_replace('\\', '/', (string) config('filesystems.disks.annotation.root')));
    }

    public function test_reconciliation_inspection_reports_record_artifacts_and_orphan_tmp(): void
    {
        $store = $this->makeStore();
        $draft = $store->writeDraftVersion('record-1', '{"d":1}', 'v1');
        $staging = $store->writeSubmissionStaging('record-1', 'intent-1', '{"s":1}');
        $store->publishFinalSubmittedArtifact('record-1', $staging['relative_path'], $staging['sha256']);

        $orphan = $this->root.'/submitted/record-1/.record.json.promote-orphan.tmp';
        File::put($orphan, '{"orphan":true}');

        $inspection = $store->inspectRecordArtifacts('record-1');

        $this->assertSame('record-1', $inspection['record_id']);
        $this->assertCount(1, $inspection['draft_versions']);
        $this->assertSame($draft['relative_path'], $inspection['draft_versions'][0]['relative_path']);
        $this->assertCount(1, $inspection['staging_artifacts']);
        $this->assertSame($staging['relative_path'], $inspection['staging_artifacts'][0]['relative_path']);
        $this->assertTrue($inspection['final_artifact']['exists']);
        $this->assertSame('submitted/record-1/record.json', $inspection['final_artifact']['relative_path']);
        $this->assertCount(1, $inspection['promotion_temps']);
        $this->assertSame('submitted/record-1/.record.json.promote-orphan.tmp', $inspection['promotion_temps'][0]['relative_path']);
    }

    private function configureStore(string $root, int $lockWaitSeconds = 5): void
    {
        File::ensureDirectoryExists($root);
        config([
            'filesystems.disks.annotation' => [
                'driver' => 'local',
                'root' => $root,
                'serve' => false,
                'throw' => true,
                'report' => false,
            ],
            'annotation.annotation_storage_disk' => 'annotation',
            'annotation.annotation_storage_driver' => 'local',
            'annotation.annotation_artifact_root' => $root,
            'annotation.annotation_draft_versions_path' => 'drafts',
            'annotation.annotation_submitted_path' => 'submitted',
            'annotation.annotation_validation_report_path' => 'validation-runs',
            'annotation.annotation_temp_path' => 'tmp',
            'annotation.annotation_promotion_lock_wait_seconds' => $lockWaitSeconds,
        ]);
    }

    private function makeStore(): AnnotationArtifactStore
    {
        return new AnnotationArtifactStore(config('annotation'));
    }

    private function canCreateSymlinks(): bool
    {
        $dir = storage_path('framework/testing/symlink-probe-'.uniqid('', true));
        File::ensureDirectoryExists($dir);
        $target = $dir.'/target.txt';
        $link = $dir.'/link.txt';
        File::put($target, 'x');
        $ok = @symlink($target, $link);
        File::deleteDirectory($dir);

        return $ok === true;
    }

    /** @return array<string,string> */
    private function processEnv(): array
    {
        $env = [];
        foreach (['SYSTEMROOT', 'WINDIR', 'PATH', 'COMSPEC', 'TEMP', 'TMP', 'HOME', 'USERPROFILE'] as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    private function waitForFile(string $path, int $timeoutMs = 5000): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                return;
            }
            usleep(10000);
        }
        $this->fail('Timed out waiting for file: '.$path);
    }

    private function lockWorkerScript(): string
    {
        $script = storage_path('framework/testing/annotation-lock-worker.php');
        File::ensureDirectoryExists(dirname($script));
        File::put($script, <<<'PHP'
<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
[$_, $root, $recordId, $counter, $usleep] = $argv;
config([
    'filesystems.disks.annotation' => ['driver' => 'local', 'root' => $root, 'serve' => false, 'throw' => true, 'report' => false],
    'annotation.annotation_storage_disk' => 'annotation',
    'annotation.annotation_storage_driver' => 'local',
    'annotation.annotation_artifact_root' => $root,
    'annotation.annotation_promotion_lock_wait_seconds' => 10,
]);
$store = new App\Services\Annotation\Storage\AnnotationArtifactStore(config('annotation'));
$store->withRecordPromotionLock($recordId, function () use ($counter, $usleep): void {
    $current = is_file($counter) ? (int) trim(file_get_contents($counter)) : 0;
    usleep((int) $usleep);
    file_put_contents($counter, (string) ($current + 1));
});
PHP);

        return $script;
    }

    private function sleepLockScript(): string
    {
        $script = storage_path('framework/testing/annotation-sleep-lock.php');
        File::ensureDirectoryExists(dirname($script));
        File::put($script, <<<'PHP'
<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
[$_, $root, $recordId, $seconds] = $argv;
config([
    'filesystems.disks.annotation' => ['driver' => 'local', 'root' => $root, 'serve' => false, 'throw' => true, 'report' => false],
    'annotation.annotation_storage_disk' => 'annotation',
    'annotation.annotation_storage_driver' => 'local',
    'annotation.annotation_artifact_root' => $root,
    'annotation.annotation_promotion_lock_wait_seconds' => 10,
]);
$store = new App\Services\Annotation\Storage\AnnotationArtifactStore(config('annotation'));
$store->withRecordPromotionLock($recordId, function () use ($seconds): void {
    sleep((int) $seconds);
});
PHP);

        return $script;
    }

    private function holdLockScript(): string
    {
        $script = storage_path('framework/testing/annotation-hold-lock.php');
        File::ensureDirectoryExists(dirname($script));
        File::put($script, <<<'PHP'
<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
[$_, $root, $recordId, $ready, $release] = $argv;
config([
    'filesystems.disks.annotation' => ['driver' => 'local', 'root' => $root, 'serve' => false, 'throw' => true, 'report' => false],
    'annotation.annotation_storage_disk' => 'annotation',
    'annotation.annotation_storage_driver' => 'local',
    'annotation.annotation_artifact_root' => $root,
    'annotation.annotation_promotion_lock_wait_seconds' => 10,
]);
$store = new App\Services\Annotation\Storage\AnnotationArtifactStore(config('annotation'));
$store->withRecordPromotionLock($recordId, function () use ($ready, $release): void {
    file_put_contents($ready, '1');
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        if (is_file($release)) {
            return;
        }
        usleep(10000);
    }
    throw new RuntimeException('release not seen');
});
PHP);

        return $script;
    }

    private function draftWorkerScript(): string
    {
        $script = storage_path('framework/testing/annotation-draft-worker.php');
        File::ensureDirectoryExists(dirname($script));
        File::put($script, <<<'PHP'
<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
[$_, $root, $recordId, $versionId, $payload] = $argv;
config([
    'filesystems.disks.annotation' => ['driver' => 'local', 'root' => $root, 'serve' => false, 'throw' => true, 'report' => false],
    'annotation.annotation_storage_disk' => 'annotation',
    'annotation.annotation_storage_driver' => 'local',
    'annotation.annotation_artifact_root' => $root,
    'annotation.annotation_promotion_lock_wait_seconds' => 5,
]);
$store = new App\Services\Annotation\Storage\AnnotationArtifactStore(config('annotation'));
try {
    $store->writeDraftVersion($recordId, $payload, $versionId);
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e)."\n";
}
PHP);

        return $script;
    }

    private function finalWorkerScript(): string
    {
        $script = storage_path('framework/testing/annotation-final-worker.php');
        File::ensureDirectoryExists(dirname($script));
        File::put($script, <<<'PHP'
<?php
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
[$_, $root, $recordId, $stagingRelative, $sha] = $argv;
config([
    'filesystems.disks.annotation' => ['driver' => 'local', 'root' => $root, 'serve' => false, 'throw' => true, 'report' => false],
    'annotation.annotation_storage_disk' => 'annotation',
    'annotation.annotation_storage_driver' => 'local',
    'annotation.annotation_artifact_root' => $root,
    'annotation.annotation_promotion_lock_wait_seconds' => 5,
]);
$store = new App\Services\Annotation\Storage\AnnotationArtifactStore(config('annotation'));
try {
    $res = $store->publishFinalSubmittedArtifact($recordId, $stagingRelative, $sha, $sha);
    echo ($res['idempotent'] ? 'IDEMPOTENT' : 'OK')."\n";
} catch (Throwable $e) {
    echo get_class($e).':'.$e->getMessage()."\n";
    exit(1);
}
PHP);

        return $script;
    }
}
