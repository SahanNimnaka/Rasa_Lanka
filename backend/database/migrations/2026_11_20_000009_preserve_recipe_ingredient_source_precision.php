<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->text('original_requirement_label')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_ingredients
                ALTER COLUMN per_person_quantity_min TYPE NUMERIC(12, 5),
                ALTER COLUMN per_person_quantity_max TYPE NUMERIC(12, 5),
                ALTER COLUMN expert_approved_quantity TYPE NUMERIC(12, 5)');

            return;
        }

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->decimal('per_person_quantity_min', 12, 5)->nullable()->change();
            $table->decimal('per_person_quantity_max', 12, 5)->nullable()->change();
            $table->decimal('expert_approved_quantity', 12, 5)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $hasExcessPrecision = DB::table('recipe_ingredients')
                ->whereRaw('
                    per_person_quantity_min <> ROUND(per_person_quantity_min, 4)
                    OR per_person_quantity_max <> ROUND(per_person_quantity_max, 4)
                    OR expert_approved_quantity <> ROUND(expert_approved_quantity, 4)
                ')
                ->exists();

            if ($hasExcessPrecision) {
                throw new \RuntimeException(
                    'Cannot reduce recipe ingredient quantity precision while rows contain more than four decimal places.'
                );
            }

            DB::statement('ALTER TABLE recipe_ingredients
                ALTER COLUMN per_person_quantity_min TYPE NUMERIC(12, 4),
                ALTER COLUMN per_person_quantity_max TYPE NUMERIC(12, 4),
                ALTER COLUMN expert_approved_quantity TYPE NUMERIC(12, 4)');
        } else {
            Schema::table('recipe_ingredients', function (Blueprint $table) {
                $table->decimal('per_person_quantity_min', 12, 4)->nullable()->change();
                $table->decimal('per_person_quantity_max', 12, 4)->nullable()->change();
                $table->decimal('expert_approved_quantity', 12, 4)->nullable()->change();
            });
        }

        Schema::table('recipe_ingredients', function (Blueprint $table) {
            $table->dropColumn('original_requirement_label');
        });
    }
};
