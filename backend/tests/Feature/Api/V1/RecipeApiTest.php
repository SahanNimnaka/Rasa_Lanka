<?php

namespace Tests\Feature\Api\V1;

use App\Models\DataQualityIssue;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\RecipeIngredient;
use App\Models\RecipeInstruction;
use App\Models\RecipeSource;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeApiTest extends TestCase
{
    private int $curryCategoryId;

    private int $soupCategoryId;

    private int $spiceCategoryId;

    private int $pepperId;

    private int $riceId;

    private int $curryRecipeId;

    private int $soupRecipeId;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])
            ->assertExitCode(0);

        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_all_six_endpoints_are_public_and_return_success_envelopes(): void
    {
        $uris = [
            '/api/v1/recipe-categories',
            '/api/v1/ingredient-categories',
            '/api/v1/ingredients',
            '/api/v1/ingredients/'.$this->pepperId,
            '/api/v1/recipes',
            '/api/v1/recipes/'.$this->curryRecipeId,
        ];

        foreach ($uris as $uri) {
            $response = $this->getJson($uri)->assertOk();

            $response->assertJsonPath('success', true)
                ->assertJsonPath('meta.api_version', 'v1')
                ->assertJsonStructure(['success', 'data', 'meta' => ['api_version']]);
        }
    }

    public function test_category_endpoints_support_pagination_links_and_metadata(): void
    {
        $recipeCategories = $this->getJson('/api/v1/recipe-categories?per_page=1')->assertOk()->json();
        $ingredientCategories = $this->getJson('/api/v1/ingredient-categories?per_page=1')->assertOk()->json();

        foreach ([$recipeCategories, $ingredientCategories] as $payload) {
            $this->assertArrayHasKey('first', $payload['links']);
            $this->assertArrayHasKey('last', $payload['links']);
            $this->assertArrayHasKey('prev', $payload['links']);
            $this->assertArrayHasKey('next', $payload['links']);
            $this->assertSame(1, $payload['meta']['pagination']['current_page']);
            $this->assertSame(1, $payload['meta']['pagination']['per_page']);
            $this->assertSame(2, $payload['meta']['pagination']['last_page']);
        }
    }

    public function test_recipe_category_search_matches_name_and_description(): void
    {
        $byName = $this->getJson('/api/v1/recipe-categories?search=curry')->assertOk()->json();
        $byDescription = $this->getJson('/api/v1/recipe-categories?search=warm')->assertOk()->json();

        $this->assertCount(1, $byName['data']);
        $this->assertSame('Curry', $byName['data'][0]['name']);
        $this->assertCount(1, $byDescription['data']);
        $this->assertSame('Curry', $byDescription['data'][0]['name']);
    }

    public function test_ingredient_category_search_matches_name_and_description(): void
    {
        $byName = $this->getJson('/api/v1/ingredient-categories?search=spice')->assertOk()->json();
        $byDescription = $this->getJson('/api/v1/ingredient-categories?search=aromatic')->assertOk()->json();

        $this->assertCount(1, $byName['data']);
        $this->assertSame('Spice', $byName['data'][0]['name']);
        $this->assertCount(1, $byDescription['data']);
        $this->assertSame('Spice', $byDescription['data'][0]['name']);
    }

    public function test_ingredient_search_matches_name_and_key(): void
    {
        $byName = $this->getJson('/api/v1/ingredients?search=black')->assertOk()->json();
        $byKey = $this->getJson('/api/v1/ingredients?search=black_pepper')->assertOk()->json();

        $this->assertCount(1, $byName['data']);
        $this->assertSame('Black Pepper', $byName['data'][0]['name']);
        $this->assertCount(1, $byKey['data']);
        $this->assertSame($this->pepperId, $byKey['data'][0]['id']);
    }

    public function test_ingredient_category_filter_uses_the_relationship(): void
    {
        $response = $this->getJson('/api/v1/ingredients?ingredient_category_id='.$this->spiceCategoryId)
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['data']);
        $this->assertSame($this->pepperId, $response['data'][0]['id']);
    }

    public function test_ingredient_list_can_include_provenanced_categories_and_recipe_count(): void
    {
        $response = $this->getJson('/api/v1/ingredients?include=categories,recipes_count&search=black')
            ->assertOk()
            ->json();

        $ingredient = $response['data'][0];

        $this->assertSame($this->pepperId, $ingredient['id']);
        $this->assertCount(2, $ingredient['categories']);
        $this->assertSame('SRC-1-ING-1', $ingredient['categories'][0]['provenance']['source_record_identifier']);
        $this->assertSame('Spice', $ingredient['categories'][0]['provenance']['source_category_text']);
        $this->assertSame(1, $ingredient['recipes_count']);
        $this->assertArrayNotHasKey('recipe_ingredients', $ingredient);
    }

    public function test_recipe_category_filter(): void
    {
        $response = $this->getJson('/api/v1/recipes?recipe_category_id='.$this->curryCategoryId)
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['data']);
        $this->assertSame($this->curryRecipeId, $response['data'][0]['id']);
    }

    public function test_recipe_ingredient_filter(): void
    {
        $response = $this->getJson('/api/v1/recipes?ingredient_id='.$this->riceId)
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['data']);
        $this->assertSame($this->soupRecipeId, $response['data'][0]['id']);
    }

    public function test_recipe_type_and_region_filters(): void
    {
        $byType = $this->getJson('/api/v1/recipes?recipe_type=Curry')->assertOk()->json();
        $byRegion = $this->getJson('/api/v1/recipes?region=Southern')->assertOk()->json();

        $this->assertCount(1, $byType['data']);
        $this->assertSame($this->curryRecipeId, $byType['data'][0]['id']);
        $this->assertCount(1, $byRegion['data']);
        $this->assertSame($this->soupRecipeId, $byRegion['data'][0]['id']);
    }

    public function test_recipe_sorting_and_invalid_sort_rejection(): void
    {
        $sorted = $this->getJson('/api/v1/recipes?sort=name')->assertOk()->json();
        $invalid = $this->getJson('/api/v1/recipes?sort=secret_column')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->json();

        $this->assertSame('Alpha Soup', $sorted['data'][0]['name']);
        $this->assertSame('Pepper Curry', $sorted['data'][1]['name']);
        $this->assertArrayHasKey('sort', $invalid['error']['details']);
    }

    public function test_recipe_list_returns_counts_without_nested_ingredients_or_instructions(): void
    {
        $response = $this->getJson('/api/v1/recipes')->assertOk()->json();
        $recipe = collect($response['data'])->firstWhere('id', $this->curryRecipeId);

        $this->assertSame(2, $recipe['recipe_ingredients_count']);
        $this->assertSame(3, $recipe['recipe_instructions_count']);
        $this->assertSame(1, $recipe['recipe_sources_count']);
        $this->assertSame(1, $recipe['data_quality_issues_count']);
        $this->assertArrayNotHasKey('recipe_ingredients', $recipe);
        $this->assertArrayNotHasKey('instructions', $recipe);
    }

    public function test_recipe_detail_returns_nested_relationships(): void
    {
        $response = $this->getJson('/api/v1/recipes/'.$this->curryRecipeId)
            ->assertOk()
            ->json();

        $recipe = $response['data'];

        $this->assertSame('Curry', $recipe['recipe_category']['name']);
        $this->assertCount(2, $recipe['recipe_ingredients']);
        $this->assertSame('Black Pepper', $recipe['recipe_ingredients'][0]['ingredient']['name']);
        $this->assertCount(1, $recipe['recipe_sources']);
        $this->assertSame('Source Book', $recipe['recipe_sources'][0]['source_name']);
        $this->assertCount(1, $recipe['data_quality_issues']);
    }

    public function test_ingredient_detail_returns_nested_relationships(): void
    {
        $response = $this->getJson('/api/v1/ingredients/'.$this->pepperId.'?include=categories,recipe_ingredients,data_quality_issues')
            ->assertOk()
            ->json();

        $ingredient = $response['data'];

        $this->assertCount(2, $ingredient['categories']);
        $this->assertCount(2, $ingredient['recipe_ingredients']);
        $this->assertSame($this->curryRecipeId, $ingredient['recipe_ingredients'][0]['recipe']['id']);
        $this->assertSame('REC-001', $ingredient['recipe_ingredients'][0]['recipe']['recipe_code']);
        $this->assertCount(1, $ingredient['data_quality_issues']);
        $this->assertSame('Review', $ingredient['data_quality_issues'][0]['severity']);
    }

    public function test_recipe_detail_preserves_step_zero_summary_and_step_order(): void
    {
        $recipe = $this->getJson('/api/v1/recipes/'.$this->curryRecipeId)
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $recipe['method_summary']['step_number']);
        $this->assertTrue($recipe['method_summary']['is_method_summary']);
        $this->assertSame('Summary paragraph', $recipe['method_summary']['cooking_method_paragraph']);
        $this->assertNull($recipe['method_summary']['instruction_text']);
        $this->assertSame([1, 2], array_column($recipe['instructions'], 'step_number'));
        $this->assertSame(['First step', 'Second step'], array_column($recipe['instructions'], 'instruction_text'));
        $this->assertFalse($recipe['instructions'][0]['is_method_summary']);
    }

    public function test_pending_expert_quantities_are_not_presented_as_approved(): void
    {
        $ingredientRow = $this->getJson('/api/v1/recipes/'.$this->curryRecipeId)
            ->assertOk()
            ->json('data.recipe_ingredients.0');

        $this->assertSame('Pending expert validation', $ingredientRow['approval_status']);
        $this->assertNull($ingredientRow['expert_approved_quantity']);
        $this->assertNull($ingredientRow['expert_approved_unit']);
        $this->assertSame('2 teaspoons', $ingredientRow['original_quantity_text']);
        $this->assertSame('teaspoon', $ingredientRow['original_unit']);
        $this->assertSame('teaspoon', $ingredientRow['per_person_unit']);
        $this->assertSame('Listed in source', $ingredientRow['original_requirement_label']);
        $this->assertTrue($ingredientRow['quantity_is_derived']);
        $this->assertFalse($ingredientRow['quantity_description_is_estimated']);
        $this->assertFalse($ingredientRow['quantity_depends_on_estimated_yield']);
    }

    public function test_five_decimal_precision_is_preserved_as_strings(): void
    {
        $ingredientRow = $this->getJson('/api/v1/recipes/'.$this->curryRecipeId)
            ->assertOk()
            ->json('data.recipe_ingredients.0');

        $this->assertIsString($ingredientRow['per_person_quantity_min']);
        $this->assertIsString($ingredientRow['per_person_quantity_max']);
        $this->assertSame('0.28571', $ingredientRow['per_person_quantity_min']);
        $this->assertSame('0.28571', $ingredientRow['per_person_quantity_max']);
    }

    public function test_ingredient_category_provenance_records_are_not_deduplicated(): void
    {
        $ingredient = $this->getJson('/api/v1/ingredients/'.$this->pepperId.'?include=categories')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $ingredient['categories']);
        $this->assertSame(
            ['SRC-1-ING-1', 'SRC-1-ING-2'],
            array_map(fn ($category) => $category['provenance']['source_record_identifier'], $ingredient['categories'])
        );
        $this->assertSame(
            ['Spice', 'Spice blend'],
            array_map(fn ($category) => $category['provenance']['source_category_text'], $ingredient['categories'])
        );
    }

    public function test_recipe_detail_includes_data_quality_issues(): void
    {
        $issue = $this->getJson('/api/v1/recipes/'.$this->curryRecipeId)
            ->assertOk()
            ->json('data.data_quality_issues.0');

        $this->assertSame('Estimated', $issue['severity']);
        $this->assertSame('Missing time', $issue['issue_type']);
        $this->assertSame('The recipe time was estimated.', $issue['description']);
        $this->assertSame('Verify with a subject-matter expert.', $issue['recommended_action']);
        $this->assertSame('unresolved', $issue['resolution_status']);
        $this->assertSame('recipe', $issue['entity_type']);
        $this->assertSame($this->curryRecipeId, $issue['recipe_id']);
    }

    public function test_unknown_recipe_and_ingredient_ids_return_standard_not_found(): void
    {
        foreach (['/api/v1/recipes/999999', '/api/v1/ingredients/999999'] as $uri) {
            $this->getJson($uri)
                ->assertNotFound()
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'NOT_FOUND')
                ->assertJsonPath('error.message', 'The requested API resource was not found.')
                ->assertJsonPath('meta.api_version', 'v1');
        }
    }

    public function test_invalid_query_parameters_return_validation_errors(): void
    {
        $pagination = $this->getJson('/api/v1/ingredients?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->json();

        $include = $this->getJson('/api/v1/recipes?include=anything')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->json();

        $missingCategory = $this->getJson('/api/v1/ingredients?ingredient_category_id=999999')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->json();

        $this->assertArrayHasKey('per_page', $pagination['error']['details']);
        $this->assertArrayHasKey('include', $include['error']['details']);
        $this->assertArrayHasKey('ingredient_category_id', $missingCategory['error']['details']);
    }

    private function createFixtures(): void
    {
        $curryCategory = RecipeCategory::create([
            'name' => 'Curry',
            'description' => 'Warm curries',
        ]);
        $soupCategory = RecipeCategory::create([
            'name' => 'Soup',
            'description' => 'Light soups',
        ]);
        $spiceCategory = IngredientCategory::create([
            'name' => 'Spice',
            'description' => 'Aromatic spices',
        ]);
        IngredientCategory::create([
            'name' => 'Grain',
            'description' => 'Cereal grains',
        ]);

        $pepper = Ingredient::create([
            'name' => 'Black Pepper',
            'ingredient_key' => 'black_pepper',
            'description' => 'Aromatic spice',
        ]);
        $rice = Ingredient::create([
            'name' => 'Rice',
            'ingredient_key' => 'rice',
            'description' => null,
        ]);

        $now = now();
        DB::table('ingredient_category')->insert([
            [
                'ingredient_id' => $pepper->id,
                'ingredient_category_id' => $spiceCategory->id,
                'source_identifier' => 'SRC-1',
                'source_record_identifier' => 'SRC-1-ING-1',
                'source_category_text' => 'Spice',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'ingredient_id' => $pepper->id,
                'ingredient_category_id' => $spiceCategory->id,
                'source_identifier' => 'SRC-1',
                'source_record_identifier' => 'SRC-1-ING-2',
                'source_category_text' => 'Spice blend',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $curry = Recipe::create([
            'recipe_category_id' => $curryCategory->id,
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
            'recipe_category_id' => $soupCategory->id,
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

        RecipeIngredient::create([
            'recipe_id' => $curry->id,
            'ingredient_id' => $pepper->id,
            'source_identifier' => 'SRC-1',
            'source_record_identifier' => 'SRC-1-ING-1',
            'original_ingredient_id' => 'SRC-1-ING-1',
            'original_ingredient_name' => 'Pepper',
            'original_quantity_text' => '2 teaspoons',
            'original_unit' => 'teaspoon',
            'per_person_quantity_min' => '0.28571',
            'per_person_quantity_max' => '0.28571',
            'per_person_quantity_description' => null,
            'per_person_unit' => 'teaspoon',
            'expert_approved_quantity' => null,
            'expert_approved_unit' => null,
            'approval_status' => 'Pending expert validation',
            'original_requirement_label' => 'Listed in source',
            'ingredient_preparation' => 'ground',
            'is_required' => true,
            'is_main_ingredient' => false,
            'ingredient_notes' => null,
            'data_quality_status' => 'Expert-approved quantity remains pending; no expert approval was fabricated.',
            'quantity_is_derived' => true,
            'quantity_description_is_estimated' => false,
            'quantity_depends_on_estimated_yield' => false,
        ]);
        RecipeIngredient::create([
            'recipe_id' => $curry->id,
            'ingredient_id' => $pepper->id,
            'source_identifier' => 'SRC-1',
            'source_record_identifier' => 'SRC-1-ING-2',
            'original_ingredient_id' => 'SRC-1-ING-2',
            'original_ingredient_name' => 'Pepper',
            'original_quantity_text' => '1 pinch',
            'original_unit' => 'pinch',
            'per_person_quantity_min' => '0.10000',
            'per_person_quantity_max' => '0.20000',
            'per_person_quantity_description' => null,
            'per_person_unit' => 'pinch',
            'expert_approved_quantity' => null,
            'expert_approved_unit' => null,
            'approval_status' => 'Pending expert validation',
            'original_requirement_label' => 'Listed in source',
            'ingredient_preparation' => null,
            'is_required' => false,
            'is_main_ingredient' => false,
            'ingredient_notes' => null,
            'data_quality_status' => 'Expert-approved quantity remains pending; no expert approval was fabricated.',
            'quantity_is_derived' => true,
            'quantity_description_is_estimated' => false,
            'quantity_depends_on_estimated_yield' => false,
        ]);
        RecipeIngredient::create([
            'recipe_id' => $soup->id,
            'ingredient_id' => $rice->id,
            'source_identifier' => 'SRC-2',
            'source_record_identifier' => 'SRC-2-ING-1',
            'original_ingredient_id' => 'SRC-2-ING-1',
            'original_ingredient_name' => 'Rice',
            'original_quantity_text' => '1 cup',
            'original_unit' => 'cup',
            'per_person_quantity_min' => '0.50000',
            'per_person_quantity_max' => '0.50000',
            'per_person_quantity_description' => null,
            'per_person_unit' => 'cup',
            'expert_approved_quantity' => null,
            'expert_approved_unit' => null,
            'approval_status' => 'Pending expert validation',
            'original_requirement_label' => 'Listed in source',
            'ingredient_preparation' => null,
            'is_required' => true,
            'is_main_ingredient' => true,
            'ingredient_notes' => null,
            'data_quality_status' => 'Expert-approved quantity remains pending; no expert approval was fabricated.',
            'quantity_is_derived' => true,
            'quantity_description_is_estimated' => false,
            'quantity_depends_on_estimated_yield' => false,
        ]);

        RecipeInstruction::create([
            'recipe_id' => $curry->id,
            'step_number' => 2,
            'is_method_summary' => false,
            'cooking_method_paragraph' => null,
            'cooking_steps_ordered' => null,
            'instruction_text' => 'Second step',
        ]);
        RecipeInstruction::create([
            'recipe_id' => $curry->id,
            'step_number' => 0,
            'is_method_summary' => true,
            'cooking_method_paragraph' => 'Summary paragraph',
            'cooking_steps_ordered' => "1. First step\n2. Second step",
            'instruction_text' => null,
        ]);
        RecipeInstruction::create([
            'recipe_id' => $curry->id,
            'step_number' => 1,
            'is_method_summary' => false,
            'cooking_method_paragraph' => null,
            'cooking_steps_ordered' => null,
            'instruction_text' => 'First step',
        ]);

        RecipeSource::create([
            'recipe_id' => $curry->id,
            'source_name' => 'Source Book',
            'source_identifier' => 'SRC-1',
            'source_record_identifier' => 'REC-001',
            'source_location' => 'Page 10',
            'source_uri' => 'https://example.test/recipes/REC-001',
            'attribution' => 'Original source',
            'notes' => null,
        ]);

        DataQualityIssue::create([
            'source_file' => 'recipes.csv',
            'source_row' => 2,
            'entity_type' => 'recipe',
            'entity_reference' => 'REC-001',
            'field_name' => 'total_time_minutes',
            'severity' => 'Estimated',
            'issue_type' => 'Missing time',
            'description' => 'The recipe time was estimated.',
            'recommended_action' => 'Verify with a subject-matter expert.',
            'resolution_status' => 'unresolved',
            'recipe_id' => $curry->id,
            'ingredient_id' => null,
        ]);
        DataQualityIssue::create([
            'source_file' => 'recipes.csv',
            'source_row' => 3,
            'entity_type' => 'ingredient',
            'entity_reference' => 'black_pepper',
            'field_name' => 'per_person_quantity_min',
            'severity' => 'Review',
            'issue_type' => 'Quantity pending approval',
            'description' => 'The derived quantity is awaiting approval.',
            'recommended_action' => 'Review the source quantity.',
            'resolution_status' => 'unresolved',
            'recipe_id' => null,
            'ingredient_id' => $pepper->id,
        ]);

        $this->curryCategoryId = $curryCategory->id;
        $this->soupCategoryId = $soupCategory->id;
        $this->spiceCategoryId = $spiceCategory->id;
        $this->pepperId = $pepper->id;
        $this->riceId = $rice->id;
        $this->curryRecipeId = $curry->id;
        $this->soupRecipeId = $soup->id;
    }
}
