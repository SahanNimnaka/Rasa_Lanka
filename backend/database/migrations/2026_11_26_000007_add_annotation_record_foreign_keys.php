<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annotation_records', function (Blueprint $table): void {
            $table->foreign('latest_validation_run_id')
                ->references('id')
                ->on('annotation_validation_runs')
                ->nullOnDelete();
            $table->foreign('supersedes_record_id')
                ->references('record_id')
                ->on('annotation_records')
                ->restrictOnDelete();
            $table->foreign('superseded_by_record_id')
                ->references('record_id')
                ->on('annotation_records')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('annotation_records', function (Blueprint $table): void {
            $table->dropForeign(['latest_validation_run_id']);
            $table->dropForeign(['supersedes_record_id']);
            $table->dropForeign(['superseded_by_record_id']);
        });
    }
};
