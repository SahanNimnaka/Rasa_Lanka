<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataQualityIssue extends Model
{
    protected $table = 'data_quality_issues';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'source_file',
        'source_row',
        'entity_type',
        'entity_reference',
        'field_name',
        'severity',
        'issue_type',
        'description',
        'recommended_action',
        'resolution_status',
        'recipe_id',
        'ingredient_id',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'source_row' => 'integer',
            'recipe_id' => 'integer',
            'ingredient_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
