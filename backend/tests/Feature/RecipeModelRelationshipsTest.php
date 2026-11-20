<?php

namespace Tests\Feature;

use App\Models\DataQualityIssue;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\RecipeIngredient;
use App\Models\RecipeInstruction;
use App\Models\RecipeSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecipeModelRelationshipsTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_recipe_model_relationships_use_the_existing_tables_and_keys(): void
    {
        $relations = [
            [(new RecipeCategory)->recipes(), HasMany::class, 'recipes', 'recipe_category_id'],
            [(new Recipe)->recipeCategory(), BelongsTo::class, 'recipe_categories', 'recipe_category_id'],
            [(new Recipe)->recipeIngredients(), HasMany::class, 'recipe_ingredients', 'recipe_id'],
            [(new Recipe)->recipeInstructions(), HasMany::class, 'recipe_instructions', 'recipe_id'],
            [(new Recipe)->recipeSources(), HasMany::class, 'recipe_sources', 'recipe_id'],
            [(new Recipe)->dataQualityIssues(), HasMany::class, 'data_quality_issues', 'recipe_id'],
            [(new Ingredient)->recipeIngredients(), HasMany::class, 'recipe_ingredients', 'ingredient_id'],
            [(new Ingredient)->dataQualityIssues(), HasMany::class, 'data_quality_issues', 'ingredient_id'],
            [(new RecipeIngredient)->recipe(), BelongsTo::class, 'recipes', 'recipe_id'],
            [(new RecipeIngredient)->ingredient(), BelongsTo::class, 'ingredients', 'ingredient_id'],
            [(new RecipeInstruction)->recipe(), BelongsTo::class, 'recipes', 'recipe_id'],
            [(new RecipeSource)->recipe(), BelongsTo::class, 'recipes', 'recipe_id'],
            [(new DataQualityIssue)->recipe(), BelongsTo::class, 'recipes', 'recipe_id'],
            [(new DataQualityIssue)->ingredient(), BelongsTo::class, 'ingredients', 'ingredient_id'],
        ];

        foreach ($relations as [$relation, $type, $table, $foreignKey]) {
            $this->assertInstanceOf($type, $relation);
            $this->assertSame($table, $relation->getRelated()->getTable());
            $this->assertSame($foreignKey, $relation->getForeignKeyName());
        }
    }

    public function test_ingredient_category_relationships_keep_pivot_provenance_and_timestamps(): void
    {
        $relation = (new Ingredient)->ingredientCategories();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertSame('ingredient_categories', $relation->getRelated()->getTable());
        $this->assertSame('ingredient_category', $relation->getTable());
        $this->assertSame('ingredient_id', $relation->getForeignPivotKeyName());
        $this->assertSame('ingredient_category_id', $relation->getRelatedPivotKeyName());
        $this->assertContains('source_identifier', $relation->getPivotColumns());
        $this->assertContains('source_record_identifier', $relation->getPivotColumns());
        $this->assertContains('source_category_text', $relation->getPivotColumns());
        $this->assertContains('created_at', $relation->getPivotColumns());
        $this->assertContains('updated_at', $relation->getPivotColumns());

        $inverse = (new IngredientCategory)->ingredients();

        $this->assertSame('ingredient_category_id', $inverse->getForeignPivotKeyName());
        $this->assertSame('ingredient_id', $inverse->getRelatedPivotKeyName());
    }

    public function test_recipe_and_ingredient_relationships_use_the_rich_recipe_ingredients_table(): void
    {
        $recipeToIngredients = (new Recipe)->ingredients();

        $this->assertInstanceOf(BelongsToMany::class, $recipeToIngredients);
        $this->assertSame('ingredients', $recipeToIngredients->getRelated()->getTable());
        $this->assertSame('recipe_ingredients', $recipeToIngredients->getTable());
        $this->assertSame('recipe_id', $recipeToIngredients->getForeignPivotKeyName());
        $this->assertSame('ingredient_id', $recipeToIngredients->getRelatedPivotKeyName());
        $this->assertContains('original_ingredient_id', $recipeToIngredients->getPivotColumns());
        $this->assertContains('per_person_quantity_min', $recipeToIngredients->getPivotColumns());
        $this->assertContains('created_at', $recipeToIngredients->getPivotColumns());
        $this->assertContains('updated_at', $recipeToIngredients->getPivotColumns());

        $ingredientToRecipes = (new Ingredient)->recipes();

        $this->assertInstanceOf(BelongsToMany::class, $ingredientToRecipes);
        $this->assertSame('recipes', $ingredientToRecipes->getRelated()->getTable());
        $this->assertSame('recipe_ingredients', $ingredientToRecipes->getTable());
        $this->assertSame('ingredient_id', $ingredientToRecipes->getForeignPivotKeyName());
        $this->assertSame('recipe_id', $ingredientToRecipes->getRelatedPivotKeyName());
    }

    public function test_model_count_queries_do_not_change_existing_recipe_table_rows(): void
    {
        $modelsByTable = [
            'recipe_categories' => RecipeCategory::class,
            'recipes' => Recipe::class,
            'ingredients' => Ingredient::class,
            'ingredient_categories' => IngredientCategory::class,
            'recipe_ingredients' => RecipeIngredient::class,
            'recipe_instructions' => RecipeInstruction::class,
            'recipe_sources' => RecipeSource::class,
            'data_quality_issues' => DataQualityIssue::class,
        ];

        $countsBefore = [];
        $modelCounts = [];

        foreach ($modelsByTable as $table => $model) {
            $countsBefore[$table] = DB::table($table)->count();
            $modelCounts[$table] = $model::query()->count();
        }

        $countsAfter = [];

        foreach (array_keys($modelsByTable) as $table) {
            $countsAfter[$table] = DB::table($table)->count();
        }

        $this->assertSame($countsBefore, $modelCounts);
        $this->assertSame($countsBefore, $countsAfter);
    }

    public function test_nullable_estimates_and_unapproved_quantities_are_preserved(): void
    {
        $recipe = new Recipe([
            'time_is_estimated' => null,
            'yield_is_estimated' => null,
        ]);
        $ingredient = new RecipeIngredient([
            'expert_approved_quantity' => null,
            'expert_approved_unit' => null,
            'approval_status' => 'unreviewed',
        ]);

        $this->assertNull($recipe->time_is_estimated);
        $this->assertNull($recipe->yield_is_estimated);
        $this->assertNull($ingredient->expert_approved_quantity);
        $this->assertNull($ingredient->expert_approved_unit);
        $this->assertSame('unreviewed', $ingredient->approval_status);
    }
}
