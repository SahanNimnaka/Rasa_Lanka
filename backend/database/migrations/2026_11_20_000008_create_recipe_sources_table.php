<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')
                ->constrained('recipes')
                ->cascadeOnDelete();
            $table->string('source_name', 255);
            $table->string('source_identifier', 160)->nullable();
            $table->string('source_record_identifier', 160)->nullable();
            $table->string('source_location', 255)->nullable();
            $table->text('source_uri')->nullable();
            $table->text('attribution')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['source_identifier', 'source_record_identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_sources');
    }
};
