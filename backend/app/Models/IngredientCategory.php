<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class IngredientCategory extends Model
{
    protected $table = 'ingredient_categories';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'name',
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

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_category', 'ingredient_category_id', 'ingredient_id')
            ->withPivot([
                'source_identifier',
                'source_record_identifier',
                'source_category_text',
            ])
            ->withTimestamps();
    }
}
