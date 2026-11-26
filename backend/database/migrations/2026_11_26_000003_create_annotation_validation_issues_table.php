<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotation_validation_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('validation_run_id')->index();
            $table->string('record_id', 64)->nullable()->index();
            $table->string('severity', 16)->index();
            $table->string('code', 64)->index();
            $table->string('field', 255)->nullable()->index();
            $table->text('message');
            $table->json('details_json')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->foreign('validation_run_id')
                ->references('id')
                ->on('annotation_validation_runs')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_validation_issues');
    }
};
