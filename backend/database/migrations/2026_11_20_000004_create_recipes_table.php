<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_category_id')
                ->constrained('recipe_categories')
                ->restrictOnDelete();
            $table->string('recipe_code', 120)->nullable()->index();
            $table->string('name', 255);
            $table->string('main_ingredient', 200)->nullable();
            $table->string('recipe_type', 120)->nullable();
            $table->text('cooking_variation')->nullable();
            $table->string('region', 160)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('preparation_time_minutes')->nullable();
            $table->unsignedInteger('cooking_time_minutes')->nullable();
            $table->unsignedInteger('total_time_minutes')->nullable();
            $table->text('preparation_time_source')->nullable();
            $table->text('cooking_time_source')->nullable();
            $table->text('total_time_source')->nullable();
            $table->text('source_yield')->nullable();
            $table->decimal('yield_people', 6, 2)->nullable();
            $table->boolean('time_is_estimated')->nullable();
            $table->boolean('yield_is_estimated')->nullable();
            $table->string('time_arithmetic_status', 32)->nullable();
            $table->timestamps();

            $table->index(['recipe_category_id', 'name']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_times_nonnegative CHECK (
                (preparation_time_minutes IS NULL OR preparation_time_minutes >= 0)
                AND (cooking_time_minutes IS NULL OR cooking_time_minutes >= 0)
                AND (total_time_minutes IS NULL OR total_time_minutes >= 0)
            )');
            DB::statement('ALTER TABLE recipes ADD CONSTRAINT recipes_yield_people_positive CHECK (
                yield_people IS NULL OR yield_people > 0
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
