<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    protected $table = 'ingredients';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'name',
        'ingredient_key',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_ingredients', 'ingredient_id', 'recipe_id')
            ->withPivot([
                'id',
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
            ])
            ->withTimestamps();
    }

    public function ingredientCategories(): BelongsToMany
    {
        return $this->belongsToMany(IngredientCategory::class, 'ingredient_category', 'ingredient_id', 'ingredient_category_id')
            ->withPivot([
                'source_identifier',
                'source_record_identifier',
                'source_category_text',
            ])
            ->withTimestamps();
    }

    public function dataQualityIssues(): HasMany
    {
        return $this->hasMany(DataQualityIssue::class);
    }
}
