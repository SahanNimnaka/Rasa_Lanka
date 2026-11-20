<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeInstruction;
use App\Services\RecipeCsvImportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportRasaLankaRecipesTest extends TestCase
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

    public function test_default_command_runs_a_non_destructive_dry_run(): void
    {
        $before = $this->recipeTableCounts();

        $this->artisan('rasalanka:import-recipes')
            ->expectsOutputToContain('Mode: DRY RUN')
            ->expectsOutputToContain('No database writes were performed.')
            ->assertExitCode(0);

        $this->assertSame($before, $this->recipeTableCounts());
    }

    public function test_commit_resolves_keys_preserves_conflicts_and_is_idempotent(): void
    {
        $importer = app(RecipeCsvImportService::class);
        $analysis = $importer->analyze(base_path('database/rasalanka_import'));

        $this->assertSame([], $analysis['report']['errors']);
        $this->assertSame([], $analysis['report']['numeric_precision_adjustments']);
        $this->assertSame([
            'Listed in this source version' => 1,
            'Adjustable' => 3,
        ], $analysis['report']['ambiguous_requirement_labels']);
        $firstImport = $importer->commit($analysis);

        $this->assertSame(8, DB::table('recipe_categories')->count());
        $this->assertSame(16, DB::table('ingredient_categories')->count());
        $this->assertSame(116, DB::table('ingredients')->count());
        $this->assertSame(20, DB::table('recipes')->count());
        $this->assertSame(337, DB::table('ingredient_category')->count());
        $this->assertSame(337, DB::table('recipe_ingredients')->count());
        $this->assertSame(146, DB::table('recipe_instructions')->count());
        $this->assertSame(20, DB::table('recipe_sources')->count());
        $this->assertSame(24, DB::table('data_quality_issues')->count());
        $this->assertSame(8, $firstImport['recipe_categories']['imported']);

        $recipe = Recipe::where('recipe_code', 'FIS-001')->firstOrFail();
        $this->assertSame('Fish', $recipe->recipeCategory->name);

        $ingredientRow = RecipeIngredient::where('original_ingredient_id', 'POT-001-ING-01')
            ->firstOrFail();
        $this->assertSame('POT-001', $ingredientRow->recipe->recipe_code);
        $this->assertSame('potatoes', $ingredientRow->ingredient->ingredient_key);

        $this->assertSame(20, RecipeIngredient::where('is_main_ingredient', true)->count());
        $this->assertSame(337, RecipeIngredient::where('approval_status', 'Pending expert validation')
            ->whereNull('expert_approved_quantity')
            ->whereNull('expert_approved_unit')
            ->count());
        $this->assertTrue(Recipe::where('recipe_code', 'POT-005')->firstOrFail()->yield_is_estimated);

        $precisionAdjusted = RecipeIngredient::where('original_ingredient_id', 'FIS-001-ING-004')
            ->firstOrFail();
        $this->assertSame('0.28571', $precisionAdjusted->per_person_quantity_min);

        $ambiguousRequirement = RecipeIngredient::where('original_ingredient_id', 'FIS-002-ING-006')
            ->firstOrFail();
        $this->assertNull($ambiguousRequirement->is_required);
        $this->assertSame('Listed in this source version', $ambiguousRequirement->original_requirement_label);
        $this->assertSame('Traditional dry ambul thiyal often omits coconut milk', $ambiguousRequirement->ingredient_notes);

        $ambiguousRequirements = RecipeIngredient::whereIn('original_requirement_label', [
            'Adjustable',
            'Listed in this source version',
        ])->get();
        $this->assertCount(4, $ambiguousRequirements);
        $this->assertSame(4, $ambiguousRequirements->whereNull('is_required')->count());

        $qualityIssueColumns = [
            'source_file',
            'source_row',
            'entity_type',
            'entity_reference',
            'field_name',
            'severity',
            'issue_type',
            'description',
            'recommended_action',
            'resolution_status',
        ];
        $expectedIssues = collect($analysis['plan']['data_quality_issues'])
            ->map(fn (array $row): array => $row['values'])
            ->values()
            ->all();
        $storedIssues = DB::table('data_quality_issues')
            ->orderBy('source_row')
            ->get($qualityIssueColumns)
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $this->assertSame($expectedIssues, $storedIssues);
        $this->assertSame(17, DB::table('data_quality_issues')->whereNotNull('recipe_id')->count());
        $this->assertSame(7, DB::table('data_quality_issues')->whereNotNull('ingredient_id')->count());

        $cuminCategories = Ingredient::where('ingredient_key', 'cumin_seeds')
            ->firstOrFail()
            ->ingredientCategories()
            ->distinct()
            ->orderBy('ingredient_categories.name')
            ->pluck('ingredient_categories.name')
            ->all();
        $this->assertEqualsCanonicalizing(['Paste spice', 'Spice', 'Spice blend'], $cuminCategories);

        $summary = RecipeInstruction::where('recipe_id', $recipe->id)->where('step_number', 0)->firstOrFail();
        $this->assertTrue($summary->is_method_summary);
        $this->assertNotEmpty($summary->cooking_method_paragraph);
        $this->assertNotEmpty($summary->cooking_steps_ordered);
        $expectedSummary = collect($analysis['plan']['recipe_instructions'])
            ->first(fn (array $row): bool => $row['recipe_code'] === 'FIS-001' && $row['step_number'] === 0);
        $this->assertSame($expectedSummary['values']['cooking_method_paragraph'], $summary->cooking_method_paragraph);
        $this->assertSame($expectedSummary['values']['cooking_steps_ordered'], $summary->cooking_steps_ordered);
        $steps = RecipeInstruction::where('recipe_id', $recipe->id)
            ->where('step_number', '>', 0)
            ->orderBy('step_number')
            ->get();
        $this->assertSame(range(1, $steps->count()), $steps->pluck('step_number')->all());

        $secondImport = $importer->commit($analysis);
        foreach ($secondImport as $tableCounts) {
            $this->assertSame(0, $tableCounts['imported']);
            $this->assertSame(0, $tableCounts['updated']);
        }
        $this->assertSame(337, DB::table('recipe_ingredients')->count());
    }

    public function test_instruction_parser_preserves_order_and_reports_invalid_sequences(): void
    {
        $importer = app(RecipeCsvImportService::class);

        $parsed = $importer->parseOrderedSteps("1. Mix well.\n2. Cook until tender.");
        $this->assertSame([1 => 'Mix well.', 2 => 'Cook until tender.'], $parsed['steps']);
        $this->assertNull($parsed['error']);

        $invalid = $importer->parseOrderedSteps('1. Mix well. 3. Serve.');
        $this->assertSame([], $invalid['steps']);
        $this->assertNotNull($invalid['error']);
    }

    public function test_failed_commit_rolls_back_all_prior_import_writes(): void
    {
        $importer = app(RecipeCsvImportService::class);
        $analysis = $importer->analyze(base_path('database/rasalanka_import'));
        $this->assertSame([], $analysis['report']['errors']);

        DB::unprepared("CREATE TRIGGER reject_quality_issue BEFORE INSERT ON data_quality_issues
            WHEN NEW.source_row = 25
            BEGIN SELECT RAISE(ABORT, 'forced rollback test'); END");

        try {
            $importer->commit($analysis);
            $this->fail('The forced database error should abort the import.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced rollback test', $exception->getMessage());
        }

        foreach (array_keys($this->recipeTableCounts()) as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty after rollback.");
        }
    }

    private function recipeTableCounts(): array
    {
        $counts = [];
        foreach ([
            'recipe_categories',
            'ingredient_categories',
            'ingredients',
            'recipes',
            'ingredient_category',
            'recipe_ingredients',
            'recipe_instructions',
            'recipe_sources',
            'data_quality_issues',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
