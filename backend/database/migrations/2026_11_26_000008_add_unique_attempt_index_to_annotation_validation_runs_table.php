<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public string $indexName = 'annotation_validation_runs_record_intent_unique';

    /**
     * Test seam only: when set, overrides driver detection. Production callers never set this.
     */
    public ?string $driverNameOverride = null;

    public function up(): void
    {

        $driver = $this->driverName();

        if ($driver === 'sqlsrv') {

            throw new RuntimeException(
                'SQL Server (sqlsrv) is not supported for RasaLanka annotation/S2. '

                .'Supported databases: PostgreSQL and MySQL for production; SQLite for development/testing. '

                .'This migration adds the I5 unique validation-attempt index and will not mutate schema on sqlsrv.'

            );

        }

        $duplicates = DB::table('annotation_validation_runs')

            ->select('annotation_record_id', 'submission_intent_id', DB::raw('COUNT(*) as aggregate'))

            ->whereNotNull('submission_intent_id')

            ->groupBy('annotation_record_id', 'submission_intent_id')

            ->havingRaw('COUNT(*) > 1')

            ->limit(1)

            ->get();

        if ($duplicates->isNotEmpty()) {

            throw new RuntimeException(
                'Cannot add unique annotation validation attempt index: duplicate (annotation_record_id, submission_intent_id) rows exist.'

            );

        }

        Schema::table('annotation_validation_runs', function (Blueprint $table): void {

            $table->unique(['annotation_record_id', 'submission_intent_id'], $this->indexName);

        });

    }

    public function down(): void
    {

        $driver = $this->driverName();

        if ($driver === 'sqlsrv') {

            // Guard: SQL Server is unsupported for annotation/S2 and up() never creates this index on sqlsrv.

            return;

        }

        if (! Schema::hasTable('annotation_validation_runs')) {

            return;

        }

        $hasIndex = collect(Schema::getIndexes('annotation_validation_runs'))

            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $this->indexName);

        if (! $hasIndex) {

            return;

        }

        Schema::table('annotation_validation_runs', function (Blueprint $table): void {

            $table->dropUnique($this->indexName);

        });

    }

    protected function driverName(): string
    {

        return $this->driverNameOverride ?? DB::connection()->getDriverName();

    }

};
