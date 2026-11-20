<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')
                ->constrained('recipes')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_id')
                ->constrained('ingredients')
                ->restrictOnDelete();
            $table->string('source_identifier', 160)->nullable();
            $table->string('source_record_identifier', 160)->nullable();
            $table->string('original_ingredient_id', 160)->nullable();
            $table->text('original_ingredient_name')->nullable();
            $table->text('original_quantity_text')->nullable();
            $table->string('original_unit', 100)->nullable();
            $table->decimal('per_person_quantity_min', 12, 4)->nullable();
            $table->decimal('per_person_quantity_max', 12, 4)->nullable();
            $table->text('per_person_quantity_description')->nullable();
            $table->string('per_person_unit', 100)->nullable();
            $table->decimal('expert_approved_quantity', 12, 4)->nullable();
            $table->string('expert_approved_unit', 100)->nullable();
            $table->string('approval_status', 32)->default('unreviewed');
            $table->text('ingredient_preparation')->nullable();
            $table->boolean('is_required')->nullable();
            $table->boolean('is_main_ingredient')->default(false);
            $table->text('ingredient_notes')->nullable();
            $table->text('data_quality_status')->nullable();
            $table->boolean('quantity_is_derived')->nullable();
            $table->boolean('quantity_description_is_estimated')->nullable();
            $table->boolean('quantity_depends_on_estimated_yield')->nullable();
            $table->timestamps();

            $table->index(['recipe_id', 'ingredient_id']);
            $table->index(['source_identifier', 'source_record_identifier']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX recipe_ingredients_original_ingredient_id_unique
                ON recipe_ingredients (original_ingredient_id)
                WHERE original_ingredient_id IS NOT NULL');
            DB::statement('ALTER TABLE recipe_ingredients ADD CONSTRAINT recipe_ingredient_quantities_nonnegative CHECK (
                (per_person_quantity_min IS NULL OR per_person_quantity_min >= 0)
                AND (per_person_quantity_max IS NULL OR per_person_quantity_max >= 0)
                AND (expert_approved_quantity IS NULL OR expert_approved_quantity >= 0)
                AND (
                    per_person_quantity_min IS NULL
                    OR per_person_quantity_max IS NULL
                    OR per_person_quantity_min <= per_person_quantity_max
                )
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
    }
};
