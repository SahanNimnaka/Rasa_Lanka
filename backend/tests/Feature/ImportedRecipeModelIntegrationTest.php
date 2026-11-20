<?php

namespace Tests\Feature;

use App\Models\DataQualityIssue;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\RecipeIngredient;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportedRecipeModelIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Imported-data integration tests require the pgsql connection.');
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'rasalanka') {
            $this->markTestSkipped('Imported-data integration tests require the rasalanka PostgreSQL database.');
        }

        $expectedCounts = [
            'recipe_categories' => 8,
            'ingredient_categories' => 16,
            'ingredients' => 116,
            'recipes' => 20,
            'ingredient_category' => 337,
            'recipe_ingredients' => 337,
            'recipe_instructions' => 146,
            'recipe_sources' => 20,
            'data_quality_issues' => 24,
        ];

        foreach ($expectedCounts as $table => $expected) {
            if (DB::table($table)->count() !== $expected) {
                $this->markTestSkipped("Expected imported data is not present in {$table}; run the dataset import first.");
            }
        }
    }

    public function test_imported_dataset_counts_are_visible_through_the_models(): void
    {
        $this->assertSame(8, RecipeCategory::query()->count());
        $this->assertSame(20, Recipe::query()->count());
        $this->assertSame(116, Ingredient::query()->count());
        $this->assertSame(337, RecipeIngredient::query()->count());
        $this->assertSame(24, DataQualityIssue::query()->count());
    }

    public function test_recipe_can_retrieve_its_ingredients_and_rich_pivot_data(): void
    {
        $recipe = $this->importedRecipe();

        $this->assertSame(14, $recipe->recipeIngredients()->count());
        $this->assertSame(14, $recipe->ingredients()->count());

        $ingredient = $recipe->ingredients()
            ->wherePivot('original_ingredient_id', 'FIS-001-ING-004')
            ->firstOrFail();

        $this->assertSame('black_peppercorns', $ingredient->ingredient_key);
        $this->assertSame('FIS-001', $ingredient->pivot->source_identifier);
        $this->assertSame('FIS-001-ING-004', $ingredient->pivot->source_record_identifier);
        $this->assertNotNull($ingredient->pivot->created_at);
        $this->assertNotNull($ingredient->pivot->updated_at);
    }

    public function test_recipe_can_retrieve_its_instructions_in_step_order(): void
    {
        $instructions = $this->importedRecipe()->recipeInstructions()->get();
        $stepNumbers = $instructions->pluck('step_number')->all();

        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $stepNumbers);
        $this->assertTrue((bool) $instructions->first()->is_method_summary);
        $this->assertFalse((bool) $instructions->last()->is_method_summary);
    }

    public function test_recipe_can_retrieve_its_category(): void
    {
        $recipe = $this->importedRecipe();

        $this->assertInstanceOf(RecipeCategory::class, $recipe->recipeCategory);
        $this->assertSame($recipe->recipe_category_id, $recipe->recipeCategory->id);
    }

    public function test_ingredient_can_retrieve_its_categories_with_provenance(): void
    {
        $ingredient = Ingredient::where('ingredient_key', 'black_peppercorns')->firstOrFail();

        $categories = $ingredient->ingredientCategories()
            ->orderBy('ingredient_categories.name')
            ->get();

        $this->assertSame(['Spice', 'Spice blend'], $categories->pluck('name')->all());
        $this->assertSame('FIS-001', $categories->first()->pivot->source_identifier);
        $this->assertSame('FIS-001-ING-004', $categories->first()->pivot->source_record_identifier);
        $this->assertSame('Spice', $categories->first()->pivot->source_category_text);
    }

    public function test_ingredient_can_retrieve_recipes_using_it(): void
    {
        $ingredient = Ingredient::where('ingredient_key', 'black_peppercorns')->firstOrFail();

        $expectedRecipeCount = DB::table('recipe_ingredients')
            ->where('ingredient_id', $ingredient->id)
            ->distinct()
            ->count('recipe_id');

        $this->assertSame($expectedRecipeCount, $ingredient->recipes()->count());
        $this->assertTrue($ingredient->recipes()
            ->where('recipes.recipe_code', 'FIS-001')
            ->exists());
    }

    public function test_recipe_can_retrieve_its_sources(): void
    {
        $recipe = $this->importedRecipe();
        $sources = $recipe->recipeSources()->get();

        $this->assertCount(1, $sources);
        $this->assertSame('FIS-001', $sources->first()->source_identifier);
        $this->assertSame($recipe->id, $sources->first()->recipe_id);
    }

    public function test_data_quality_issues_can_retrieve_their_related_recipe_or_ingredient(): void
    {
        $recipeIssue = DataQualityIssue::whereNotNull('recipe_id')
            ->with('recipe')
            ->firstOrFail();

        $ingredientIssue = DataQualityIssue::whereNotNull('ingredient_id')
            ->with('ingredient')
            ->firstOrFail();

        $this->assertSame(24, DataQualityIssue::where('resolution_status', 'unresolved')->count());
        $this->assertSame('recipe', $recipeIssue->entity_type);
        $this->assertSame($recipeIssue->recipe_id, $recipeIssue->recipe->id);
        $this->assertSame('ingredient', $ingredientIssue->entity_type);
        $this->assertSame($ingredientIssue->ingredient_id, $ingredientIssue->ingredient->id);
    }

    public function test_decimal_quantity_values_retain_their_source_precision(): void
    {
        $ids = [
            'FIS-001-ING-004',
            'FIS-001-ING-005',
            'FIS-001-ING-006',
            'FIS-001-ING-007',
            'FIS-001-ING-013',
        ];

        foreach ($ids as $id) {
            $quantity = RecipeIngredient::where('original_ingredient_id', $id)->firstOrFail();

            $this->assertSame('0.28571', $quantity->per_person_quantity_min);
            $this->assertSame('0.28571', $quantity->per_person_quantity_max);
        }
    }

    private function importedRecipe(): Recipe
    {
        return Recipe::where('recipe_code', 'FIS-001')->firstOrFail();
    }
}
