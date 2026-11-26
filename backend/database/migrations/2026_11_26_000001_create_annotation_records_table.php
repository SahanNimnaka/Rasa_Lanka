<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotation_records', function (Blueprint $table): void {
            $table->id();
            $table->string('record_id', 64)->unique();
            $table->string('schema_version', 32)->default('human-recognition-v1')->index();
            $table->string('recognition_method', 16)->index();
            $table->string('verification_status', 32)->index();
            $table->string('workflow_status', 32)->default('draft')->index();
            $table->string('artifact_state', 32)->default('draft')->index();
            $table->string('current_draft_version_id', 64)->nullable()->index();
            $table->string('current_draft_path', 1024)->nullable();
            $table->char('current_draft_sha256', 64)->nullable()->index();
            $table->string('submitted_json_path', 1024)->nullable()->unique();
            $table->char('submitted_artifact_sha256', 64)->nullable()->index();
            $table->string('submission_intent_id', 64)->nullable()->index();
            $table->string('submission_state', 32)->default('none')->index();
            $table->string('submission_staging_path', 1024)->nullable();
            $table->char('submission_staging_sha256', 64)->nullable()->index();
            $table->timestamp('submission_started_at')->nullable()->index();
            $table->timestamp('submission_finalized_at')->nullable()->index();
            $table->string('supersedes_record_id', 64)->nullable()->index();
            $table->string('superseded_by_record_id', 64)->nullable()->index();
            $table->foreignId('created_by_user_id')->index();
            $table->foreignId('submitted_by_user_id')->nullable()->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->index();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->boolean('is_export_eligible_canonical')->default(false)->index();
            $table->unsignedBigInteger('latest_validation_run_id')->nullable()->index();
            $table->boolean('latest_b1_valid')->nullable()->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('discarded_at')->nullable()->index();
            $table->string('discard_reason', 255)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('submitted_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_records');
    }
};
