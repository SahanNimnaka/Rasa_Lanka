<?php

namespace App\Console\Commands;

use App\Services\RecipeCsvImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportRasaLankaRecipes extends Command
{
    protected $signature = 'rasalanka:import-recipes
        {--dry-run : Validate and report without writing (the default)}
        {--commit : Apply a validated import in one transaction}
        {--file= : Import package directory (defaults to database/rasalanka_import)}';

    protected $description = 'Validate or import the RasaLanka recipe CSV package';

    public function __construct(private readonly RecipeCsvImportService $importer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        if ($commit && (bool) $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --commit, not both.');

            return self::FAILURE;
        }

        $directory = $this->option('file') ?: base_path('database/rasalanka_import');
        $this->info($commit ? 'Mode: COMMIT (writes only after successful validation)' : 'Mode: DRY RUN (read-only)');
        $this->line("Package: {$directory}");

        $analysis = $this->importer->analyze($directory);
        $this->printReport($analysis['report']);

        if ($analysis['report']['errors'] !== []) {
            $this->error('Preflight failed; no data was written.');
            foreach (array_slice($analysis['report']['errors'], 0, 20) as $error) {
                $this->line(" - {$error}");
            }

            return self::FAILURE;
        }

        if (! $commit) {
            $this->info('Dry run complete. No database writes were performed.');

            return self::SUCCESS;
        }

        try {
            $counts = $this->importer->commit($analysis);
        } catch (Throwable $exception) {
            $this->error('Import failed; the database transaction was rolled back.');
            $this->line($exception->getMessage());
            $this->line('Imported: 0 | Updated: 0 | Skipped: 0 | Failed: 1 (transaction rolled back)');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Commit complete');
        $this->table(
            ['Table', 'Imported', 'Updated', 'Skipped'],
            collect($counts)->map(fn (array $tableCounts, string $table): array => [
                $table,
                $tableCounts['imported'],
                $tableCounts['updated'],
                $tableCounts['skipped'],
            ])->values()->all()
        );

        return self::SUCCESS;
    }

    private function printReport(array $report): void
    {
        $this->newLine();
        $this->line('CSV files found:');
        foreach ($report['files_found'] as $name) {
            $count = $report['records_detected'][$name] ?? 'unreadable';
            $this->line(" - {$name}: {$count} records");
        }

        if ($report['ready_records'] !== []) {
            $this->newLine();
            $this->line('Records ready to import:');
            foreach ($report['ready_records'] as $table => $count) {
                $this->line(" - {$table}: {$count}");
            }
        }

        $this->newLine();
        $this->line('Skipped records: '.array_sum(array_map('count', $report['skipped_records'])));
        $this->line('Duplicate identifiers: '.count($report['duplicate_identifiers']));
        $this->line('Missing foreign-key references: '.count($report['missing_foreign_keys']));
        $this->line('Invalid numeric values: '.count($report['invalid_numeric_values']));
        $this->line('Numeric precision adjustments: '.count($report['numeric_precision_adjustments']));
        $this->line('Instruction parsing failures: '.count($report['instruction_parsing_failures']));
        $this->line('Ingredient category conflicts: '.$report['category_conflicts']);
        $this->line('Master aggregate estimate-flag inconsistencies (audit-only): '.count($report['aggregate_flag_inconsistencies']));
        foreach ($report['ambiguous_requirement_labels'] as $label => $count) {
            $this->line("is_required left NULL for '{$label}': {$count}");
        }

        foreach ($report['estimated_values'] as $name => $count) {
            $this->line(str_replace('_', ' ', ucfirst($name)).": {$count}");
        }
        $this->line('Expert validation pending: '.$report['expert_validation_pending']);

        foreach ($report['quality_issues_by_severity'] as $severity => $count) {
            $this->line("Data quality issues ({$severity}): {$count}");
        }

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }
        foreach (array_slice($report['numeric_precision_adjustments'], 0, 10) as $adjustment) {
            $this->warn($adjustment);
        }
        foreach ($report['unmapped_columns'] as $unmapped) {
            $this->warn("Unmapped input: {$unmapped}");
        }
    }
}
