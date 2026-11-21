<?php

namespace Tests\Feature\Api\V1;

use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\RecipeView;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecipeHistoryApiTest extends TestCase
{
    private int $curryRecipeId;

    private int $soupRecipeId;

    private int $pilafRecipeId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'session.driver' => 'array',
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])
            ->assertExitCode(0);

        $this->withHeader('Origin', 'http://localhost:5173');
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_authenticated_list_returns_envelope_links_nested_recipe_and_descending_order(): void
    {
        $user = $this->createUser('user@example.com');
        $now = now();
        $oldest = $now->copy()->subMinutes(30)->startOfSecond();
        $middle = $now->copy()->subMinutes(20)->startOfSecond();
        $newest = $now->copy()->subMinutes(10)->startOfSecond();

        DB::table('recipe_views')->insert([
            [
                'user_id' => $user->id,
                'recipe_id' => $this->curryRecipeId,
                'viewed_at' => $oldest,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $user->id,
                'recipe_id' => $this->soupRecipeId,
                'viewed_at' => $middle,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $user->id,
                'recipe_id' => $this->pilafRecipeId,
                'viewed_at' => $newest,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/recipe-history')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('meta.api_version', 'v1')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 15)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.last_page', 1)
            ->assertJsonPath('meta.pagination.from', 1)
            ->assertJsonPath('meta.pagination.to', 3)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'recipe_id',
                        'recipe' => ['id', 'name'],
                        'viewed_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['api_version', 'pagination'],
            ]);

        $data = $response->json('data');

        $this->assertCount(3, $data);
        $this->assertSame([$this->pilafRecipeId, $this->soupRecipeId, $this->curryRecipeId], array_column($data, 'recipe_id'));
        $this->assertSame(['Rice Pilaf', 'Alpha Soup', 'Pepper Curry'], array_column(array_column($data, 'recipe'), 'name'));
        $this->assertTrue(Carbon::parse($data[0]['viewed_at'])->equalTo($newest));
        $this->assertTrue(Carbon::parse($data[1]['viewed_at'])->equalTo($middle));
        $this->assertTrue(Carbon::parse($data[2]['viewed_at'])->equalTo($oldest));
    }

    public function test_store_records_a_view_with_server_timestamp_and_allows_repeated_views(): void
    {
        $user = $this->createUser('user@example.com');
        $this->actingAs($user);

        $before = now()->subSecond();

        $response = $this->postJson('/api/v1/recipe-history', [
            'recipe_id' => $this->curryRecipeId,
        ])->assertStatus(201);

        $after = now()->addSecond();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.recipe_id', $this->curryRecipeId)
            ->assertJsonPath('data.recipe.id', $this->curryRecipeId)
            ->assertJsonPath('data.recipe.name', 'Pepper Curry')
            ->assertJsonPath('meta.api_version', 'v1');

        $viewedAt = $response->json('data.viewed_at');

        $this->assertIsString($viewedAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $viewedAt);
        $this->assertTrue(Carbon::parse($viewedAt)->between($before, $after));

        $this->postJson('/api/v1/recipe-history', [
            'recipe_id' => $this->curryRecipeId,
        ])->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recipe_id', $this->curryRecipeId);

        $this->assertSame(2, RecipeView::query()->count());
        $this->assertSame(2, RecipeView::query()->where('user_id', $user->id)->count());
    }

    public function test_destroy_clears_only_the_authenticated_users_history_and_returns_deleted_count(): void
    {
        $user = $this->createUser('user@example.com');
        $otherUser = $this->createUser('other@example.com');
        $now = now();

        DB::table('recipe_views')->insert([
            [
                'user_id' => $user->id,
                'recipe_id' => $this->curryRecipeId,
                'viewed_at' => $now->copy()->subMinutes(5),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $user->id,
                'recipe_id' => $this->soupRecipeId,
                'viewed_at' => $now->copy()->subMinute(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $otherUser->id,
                'recipe_id' => $this->pilafRecipeId,
                'viewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->actingAs($user)->deleteJson('/api/v1/recipe-history')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted_count', 2)
            ->assertJsonPath('meta.api_version', 'v1')
            ->assertJsonPath('meta.message', 'Recipe history cleared.');

        $this->assertSame(0, RecipeView::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, RecipeView::query()->where('user_id', $otherUser->id)->count());
    }

    public function test_another_users_history_is_not_listed_or_cleared(): void
    {
        $user = $this->createUser('user@example.com');
        $otherUser = $this->createUser('other@example.com');
        $now = now();

        DB::table('recipe_views')->insert([
            'user_id' => $otherUser->id,
            'recipe_id' => $this->pilafRecipeId,
            'viewed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/v1/recipe-history')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 0);
        $this->assertSame([], $response->json('data'));

        $this->deleteJson('/api/v1/recipe-history')->assertOk()
            ->assertJsonPath('data.deleted_count', 0);

        $this->assertSame(1, RecipeView::query()->where('user_id', $otherUser->id)->count());
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $requests = [
            fn () => $this->getJson('/api/v1/recipe-history'),
            fn () => $this->postJson('/api/v1/recipe-history', ['recipe_id' => $this->curryRecipeId]),
            fn () => $this->deleteJson('/api/v1/recipe-history'),
        ];

        foreach ($requests as $request) {
            $request()
                ->assertStatus(401)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'UNAUTHENTICATED')
                ->assertJsonPath('meta.api_version', 'v1');
        }
    }

    public function test_invalid_recipe_id_returns_validation_error(): void
    {
        $user = $this->createUser('user@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v1/recipe-history', [
            'recipe_id' => 999999,
        ])->assertUnprocessable();

        $response->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('meta.api_version', 'v1');
        $this->assertArrayHasKey('recipe_id', $response->json('error.details'));

        $missing = $this->postJson('/api/v1/recipe-history', [])->assertUnprocessable();

        $missing->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('recipe_id', $missing->json('error.details'));

        $this->assertSame(0, RecipeView::query()->count());
    }

    public function test_pagination_returns_correct_metadata_and_items(): void
    {
        $user = $this->createUser('user@example.com');
        $now = now();
        $viewIds = [];

        foreach ([40, 30, 20, 10] as $minutesAgo) {
            $viewIds[] = DB::table('recipe_views')->insertGetId([
                'user_id' => $user->id,
                'recipe_id' => $this->curryRecipeId,
                'viewed_at' => $now->copy()->subMinutes($minutesAgo),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $expectedOrder = array_reverse($viewIds);
        $this->actingAs($user);

        $firstPage = $this->getJson('/api/v1/recipe-history?per_page=2')->assertOk()->json();

        $this->assertCount(2, $firstPage['data']);
        $this->assertSame(array_slice($expectedOrder, 0, 2), array_column($firstPage['data'], 'id'));
        $this->assertSame(1, $firstPage['meta']['pagination']['current_page']);
        $this->assertSame(2, $firstPage['meta']['pagination']['per_page']);
        $this->assertSame(4, $firstPage['meta']['pagination']['total']);
        $this->assertSame(2, $firstPage['meta']['pagination']['last_page']);
        $this->assertSame(1, $firstPage['meta']['pagination']['from']);
        $this->assertSame(2, $firstPage['meta']['pagination']['to']);
        $this->assertNull($firstPage['links']['prev']);
        $this->assertNotNull($firstPage['links']['next']);

        $secondPage = $this->getJson('/api/v1/recipe-history?per_page=2&page=2')->assertOk()->json();

        $this->assertCount(2, $secondPage['data']);
        $this->assertSame(array_slice($expectedOrder, 2, 2), array_column($secondPage['data'], 'id'));
        $this->assertSame(2, $secondPage['meta']['pagination']['current_page']);
        $this->assertSame(2, $secondPage['meta']['pagination']['per_page']);
        $this->assertSame(4, $secondPage['meta']['pagination']['total']);
        $this->assertSame(3, $secondPage['meta']['pagination']['from']);
        $this->assertSame(4, $secondPage['meta']['pagination']['to']);
        $this->assertNotNull($secondPage['links']['prev']);
        $this->assertNull($secondPage['links']['next']);
    }

    public function test_database_constraints_are_enforced(): void
    {
        $user = $this->createUser('user@example.com');
        $now = now();

        foreach ([
            ['user_id' => 999999, 'recipe_id' => $this->curryRecipeId],
            ['user_id' => $user->id, 'recipe_id' => 999999],
        ] as $invalidRow) {
            try {
                DB::table('recipe_views')->insert(array_merge($invalidRow, [
                    'viewed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
                $this->fail('Recipe views with invalid foreign keys should be rejected.');
            } catch (QueryException) {
            }
        }

        $this->assertSame(0, DB::table('recipe_views')->count());

        DB::table('recipe_views')->insert([
            'user_id' => $user->id,
            'recipe_id' => $this->curryRecipeId,
            'viewed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('recipe_views')->insert([
            'user_id' => $user->id,
            'recipe_id' => $this->curryRecipeId,
            'viewed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(2, DB::table('recipe_views')->count());
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('password123'),
        ]);
    }

    private function createFixtures(): void
    {
        $category = RecipeCategory::create([
            'name' => 'Curry',
            'description' => 'Warm curries',
        ]);

        $curry = Recipe::create([
            'recipe_category_id' => $category->id,
            'recipe_code' => 'REC-001',
            'name' => 'Pepper Curry',
            'main_ingredient' => 'Pepper',
            'recipe_type' => 'Curry',
            'cooking_variation' => 'Stovetop',
            'region' => 'Sri Lankan',
            'description' => 'A warming pepper curry.',
            'preparation_time_minutes' => 10,
            'cooking_time_minutes' => 20,
            'total_time_minutes' => 30,
            'source_yield' => '4 people',
            'yield_people' => 4.00,
            'time_is_estimated' => false,
            'yield_is_estimated' => false,
            'time_arithmetic_status' => 'MATCH',
        ]);
        $soup = Recipe::create([
            'recipe_category_id' => $category->id,
            'recipe_code' => 'REC-002',
            'name' => 'Alpha Soup',
            'main_ingredient' => 'Rice',
            'recipe_type' => 'Soup',
            'cooking_variation' => 'Stovetop',
            'region' => 'Southern',
            'description' => 'A light rice soup.',
            'preparation_time_minutes' => 5,
            'cooking_time_minutes' => 15,
            'total_time_minutes' => 20,
            'source_yield' => '2 people',
            'yield_people' => 2.00,
            'time_is_estimated' => true,
            'yield_is_estimated' => true,
            'time_arithmetic_status' => 'MATCH',
        ]);
        $pilaf = Recipe::create([
            'recipe_category_id' => $category->id,
            'recipe_code' => 'REC-003',
            'name' => 'Rice Pilaf',
            'main_ingredient' => 'Rice',
            'recipe_type' => 'Pilaf',
            'cooking_variation' => 'Stovetop',
            'region' => 'Northern',
            'description' => 'A fragrant rice pilaf.',
            'preparation_time_minutes' => 8,
            'cooking_time_minutes' => 18,
            'total_time_minutes' => 26,
            'source_yield' => '3 people',
            'yield_people' => 3.00,
            'time_is_estimated' => false,
            'yield_is_estimated' => false,
            'time_arithmetic_status' => 'MATCH',
        ]);

        $this->curryRecipeId = $curry->id;
        $this->soupRecipeId = $soup->id;
        $this->pilafRecipeId = $pilaf->id;
    }
}
