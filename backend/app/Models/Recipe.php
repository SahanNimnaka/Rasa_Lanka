<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $table = 'recipes';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'recipe_category_id',
        'recipe_code',
        'name',
        'main_ingredient',
        'recipe_type',
        'cooking_variation',
        'region',
        'description',
        'preparation_time_minutes',
        'cooking_time_minutes',
        'total_time_minutes',
        'preparation_time_source',
        'cooking_time_source',
        'total_time_source',
        'source_yield',
        'yield_people',
        'time_is_estimated',
        'yield_is_estimated',
        'time_arithmetic_status',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'recipe_category_id' => 'integer',
            'preparation_time_minutes' => 'integer',
            'cooking_time_minutes' => 'integer',
            'total_time_minutes' => 'integer',
            'yield_people' => 'decimal:2',
            'time_is_estimated' => 'boolean',
            'yield_is_estimated' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function recipeCategory(): BelongsTo
    {
        return $this->belongsTo(RecipeCategory::class);
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'recipe_ingredients', 'recipe_id', 'ingredient_id')
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

    public function recipeInstructions(): HasMany
    {
        return $this->hasMany(RecipeInstruction::class)->orderBy('step_number');
    }

    public function recipeSources(): HasMany
    {
        return $this->hasMany(RecipeSource::class);
    }

    public function dataQualityIssues(): HasMany
    {
        return $this->hasMany(DataQualityIssue::class);
    }
}
