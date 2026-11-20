<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Services\RecipeCsvImportService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSqlRecipeImportTest extends TestCase
{
    private const CONNECTION = 'rasalanka_pg_test';

    protected function setUp(): void
    {
        parent::setUp();

        $database = getenv('RASALANKA_TEST_PG_DATABASE');
        if ($database === false || $database === '') {
            $this->markTestSkipped('Set RASALANKA_TEST_PG_DATABASE to a disposable database named rasalanka_test_*.');
        }

        if (preg_match('/^rasalanka_test_[a-z0-9_]+$/i', $database) !== 1) {
            $this->fail('PostgreSQL integration tests refuse databases not named rasalanka_test_*.');
        }

        $connection = config('database.connections.pgsql');
        $connection['url'] = null;
        $connection['database'] = $database;
        $connection['search_path'] = 'public';

        config([
            'database.connections.'.self::CONNECTION => $connection,
            'database.default' => self::CONNECTION,
        ]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);

        $this->artisan('migrate', ['--database' => self::CONNECTION, '--force' => true])
            ->assertExitCode(0);
        $this->clearRecipeRows();
    }

    protected function tearDown(): void
    {
        if (getenv('RASALANKA_TEST_PG_DATABASE') !== false
            && preg_match('/^rasalanka_test_[a-z0-9_]+$/i', (string) getenv('RASALANKA_TEST_PG_DATABASE')) === 1) {
            $this->clearRecipeRows();
            DB::purge(self::CONNECTION);
        }

        parent::tearDown();
    }

    public function test_postgresql_commit_preserves_precision_labels_and_individual_estimate_flags(): void
    {
        $importer = app(RecipeCsvImportService::class);
        $analysis = $importer->analyze(base_path('database/rasalanka_import'));

        $this->assertSame([], $analysis['report']['errors']);
        $this->assertSame([], $analysis['report']['numeric_precision_adjustments']);
        $this->assertCount(17, $analysis['report']['aggregate_flag_inconsistencies']);
        $this->assertSame(['Estimated' => 15, 'Review' => 9], $analysis['report']['quality_issues_by_severity']);
        $this->assertSame([
            'Listed in this source version' => 1,
            'Adjustable' => 3,
        ], $analysis['report']['ambiguous_requirement_labels']);
        $this->assertGreaterThanOrEqual(180000, (int) DB::scalar('SHOW server_version_num'));

        $plannedLabels = collect($analysis['plan']['recipe_ingredients'])
            ->mapWithKeys(fn (array $row): array => [
                $row['original_ingredient_id'] => $row['values']['original_requirement_label'],
            ])
            ->all();
        $importer->commit($analysis);

        $storedLabels = RecipeIngredient::query()
            ->orderBy('original_ingredient_id')
            ->get(['original_ingredient_id', 'original_requirement_label'])
            ->mapWithKeys(fn (RecipeIngredient $row): array => [
                $row->original_ingredient_id => $row->original_requirement_label,
            ])
            ->all();
        ksort($plannedLabels);
        ksort($storedLabels);
        $this->assertSame($plannedLabels, $storedLabels);

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

        $this->assertSame('0.28571', RecipeIngredient::where(
            'original_ingredient_id',
            'FIS-001-ING-004'
        )->value('per_person_quantity_min'));

        $scale = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'recipe_ingredients')
            ->where('column_name', 'per_person_quantity_min')
            ->value('numeric_scale');
        $this->assertSame(5, (int) $scale);

        $ambiguousRows = RecipeIngredient::whereIn('original_requirement_label', [
            'Adjustable',
            'Listed in this source version',
        ])->get();
        $this->assertCount(4, $ambiguousRows);
        $this->assertSame(4, $ambiguousRows->whereNull('is_required')->count());
        $this->assertSame(
            'Traditional dry ambul thiyal often omits coconut milk',
            RecipeIngredient::where('original_ingredient_id', 'FIS-002-ING-006')
                ->value('ingredient_notes')
        );

        foreach ($analysis['report']['aggregate_flag_inconsistencies'] as $inconsistentId) {
            $plannedIngredient = collect($analysis['plan']['recipe_ingredients'])
                ->firstWhere('original_ingredient_id', $inconsistentId);
            $plannedRecipe = collect($analysis['plan']['recipes'])
                ->firstWhere('recipe_code', $plannedIngredient['recipe_code']);
            $storedIngredient = RecipeIngredient::where('original_ingredient_id', $inconsistentId)->firstOrFail();
            $storedRecipe = Recipe::findOrFail($storedIngredient->recipe_id);

            foreach ([
                'quantity_is_derived',
                'quantity_description_is_estimated',
                'quantity_depends_on_estimated_yield',
            ] as $flag) {
                $this->assertSame($plannedIngredient['values'][$flag], $storedIngredient->{$flag});
            }
            $this->assertSame(
                $plannedRecipe['values']['time_is_estimated'],
                $storedRecipe->time_is_estimated
            );
            $this->assertSame(
                $plannedRecipe['values']['yield_is_estimated'],
                $storedRecipe->yield_is_estimated
            );
        }
    }

    public function test_postgresql_foreign_keys_and_source_identifier_uniqueness_are_enforced(): void
    {
        $importer = app(RecipeCsvImportService::class);
        $analysis = $importer->analyze(base_path('database/rasalanka_import'));
        $importer->commit($analysis);

        try {
            DB::table('recipe_ingredients')->insert([
                'recipe_id' => 2147483647,
                'ingredient_id' => 2147483647,
                'original_ingredient_id' => 'PHASE08-INVALID-FK',
            ]);
            $this->fail('PostgreSQL accepted recipe ingredient rows with invalid foreign keys.');
        } catch (QueryException) {
        }

        $existing = DB::table('recipe_ingredients')
            ->where('original_ingredient_id', 'FIS-001-ING-004')
            ->first();
        $duplicate = (array) $existing;
        unset($duplicate['id']);

        try {
            DB::table('recipe_ingredients')->insert($duplicate);
            $this->fail('PostgreSQL accepted a duplicate original ingredient identifier.');
        } catch (QueryException) {
        }

        $this->assertSame(337, DB::table('recipe_ingredients')->count());

        $existingIssue = DB::table('data_quality_issues')
            ->where('source_file', 'data_quality_issues.csv')
            ->where('source_row', 19)
            ->first();
        $duplicateIssue = (array) $existingIssue;
        unset($duplicateIssue['id']);

        try {
            DB::table('data_quality_issues')->insert($duplicateIssue);
            $this->fail('PostgreSQL accepted a duplicate data-quality source row.');
        } catch (QueryException) {
        }

        $this->assertSame(24, DB::table('data_quality_issues')->count());
    }

    public function test_postgresql_dry_run_does_not_write_recipe_records(): void
    {
        $this->artisan('rasalanka:import-recipes')
            ->expectsOutputToContain('Mode: DRY RUN')
            ->expectsOutputToContain('No database writes were performed.')
            ->assertExitCode(0);

        foreach ($this->recipeTables() as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should remain empty after dry-run.");
        }
    }

    public function test_postgresql_import_is_idempotent_and_rolls_back_after_a_late_failure(): void
    {
        $importer = app(RecipeCsvImportService::class);
        $analysis = $importer->analyze(base_path('database/rasalanka_import'));
        $firstImport = $importer->commit($analysis);
        DB::table('data_quality_issues')
            ->where('source_file', 'data_quality_issues.csv')
            ->where('source_row', 19)
            ->update(['resolution_status' => 'resolved']);
        $secondImport = $importer->commit($analysis);

        $this->assertSame(337, DB::table('recipe_ingredients')->count());
        $this->assertSame(24, DB::table('data_quality_issues')->count());
        $this->assertSame('resolved', DB::table('data_quality_issues')
            ->where('source_file', 'data_quality_issues.csv')
            ->where('source_row', 19)
            ->value('resolution_status'));
        $this->assertSame(0, $secondImport['recipe_ingredients']['imported']);
        $this->assertSame(0, $secondImport['recipe_ingredients']['updated']);
        $this->assertSame(0, $secondImport['data_quality_issues']['imported']);
        $this->assertSame(0, $secondImport['data_quality_issues']['updated']);

        $this->clearRecipeRows();
        DB::statement('ALTER TABLE data_quality_issues ADD CONSTRAINT phase08_reject_quality_issue CHECK (source_row < 25)');

        try {
            try {
                $importer->commit($analysis);
                $this->fail('The late PostgreSQL constraint violation should abort the import.');
            } catch (QueryException) {
            }

            foreach ($this->recipeTables() as $table) {
                $this->assertSame(0, DB::table($table)->count(), "{$table} rows should be rolled back.");
            }
        } finally {
            DB::statement('ALTER TABLE data_quality_issues DROP CONSTRAINT IF EXISTS phase08_reject_quality_issue');
        }
    }

    private function clearRecipeRows(): void
    {
        foreach (array_reverse($this->recipeTables()) as $table) {
            DB::table($table)->delete();
        }
    }

    private function recipeTables(): array
    {
        return [
            'recipe_categories',
            'ingredient_categories',
            'ingredients',
            'recipes',
            'ingredient_category',
            'recipe_ingredients',
            'recipe_instructions',
            'recipe_sources',
            'data_quality_issues',
        ];
    }
}
