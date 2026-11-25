<?php

namespace Tests\Unit\Services\Annotation\Registries;

use App\Services\Annotation\Registries\ApprovedTaxonomyRegistry;
use App\Services\Annotation\Registries\ImageIdRegistry;
use App\Services\Annotation\Registries\LabelMappingDecisionRegistry;
use App\Services\Annotation\Registries\MasterImageInventoryRegistry;
use App\Services\Annotation\Registries\RegistryFileCache;
use App\Services\Annotation\Registries\RegistryPathResolver;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RegistryServicesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
        $this->tmp = storage_path('framework/testing/registries-'.uniqid());
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmp);
        parent::tearDown();
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) return;
        $items = scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) $this->deleteDir($path);
            else @unlink($path);
        }
        @rmdir($dir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);
        return $path;
    }

    private function config(array $overrides = []): array
    {
        $base = [
            'b1_workdir' => base_path('..'),
            'registry_taxonomy_path' => $this->write('taxonomy.json', json_encode([
                'taxonomy_version' => 'v1',
                'source_schema' => 's.json',
                'generated_at' => '2026-01-01T00:00:00Z',
                'in_scope_classes' => ['curry_leaves'],
                'out_of_scope_classes' => ['salt'],
                'processing_states' => ['whole'],
            ])),
            'registry_decisions_path' => $this->write('decisions.json', json_encode([
                'version' => 'd1',
                'generated_at' => '2026-01-01T00:00:00Z',
                'scope' => 'test',
                'policy' => ['preserve_master_csv_labels' => true],
                'decisions' => [
                    ['decision_type' => 'approved_alias', 'canonical_mapping_pending' => false],
                    ['decision_type' => 'pending_canonical_mapping', 'canonical_mapping_pending' => true],
                ],
            ])),
            'registry_image_id_registry_path' => $this->write('image_ids.json', json_encode([
                'registry_version' => 'r1',
                'next_sequence' => 3,
                'path_to_id' => [
                    'Black Pepper/a.jpg' => 'IMG-000001',
                    'Black Pepper/b.jpg' => 'IMG-000002',
                ],
            ])),
            'registry_inventory_path' => $this->write('inventory.csv', implode("\n", [
                'image_id,batch_id,original_folder,original_filename,relative_path,file_extension,file_size_bytes,image_width,image_height,image_mode,image_format,sha256,folder_class_proposal,mapping_status,scan_status,scan_timestamp,first_seen_timestamp,error_message,duplicate_group_id,duplicate_of_image_id,is_exact_duplicate',
                'IMG-000001,B1,Black Pepper,a.jpg,Black Pepper/a.jpg,.jpg,1,10,10,RGB,JPEG,abc,,proposed_pending_review,success_cached,t1,t2,,,,False',
                'IMG-000002,B1,Black Pepper,b.jpg,Black Pepper/b.jpg,.jpg,2,10,10,RGB,JPEG,def,,ambiguous_pending_review,success_cached,t1,t2,,,,False',
                '',
            ])),
            'registry_cache_ttl_seconds' => 30,
            'registry_inventory_default_per_page' => 25,
            'registry_inventory_max_per_page' => 100,
        ];
        return array_merge($base, $overrides);
    }

    public function test_taxonomy_parses_and_summarizes(): void
    {
        $cfg = $this->config();
        $resolver = new RegistryPathResolver($cfg);
        $svc = new ApprovedTaxonomyRegistry($resolver, new RegistryFileCache(30));
        $data = $svc->get();
        $this->assertSame('v1', $data['taxonomy_version']);
        $this->assertSame(['curry_leaves'], $data['in_scope_classes']);
        $this->assertSame(1, $svc->summary()['in_scope_count']);
    }

    public function test_taxonomy_invalid_json_fails_closed(): void
    {
        $cfg = $this->config(['registry_taxonomy_path' => $this->write('bad.json', '{')]);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->expectException(\RuntimeException::class);
        $svc->get();
    }

    public function test_decisions_parse(): void
    {
        $cfg = $this->config();
        $svc = new LabelMappingDecisionRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $data = $svc->get();
        $this->assertSame('d1', $data['version']);
        $this->assertCount(2, $data['decisions']);
        $this->assertSame(1, $svc->summary()['approved_alias_count']);
        $this->assertSame(1, $svc->summary()['pending_count']);
    }

    public function test_inventory_paginates_and_filters(): void
    {
        $cfg = $this->config();
        $svc = new MasterImageInventoryRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $page = $svc->paginate(['mapping_status' => 'proposed_pending_review'], 1, 1);
        $this->assertSame(1, $page->total());
        $this->assertSame('IMG-000001', $page->items()[0]['image_id']);
    }

    public function test_image_id_lookup_and_consistency(): void
    {
        $cfg = $this->config();
        $resolver = new RegistryPathResolver($cfg);
        $cache = new RegistryFileCache(30);
        $ids = new ImageIdRegistry($resolver, $cache);
        $inv = new MasterImageInventoryRegistry($resolver, $cache);
        $this->assertSame('Black Pepper/a.jpg', $ids->findByImageId('IMG-000001'));
        $this->assertSame('IMG-000002', $ids->findByRelativePath('Black Pepper/b.jpg'));
        $this->assertSame('IMG-000001', $inv->findByRelativePath('Black Pepper/a.jpg')['image_id']);
    }

    public function test_inconsistent_registry_rejected(): void
    {
        $cfg = $this->config([
            'registry_image_id_registry_path' => $this->write('image_ids_bad.json', json_encode([
                'registry_version' => 'r1', 'next_sequence' => 3,
                'path_to_id' => ['Black Pepper/a.jpg' => 'IMG-999999', 'Black Pepper/b.jpg' => 'IMG-000002'],
            ])),
        ]);
        $resolver = new RegistryPathResolver($cfg);
        $cache = new RegistryFileCache(30);
        $health = new \App\Services\Annotation\Registries\RegistryHealthService(
            $resolver,
            new ApprovedTaxonomyRegistry($resolver, $cache),
            new LabelMappingDecisionRegistry($resolver, $cache),
            new ImageIdRegistry($resolver, $cache),
            new MasterImageInventoryRegistry($resolver, $cache),
        );
        try {
            $health->assertConsistency();
            $this->fail('Expected inconsistency exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('registry mapping mismatch', strtolower($e->getMessage()));
        }
    }

    public function test_missing_file_fails_closed(): void
    {
        $cfg = $this->config(['registry_taxonomy_path' => $this->tmp.'/missing.json']);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->expectException(\RuntimeException::class);
        $svc->get();
    }

    public function test_directory_path_fails_closed(): void
    {
        $dir = $this->tmp.DIRECTORY_SEPARATOR.'as-dir';
        @mkdir($dir, 0777, true);
        $cfg = $this->config(['registry_taxonomy_path' => $dir]);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->expectException(\RuntimeException::class);
        $svc->get();
    }

    public function test_unreadable_file_fails_closed(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('File permission denial is not reliably enforceable on Windows in this environment.');
        }

        $path = $this->write('unreadable.json', '{"ok":true}');
        @chmod($path, 0000);
        clearstatcache(true, $path);
        if (is_readable($path)) {
            @chmod($path, 0644);
            $this->markTestSkipped('Unable to make file unreadable on this platform.');
        }

        try {
            $cfg = $this->config(['registry_taxonomy_path' => $path]);
            $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
            $this->expectException(\RuntimeException::class);
            $svc->get();
        } finally {
            @chmod($path, 0644);
        }
    }

    public function test_malformed_or_unresolved_path_fails_closed(): void
    {
        $badPath = $this->tmp.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'not-a-registry.json';
        $cfg = $this->config(['registry_taxonomy_path' => $badPath]);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->expectException(\RuntimeException::class);
        $svc->get();
    }

    public function test_final_component_symlink_rejected(): void
    {
        $target = $this->write('real-taxonomy.json', json_encode([
            'taxonomy_version' => 'v1', 'source_schema' => 's', 'generated_at' => 't',
            'in_scope_classes' => ['a'], 'out_of_scope_classes' => [], 'processing_states' => [],
        ]));
        $link = $this->tmp.DIRECTORY_SEPARATOR.'taxonomy-link.json';
        $this->requireSymlink($target, $link, 'final component symlink');

        $cfg = $this->config(['registry_taxonomy_path' => $link]);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->expectException(\RuntimeException::class);
        $svc->get();
    }

    public function test_symlinked_parent_directory_rejected(): void
    {
        $realDir = $this->tmp.DIRECTORY_SEPARATOR.'real-dir';
        @mkdir($realDir, 0777, true);
        file_put_contents($realDir.DIRECTORY_SEPARATOR.'taxonomy.json', json_encode([
            'taxonomy_version' => 'v1', 'source_schema' => 's', 'generated_at' => 't',
            'in_scope_classes' => ['a'], 'out_of_scope_classes' => [], 'processing_states' => [],
        ]));

        $linkDir = $this->tmp.DIRECTORY_SEPARATOR.'link-dir';
        $this->requireSymlink($realDir, $linkDir, 'symlinked parent directory');

        $cfg = $this->config(['registry_taxonomy_path' => $linkDir.DIRECTORY_SEPARATOR.'taxonomy.json']);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->expectException(\RuntimeException::class);
        $svc->get();
    }

    public function test_nested_symlinked_parent_directory_rejected(): void
    {
        $realDir = $this->tmp.DIRECTORY_SEPARATOR.'real-nested';
        @mkdir($realDir, 0777, true);
        file_put_contents($realDir.DIRECTORY_SEPARATOR.'taxonomy.json', json_encode([
            'taxonomy_version' => 'v1', 'source_schema' => 's', 'generated_at' => 't',
            'in_scope_classes' => ['a'], 'out_of_scope_classes' => [], 'processing_states' => [],
        ]));

        $parentLink = $this->tmp.DIRECTORY_SEPARATOR.'parent-link';
        $this->requireSymlink($this->tmp, $parentLink, 'nested symlinked parent (parent link)');

        $childLink = $parentLink.DIRECTORY_SEPARATOR.'child-link';
        $this->requireSymlink($realDir, $childLink, 'nested symlinked parent (child link)');

        $cfg = $this->config(['registry_taxonomy_path' => $childLink.DIRECTORY_SEPARATOR.'taxonomy.json']);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->expectException(\RuntimeException::class);
        $svc->get();
    }

    public function test_valid_relative_path_resolved_through_b1_workdir(): void
    {
        $workdir = $this->tmp.DIRECTORY_SEPARATOR.'workdir';
        @mkdir($workdir.DIRECTORY_SEPARATOR.'registries', 0777, true);
        $real = $workdir.DIRECTORY_SEPARATOR.'registries'.DIRECTORY_SEPARATOR.'taxonomy.json';
        file_put_contents($real, json_encode([
            'taxonomy_version' => 'v1', 'source_schema' => 's', 'generated_at' => 't',
            'in_scope_classes' => ['a'], 'out_of_scope_classes' => [], 'processing_states' => [],
        ]));

        $cfg = $this->config([
            'b1_workdir' => $workdir,
            'registry_taxonomy_path' => 'registries/taxonomy.json',
        ]);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->assertSame('v1', $svc->get()['taxonomy_version']);
    }

    public function test_valid_absolute_path_accepted(): void
    {
        $path = $this->write('absolute-taxonomy.json', json_encode([
            'taxonomy_version' => 'v1', 'source_schema' => 's', 'generated_at' => 't',
            'in_scope_classes' => ['a'], 'out_of_scope_classes' => [], 'processing_states' => [],
        ]));

        $cfg = $this->config(['registry_taxonomy_path' => $path]);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->assertSame('v1', $svc->get()['taxonomy_version']);
    }

    public function test_windows_drive_letter_path_handling_when_on_windows(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows drive-letter path test runs only on Windows.');
        }

        $path = $this->write('drive-taxonomy.json', json_encode([
            'taxonomy_version' => 'v1', 'source_schema' => 's', 'generated_at' => 't',
            'in_scope_classes' => ['a'], 'out_of_scope_classes' => [], 'processing_states' => [],
        ]));
        $this->assertMatchesRegularExpression('/^[A-Za-z]:[\\\\\\/]/', $path);

        $cfg = $this->config(['registry_taxonomy_path' => $path]);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->assertSame('v1', $svc->get()['taxonomy_version']);
    }

    public function test_windows_unc_path_normalization_semantics_are_reported(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Windows UNC path normalization semantics test runs only on Windows.');
        }

        $resolver = new RegistryPathResolver($this->config());
        $method = new \ReflectionMethod(RegistryPathResolver::class, 'normalizeAbsolutePath');
        $method->setAccessible(true);

        // Observed current behavior: normalizeAbsolutePath collapses ".." within UNC share segments
        // and may return a path outside the intended share. This is an unresolved semantics question;
        // do not treat this as verified safe behavior. The resolver still fails closed later if the
        // resulting file is unavailable/unreadable/not a regular file.
        $normalized = $method->invoke($resolver, '\\\\server\\share\\..\\escaped.json');
        $this->assertIsString($normalized);
        $this->assertStringStartsWith('\\\\server\\', $normalized);
    }

    private function trySymlink(string $target, string $link): bool
    {
        if (file_exists($link) || is_link($link)) {
            @unlink($link);
            @rmdir($link);
        }
        $ok = @symlink($target, $link);
        clearstatcache(true, $link);
        return $ok && is_link($link);
    }

    private function requireSymlink(string $target, string $link, string $purpose): void
    {
        if ($this->trySymlink($target, $link)) {
            return;
        }

        $reason = 'symlink creation not supported or denied';
        if (DIRECTORY_SEPARATOR === '\\') {
            $reason .= ' (Windows may require Developer Mode or elevated privileges)';
        }
        $this->markTestSkipped('NOT VERIFIED: unable to create symlink required for '.$purpose.': '.$reason.'.');
    }

    public function test_cache_invalidates_on_file_change(): void
    {
        $path = $this->tmp.'/taxonomy.json';
        file_put_contents($path, json_encode([
            'taxonomy_version' => 'v1', 'source_schema' => 's', 'generated_at' => 't',
            'in_scope_classes' => ['a'], 'out_of_scope_classes' => [], 'processing_states' => [],
        ]));
        $cfg = $this->config(['registry_taxonomy_path' => $path]);
        $svc = new ApprovedTaxonomyRegistry(new RegistryPathResolver($cfg), new RegistryFileCache(30));
        $this->assertSame('v1', $svc->get()['taxonomy_version']);
        file_put_contents($path, json_encode([
            'taxonomy_version' => 'v2', 'source_schema' => 's', 'generated_at' => 't',
            'in_scope_classes' => ['a'], 'out_of_scope_classes' => [], 'processing_states' => [],
        ]));
        clearstatcache(true, $path);
        touch($path, time() + 5);
        $this->assertSame('v2', $svc->get()['taxonomy_version']);
    }
}
