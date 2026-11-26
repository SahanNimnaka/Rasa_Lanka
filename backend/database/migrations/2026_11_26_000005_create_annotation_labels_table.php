<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotation_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('annotation_record_id')->index();
            $table->string('label_array', 32)->index();
            $table->string('raw_label', 255)->index();
            $table->string('resolved_label', 255)->nullable()->index();
            $table->string('label_class', 32)->nullable()->index();
            $table->boolean('is_pending_mapping')->default(false)->index();
            $table->boolean('is_alias_resolved')->default(false)->index();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['annotation_record_id', 'label_array', 'raw_label']);
            $table->foreign('annotation_record_id')
                ->references('id')
                ->on('annotation_records')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_labels');
    }
};
