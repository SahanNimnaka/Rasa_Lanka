<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeIngredientResource extends JsonResource
{
    /**
     * Responses are already wrapped by the API base controller.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipe_id' => $this->recipe_id,
            'ingredient_id' => $this->ingredient_id,
            'recipe' => $this->whenLoaded('recipe', function () {
                return [
                    'id' => $this->recipe->id,
                    'recipe_code' => $this->recipe->recipe_code,
                    'name' => $this->recipe->name,
                ];
            }),
            'ingredient' => $this->whenLoaded('ingredient', fn () => new IngredientResource($this->ingredient)),
            'source_identifier' => $this->source_identifier,
            'source_record_identifier' => $this->source_record_identifier,
            'original_ingredient_id' => $this->original_ingredient_id,
            'original_ingredient_name' => $this->original_ingredient_name,
            'original_quantity_text' => $this->original_quantity_text,
            'original_unit' => $this->original_unit,
            'per_person_quantity_min' => $this->per_person_quantity_min,
            'per_person_quantity_max' => $this->per_person_quantity_max,
            'per_person_quantity_description' => $this->per_person_quantity_description,
            'per_person_unit' => $this->per_person_unit,
            'expert_approved_quantity' => $this->expert_approved_quantity,
            'expert_approved_unit' => $this->expert_approved_unit,
            'approval_status' => $this->approval_status,
            'original_requirement_label' => $this->original_requirement_label,
            'ingredient_preparation' => $this->ingredient_preparation,
            'is_required' => $this->is_required,
            'is_main_ingredient' => $this->is_main_ingredient,
            'ingredient_notes' => $this->ingredient_notes,
            'data_quality_status' => $this->data_quality_status,
            'quantity_is_derived' => $this->quantity_is_derived,
            'quantity_description_is_estimated' => $this->quantity_description_is_estimated,
            'quantity_depends_on_estimated_yield' => $this->quantity_depends_on_estimated_yield,
        ];
    }
}
