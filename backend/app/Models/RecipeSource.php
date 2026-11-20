<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeSource extends Model
{
    protected $table = 'recipe_sources';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'recipe_id',
        'source_name',
        'source_identifier',
        'source_record_identifier',
        'source_location',
        'source_uri',
        'attribution',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'recipe_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
