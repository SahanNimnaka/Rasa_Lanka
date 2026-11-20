<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeIngredient extends Model
{
    protected $table = 'recipe_ingredients';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'recipe_id',
        'ingredient_id',
        'source_identifier',
        'source_record_identifier',
        'original_ingredient_id',
        'original_ingredient_name',
        'original_quantity_text',
        'original_unit',
        'per_person_quantity_min',
        'per_person_quantity_max',
        'per_person_quantity_description',
        'per_person_unit',
        'expert_approved_quantity',
        'expert_approved_unit',
        'approval_status',
        'original_requirement_label',
        'ingredient_preparation',
        'is_required',
        'is_main_ingredient',
        'ingredient_notes',
        'data_quality_status',
        'quantity_is_derived',
        'quantity_description_is_estimated',
        'quantity_depends_on_estimated_yield',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'recipe_id' => 'integer',
            'ingredient_id' => 'integer',
            'per_person_quantity_min' => 'decimal:5',
            'per_person_quantity_max' => 'decimal:5',
            'expert_approved_quantity' => 'decimal:5',
            'is_required' => 'boolean',
            'is_main_ingredient' => 'boolean',
            'quantity_is_derived' => 'boolean',
            'quantity_description_is_estimated' => 'boolean',
            'quantity_depends_on_estimated_yield' => 'boolean',
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
