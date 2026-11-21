<?php

namespace Tests\Feature\Api\V1;

use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\SavedRecipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SavedRecipeApiTest extends TestCase
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

    public function test_authenticated_list_returns_success_envelope_links_and_nested_recipe(): void
    {
        $user = $this->createUser('user@example.com');
        $now = now();

        DB::table('saved_recipes')->insert([
            [
                'user_id' => $user->id,
                'recipe_id' => $this->curryRecipeId,
                'created_at' => $now->copy()->subDay(),
                'updated_at' => $now->copy()->subDay(),
            ],
            [
                'user_id' => $user->id,
                'recipe_id' => $this->soupRecipeId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/saved-recipes')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('meta.api_version', 'v1')
            ->assertJsonPath('meta.pagination.current_page', 1)
            ->assertJsonPath('meta.pagination.per_page', 15)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.last_page', 1)
            ->assertJsonPath('meta.pagination.from', 1)
            ->assertJsonPath('meta.pagination.to', 2)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'recipe_id',
                        'recipe' => ['id', 'name'],
                        'created_at',
                        'updated_at',
                    ],
                ],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['api_version', 'pagination'],
            ]);

        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertSame($this->soupRecipeId, $data[0]['recipe']['id']);
        $this->assertSame('Alpha Soup', $data[0]['recipe']['name']);
        $this->assertSame($this->curryRecipeId, $data[1]['recipe']['id']);
        $this->assertSame('Pepper Curry', $data[1]['recipe']['name']);
    }

    public function test_store_saves_a_recipe_and_is_idempotent(): void
    {
        $user = $this->createUser('user@example.com');
        $this->actingAs($user);

        $response = $this->postJson('/api/v1/saved-recipes', [
            'recipe_id' => $this->curryRecipeId,
        ])->assertStatus(201);

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.recipe_id', $this->curryRecipeId)
            ->assertJsonPath('data.recipe.id', $this->curryRecipeId)
            ->assertJsonPath('data.recipe.name', 'Pepper Curry')
            ->assertJsonPath('meta.api_version', 'v1');

        $this->assertSame(1, SavedRecipe::query()->count());

        $duplicate = $this->postJson('/api/v1/saved-recipes', [
            'recipe_id' => $this->curryRecipeId,
        ]);

        $this->assertContains($duplicate->status(), [200, 201]);
        $duplicate->assertJsonPath('success', true)
            ->assertJsonPath('data.recipe_id', $this->curryRecipeId);

        $this->assertSame(1, SavedRecipe::query()->count());
        $this->assertDatabaseHas('saved_recipes', [
            'user_id' => $user->id,
            'recipe_id' => $this->curryRecipeId,
        ]);
    }

    public function test_store_ignores_a_frontend_supplied_user_id(): void
    {
        $user = $this->createUser('user@example.com');
        $otherUser = $this->createUser('other@example.com');
        $this->actingAs($user);

        $this->postJson('/api/v1/saved-recipes', [
            'recipe_id' => $this->soupRecipeId,
            'user_id' => $otherUser->id,
        ])->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recipe_id', $this->soupRecipeId);

        $this->assertDatabaseHas('saved_recipes', [
            'user_id' => $user->id,
            'recipe_id' => $this->soupRecipeId,
        ]);
        $this->assertSame(0, SavedRecipe::query()->where('user_id', $otherUser->id)->count());
    }

    public function test_destroy_removes_only_the_authenticated_users_saved_recipe(): void
    {
        $user = $this->createUser('user@example.com');
        $otherUser = $this->createUser('other@example.com');
        $now = now();

        DB::table('saved_recipes')->insert([
            [
                'user_id' => $user->id,
                'recipe_id' => $this->curryRecipeId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => $otherUser->id,
                'recipe_id' => $this->curryRecipeId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $response = $this->actingAs($user)
            ->deleteJson('/api/v1/saved-recipes/'.$this->curryRecipeId)
            ->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('meta.api_version', 'v1')
            ->assertJsonPath('meta.message', 'Recipe removed from saved recipes.');
        $this->assertNull($response->json('data'));

        $this->assertSame(0, SavedRecipe::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, SavedRecipe::query()->where('user_id', $otherUser->id)->count());

        $this->deleteJson('/api/v1/saved-recipes/'.$this->curryRecipeId)
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', 'Saved recipe not found.');
    }

    public function test_another_users_saved_recipes_are_not_listed_or_deletable(): void
    {
        $user = $this->createUser('user@example.com');
        $otherUser = $this->createUser('other@example.com');
        $now = now();

        DB::table('saved_recipes')->insert([
            'user_id' => $otherUser->id,
            'recipe_id' => $this->pilafRecipeId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/v1/saved-recipes')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('meta.pagination.total', 0);
        $this->assertSame([], $response->json('data'));

        $this->deleteJson('/api/v1/saved-recipes/'.$this->pilafRecipeId)
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertSame(1, SavedRecipe::query()->where('user_id', $otherUser->id)->count());
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $requests = [
            fn () => $this->getJson('/api/v1/saved-recipes'),
            fn () => $this->postJson('/api/v1/saved-recipes', ['recipe_id' => $this->curryRecipeId]),
            fn () => $this->deleteJson('/api/v1/saved-recipes/'.$this->curryRecipeId),
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

        $response = $this->postJson('/api/v1/saved-recipes', [
            'recipe_id' => 999999,
        ])->assertUnprocessable();

        $response->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('meta.api_version', 'v1');
        $this->assertArrayHasKey('recipe_id', $response->json('error.details'));

        $missing = $this->postJson('/api/v1/saved-recipes', [])->assertUnprocessable();

        $missing->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertArrayHasKey('recipe_id', $missing->json('error.details'));

        $this->assertSame(0, SavedRecipe::query()->count());
    }

    public function test_deleting_a_recipe_that_was_never_saved_returns_404(): void
    {
        $user = $this->createUser('user@example.com');
        $this->actingAs($user);

        $this->deleteJson('/api/v1/saved-recipes/'.$this->soupRecipeId)
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->deleteJson('/api/v1/saved-recipes/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_database_constraints_are_enforced(): void
    {
        $user = $this->createUser('user@example.com');
        $now = now();

        DB::table('saved_recipes')->insert([
            'user_id' => $user->id,
            'recipe_id' => $this->curryRecipeId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('saved_recipes')->insert([
                'user_id' => $user->id,
                'recipe_id' => $this->curryRecipeId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('Duplicate (user_id, recipe_id) saved recipes should be rejected.');
        } catch (QueryException) {
        }

        $this->assertSame(1, DB::table('saved_recipes')->count());

        foreach ([
            ['user_id' => 999999, 'recipe_id' => $this->curryRecipeId],
            ['user_id' => $user->id, 'recipe_id' => 999999],
        ] as $invalidRow) {
            try {
                DB::table('saved_recipes')->insert(array_merge($invalidRow, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
                $this->fail('Saved recipes with invalid foreign keys should be rejected.');
            } catch (QueryException) {
            }
        }

        $this->assertSame(1, DB::table('saved_recipes')->count());
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
