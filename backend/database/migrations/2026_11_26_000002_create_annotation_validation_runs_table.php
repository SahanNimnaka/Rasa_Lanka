<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotation_validation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 64)->unique();
            $table->foreignId('annotation_record_id')->index();
            $table->string('submission_intent_id', 64)->nullable()->index();
            $table->string('batch_run_id', 64)->nullable()->index();
            $table->string('b1_version', 128)->nullable()->index();
            $table->string('input_artifact_path', 1024);
            $table->char('input_artifact_sha256', 64)->index();
            $table->string('input_evidence_state', 32)->default('staged_path')->index();
            $table->string('durable_input_evidence_path', 1024)->nullable();
            $table->char('durable_input_evidence_sha256', 64)->nullable()->index();
            $table->string('report_json_path', 1024)->nullable();
            $table->char('report_json_sha256', 64)->nullable()->index();
            $table->string('report_csv_path', 1024)->nullable();
            $table->integer('exit_code')->nullable()->index();
            $table->boolean('is_valid')->nullable()->index();
            $table->boolean('is_export_eligible_canonical')->nullable()->index();
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('export_block_count')->default(0);
            $table->string('status', 32)->index();
            $table->string('infra_error_code', 64)->nullable()->index();
            $table->string('infra_error_message', 512)->nullable();
            $table->boolean('timed_out')->default(false)->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('executed_by_user_id')->nullable()->index();
            $table->timestamp('executed_at')->index();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->foreign('annotation_record_id')
                ->references('id')
                ->on('annotation_records')
                ->restrictOnDelete();
            $table->foreign('executed_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_validation_runs');
    }
};
