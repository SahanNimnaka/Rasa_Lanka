<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotation_record_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('annotation_record_id')->index();
            $table->string('image_id', 32)->index();
            $table->string('relative_path', 512)->index();
            $table->string('original_folder', 255)->index();
            $table->string('original_filename', 255);
            $table->string('membership_role', 16)->index();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['annotation_record_id', 'image_id']);
            $table->foreign('annotation_record_id')
                ->references('id')
                ->on('annotation_records')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotation_record_images');
    }
};
