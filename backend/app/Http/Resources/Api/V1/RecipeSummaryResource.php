<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeSummaryResource extends JsonResource
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
            'recipe_category_id' => $this->recipe_category_id,
            'recipe_code' => $this->recipe_code,
            'name' => $this->name,
            'main_ingredient' => $this->main_ingredient,
            'recipe_type' => $this->recipe_type,
            'cooking_variation' => $this->cooking_variation,
            'region' => $this->region,
            'description' => $this->description,
            'preparation_time_minutes' => $this->preparation_time_minutes,
            'cooking_time_minutes' => $this->cooking_time_minutes,
            'total_time_minutes' => $this->total_time_minutes,
            'source_yield' => $this->source_yield,
            'yield_people' => $this->yield_people,
            'time_is_estimated' => $this->time_is_estimated,
            'yield_is_estimated' => $this->yield_is_estimated,
            'time_arithmetic_status' => $this->time_arithmetic_status,
            'recipe_category' => $this->whenLoaded('recipeCategory', fn () => new RecipeCategoryResource($this->recipeCategory)),
            'recipe_ingredients_count' => $this->whenCounted('recipeIngredients'),
            'recipe_instructions_count' => $this->whenCounted('recipeInstructions'),
            'recipe_sources_count' => $this->whenCounted('recipeSources'),
            'data_quality_issues_count' => $this->whenCounted('dataQualityIssues'),
        ];
    }
}
