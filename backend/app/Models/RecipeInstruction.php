<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeInstruction extends Model
{
    protected $table = 'recipe_instructions';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'recipe_id',
        'step_number',
        'is_method_summary',
        'cooking_method_paragraph',
        'cooking_steps_ordered',
        'instruction_text',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'recipe_id' => 'integer',
            'step_number' => 'integer',
            'is_method_summary' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
