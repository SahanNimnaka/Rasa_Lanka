<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('ingredient_key', 180)->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX ingredients_normalized_name_unique ON ingredients (lower(btrim(regexp_replace(name, '[[:space:]]+', ' ', 'g'))))");
            DB::statement("ALTER TABLE ingredients ADD CONSTRAINT ingredients_name_not_blank CHECK (length(btrim(regexp_replace(name, '[[:space:]]+', ' ', 'g'))) > 0)");
        } else {
            Schema::table('ingredients', function (Blueprint $blueprint) {
                $blueprint->unique('name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
