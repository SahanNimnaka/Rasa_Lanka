<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->string('source_file', 255);
            $table->unsignedInteger('source_row');
            $table->string('entity_type', 32)->nullable();
            $table->text('entity_reference')->nullable();
            $table->text('field_name')->nullable();
            $table->text('severity');
            $table->text('issue_type');
            $table->text('description');
            $table->text('recommended_action');
            $table->string('resolution_status', 32)->default('unresolved');
            $table->foreignId('recipe_id')
                ->nullable()
                ->constrained('recipes')
                ->nullOnDelete();
            $table->foreignId('ingredient_id')
                ->nullable()
                ->constrained('ingredients')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['source_file', 'source_row']);
            $table->index(['resolution_status', 'severity']);
            $table->index(['entity_type', 'entity_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_quality_issues');
    }
};
