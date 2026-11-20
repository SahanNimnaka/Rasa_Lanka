<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_instructions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')
                ->constrained('recipes')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('step_number');
            $table->boolean('is_method_summary')->default(false);
            $table->text('cooking_method_paragraph')->nullable();
            $table->text('cooking_steps_ordered')->nullable();
            $table->text('instruction_text')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'step_number']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipe_instructions ADD CONSTRAINT recipe_instructions_record_type_valid CHECK (
                (
                    is_method_summary
                    AND step_number = 0
                    AND instruction_text IS NULL
                )
                OR (
                    NOT is_method_summary
                    AND step_number > 0
                    AND instruction_text IS NOT NULL
                    AND cooking_method_paragraph IS NULL
                    AND cooking_steps_ordered IS NULL
                )
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_instructions');
    }
};
