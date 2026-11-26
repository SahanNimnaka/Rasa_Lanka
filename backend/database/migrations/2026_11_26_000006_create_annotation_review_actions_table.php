<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotation_review_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('annotation_record_id')->index();
            $table->foreignId('actor_user_id')->nullable()->index();
            $table->string('actor_type', 16)->default('user')->index();
            $table->string('action', 64)->index();
            $table->string('from_workflow_status', 32)->nullable()->index();
            $table->string('to_workflow_status', 32)->nullable()->index();
            $table->text('comment')->nullable();
            $table->string('evidence_ref', 512)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->index();

            $table->foreign('annotation_record_id')
                ->references('id')
                ->on('annotation_records')
                ->restrictOnDelete();
            $table->foreign('actor_user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_review_actions');
    }
};
