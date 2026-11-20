<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')
                ->constrained('ingredients')
                ->cascadeOnDelete();
            $table->foreignId('ingredient_category_id')
                ->constrained('ingredient_categories')
                ->cascadeOnDelete();
            $table->string('source_identifier', 160)->nullable();
            $table->string('source_record_identifier', 160)->nullable();
            $table->string('source_category_text', 200)->nullable();
            $table->timestamps();

            $table->index(['ingredient_id', 'ingredient_category_id']);
            $table->index(['source_identifier', 'source_record_identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_category');
    }
};
