<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AnnotationRegistryApiTest extends TestCase
{
    private string $tmp;
    /** @var array<string,string> */
    private array $hashes = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);

        $this->tmp = storage_path('framework/testing/registry-api-'.uniqid());
        @mkdir($this->tmp, 0777, true);
        $this->configureRegistries();
        $this->captureSourceHashes();
    }

    protected function tearDown(): void
    {
        $this->assertSourceRegistriesUnchanged();
        $this->deleteDir($this->tmp);
        parent::tearDown();
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($p) ? $this->deleteDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);
        return $path;
    }

    private function defaultFiles(): array
    {
        return [
            'taxonomy' => $this->write('taxonomy.json', json_encode([
                'taxonomy_version' => 'pilot-annotation-v1',
                'source_schema' => 'pilot_annotation_schema.json',
                'generated_at' => '2026-01-01T00:00:00Z',
                'in_scope_classes' => ['curry_leaves'],
                'out_of_scope_classes' => ['salt'],
                'processing_states' => ['whole'],
            ])),
            'decisions' => $this->write('decisions.json', json_encode([
                'version' => 'step8a-label-mapping-decisions-v1',
                'generated_at' => '2026-01-01T00:00:00Z',
                'scope' => 'test',
                'policy' => ['preserve_master_csv_labels' => true],
                'decisions' => [
                    ['observed_label' => 'Curry Leaf', 'canonical_class_id' => 'curry_leaves', 'decision_type' => 'approved_alias', 'canonical_mapping_pending' => false, 'candidate_canonical_class_ids' => []],
                ],
            ])),
            'image_ids' => $this->write('image_ids.json', json_encode([
                'registry_version' => 'image-id-registry-v1',
                'next_sequence' => 2,
                'path_to_id' => ['Black Pepper/a.jpg' => 'IMG-000001'],
            ])),
            'inventory' => $this->write('inventory.csv', implode("\n", [
                'image_id,batch_id,original_folder,original_filename,relative_path,file_extension,file_size_bytes,image_width,image_height,image_mode,image_format,sha256,folder_class_proposal,mapping_status,scan_status,scan_timestamp,first_seen_timestamp,error_message,duplicate_group_id,duplicate_of_image_id,is_exact_duplicate',
                'IMG-000001,B1,Black Pepper,a.jpg,Black Pepper/a.jpg,.jpg,1,10,10,RGB,JPEG,abc,,proposed_pending_review,success_cached,t1,t2,,,,False',
                '',
            ])),
        ];
    }

    private function configureRegistries(array $overrides = []): array
    {
        $files = array_merge($this->defaultFiles(), $overrides);
        config([
            'annotation.registry_taxonomy_path' => $files['taxonomy'],
            'annotation.registry_decisions_path' => $files['decisions'],
            'annotation.registry_image_id_registry_path' => $files['image_ids'],
            'annotation.registry_inventory_path' => $files['inventory'],
            'annotation.registry_inventory_default_per_page' => 25,
            'annotation.registry_inventory_max_per_page' => 100,
        ]);
        return $files;
    }

    private function createUser(?string $role = null): User
    {
        return User::query()->forceCreate([
            'email' => uniqid('u').'@example.test',
            'password' => bcrypt('Password123'),
            'name' => 'User',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/annotation-registries/taxonomy')->assertStatus(401);
    }

    public function test_null_and_unknown_role_denied(): void
    {
        $null = $this->createUser(null);
        $unknown = $this->createUser('owner');
        $this->actingAs($null)->getJson('/api/v1/annotation-registries/taxonomy')->assertStatus(403);
        $this->actingAs($unknown)->getJson('/api/v1/annotation-registries/summary')->assertStatus(403);
    }

    public function test_annotator_reviewer_admin_can_read(): void
    {
        foreach (['annotator','reviewer','research_admin'] as $role) {
            $user = $this->createUser($role);
            $this->actingAs($user)->getJson('/api/v1/annotation-registries/taxonomy')->assertOk()->assertJsonPath('data.taxonomy_version', 'pilot-annotation-v1');
            $this->actingAs($user)->getJson('/api/v1/annotation-registries/label-mapping-decisions')->assertOk()->assertJsonPath('data.version', 'step8a-label-mapping-decisions-v1');
            $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-inventory')->assertOk()->assertJsonPath('meta.pagination.total', 1);
            $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-id-lookup?image_id=IMG-000001')->assertOk()->assertJsonPath('data.relative_path', 'Black Pepper/a.jpg');
            $this->actingAs($user)->getJson('/api/v1/annotation-registries/summary')->assertOk()->assertJsonPath('data.status', 'ok');
        }
    }

    public function test_invalid_lookup_returns_422(): void
    {
        $user = $this->createUser('annotator');
        $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-id-lookup')->assertStatus(422);
        $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-id-lookup?image_id=IMG-000001&relative_path=Black Pepper/a.jpg')->assertStatus(422);
        $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-id-lookup?relative_path=../x.jpg')->assertStatus(422);
    }

    public function test_missing_registry_returns_controlled_503(): void
    {
        $this->configureRegistries(['taxonomy' => $this->tmp.'/missing.json']);
        $user = $this->createUser('research_admin');
        $res = $this->actingAs($user)->getJson('/api/v1/annotation-registries/taxonomy');
        $res->assertStatus(503)->assertJsonPath('error.code', 'REGISTRY_UNAVAILABLE');
        $this->assertStringNotContainsString($this->tmp, $res->getContent());
        $this->assertStringNotContainsString('C:\\', $res->getContent());
    }

    public function test_no_write_endpoints_introduced(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/annotation-registries/'));

        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $methods = array_values(array_diff($route->methods(), ['HEAD']));
            $this->assertSame(['GET'], $methods, 'Unexpected methods for '.$route->uri());
        }
    }

    public function test_image_id_lookup_valid_unknown_returns_404(): void
    {
        $user = $this->createUser('annotator');
        $res = $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-id-lookup?image_id=IMG-999999');

        $res->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertStringNotContainsString($this->tmp, $res->getContent());
        $this->assertStringNotContainsString('\\', $res->getContent());
    }

    public function test_image_id_lookup_inconsistent_registries_returns_controlled_503(): void
    {
        $this->configureRegistries([
            'image_ids' => $this->write('image_ids_mismatch.json', json_encode([
                'registry_version' => 'image-id-registry-v1',
                'next_sequence' => 3,
                'path_to_id' => [
                    'Black Pepper/a.jpg' => 'IMG-000001',
                    'Black Pepper/b.jpg' => 'IMG-000002',
                ],
            ])),
        ]);

        $user = $this->createUser('research_admin');
        $res = $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-id-lookup?image_id=IMG-000001');

        $res->assertStatus(503)
            ->assertJsonPath('error.code', 'REGISTRY_UNAVAILABLE');

        $this->assertStringNotContainsString($this->tmp, $res->getContent());
        $this->assertStringNotContainsString('\\', $res->getContent());
    }

    public function test_inventory_relative_path_traversal_returns_422(): void
    {
        $user = $this->createUser('annotator');
        $res = $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-inventory?relative_path=../x.jpg');

        $res->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message']]);
    }

    public function test_inventory_per_page_exceeding_max_returns_422(): void
    {
        $user = $this->createUser('annotator');
        $res = $this->actingAs($user)->getJson('/api/v1/annotation-registries/image-inventory?per_page=101');

        $res->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message']]);
    }

    public function test_summary_missing_registry_returns_controlled_503(): void
    {
        $this->configureRegistries(['taxonomy' => $this->tmp.'/missing-taxonomy.json']);
        $user = $this->createUser('research_admin');
        $res = $this->actingAs($user)->getJson('/api/v1/annotation-registries/summary');

        $res->assertStatus(503)
            ->assertJsonPath('error.code', 'REGISTRY_UNAVAILABLE')
            ->assertJsonStructure(['error' => ['code', 'message']]);

        $body = $res->json();
        $summary = $body['error']['details']['summary'] ?? $body['data'] ?? [];
        $this->assertSame('degraded', $summary['status'] ?? null);
        $this->assertArrayHasKey('registries', $summary);
        $this->assertArrayHasKey('errors', $summary);

        $this->assertStringNotContainsString($this->tmp, $res->getContent());
        $this->assertStringNotContainsString('\\', $res->getContent());
    }

    public function test_error_responses_do_not_expose_absolute_filesystem_paths(): void
    {
        $user = $this->createUser('annotator');

        $cases = [
            ['taxonomy', $this->tmp.'/missing-taxonomy.json', '/api/v1/annotation-registries/taxonomy'],
            ['decisions', $this->tmp.'/missing-decisions.json', '/api/v1/annotation-registries/label-mapping-decisions'],
            ['inventory', $this->tmp.'/missing-inventory.csv', '/api/v1/annotation-registries/image-inventory'],
            ['image_ids', $this->tmp.'/missing-image-ids.json', '/api/v1/annotation-registries/image-id-lookup?image_id=IMG-000001'],
            ['taxonomy', $this->tmp.'/missing-taxonomy.json', '/api/v1/annotation-registries/summary'],
        ];

        foreach ($cases as [$key, $missingPath, $url]) {
            $this->configureRegistries([$key => $missingPath]);
            $res = $this->actingAs($user)->getJson($url);

            $res->assertStatus(503)
                ->assertJsonPath('error.code', 'REGISTRY_UNAVAILABLE');

            $content = $res->getContent();
            $this->assertStringNotContainsString($this->tmp, $content);
            $this->assertStringNotContainsString('\\', $content);
            $this->assertStringNotContainsString('/Users/', $content);
        }
    }

    private function captureSourceHashes(): void
    {
        $paths = [
            base_path('../research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json'),
            base_path('../research/phase-13.3/annotation_pipeline/config/image_id_registry.json'),
            base_path('../research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json'),
            base_path('../research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv'),
        ];
        foreach ($paths as $path) {
            $this->hashes[$path] = is_file($path) ? hash_file('sha256', $path) : 'missing';
        }
    }

    private function assertSourceRegistriesUnchanged(): void
    {
        foreach ($this->hashes as $path => $hash) {
            $actual = is_file($path) ? hash_file('sha256', $path) : 'missing';
            $this->assertSame($hash, $actual, 'Source registry changed: '.$path);
        }
    }
}
