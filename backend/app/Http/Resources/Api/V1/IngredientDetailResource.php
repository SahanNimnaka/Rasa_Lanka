<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class IngredientDetailResource extends IngredientResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'recipe_ingredients' => RecipeIngredientResource::collection($this->whenLoaded('recipeIngredients')),
            'data_quality_issues' => DataQualityIssueResource::collection($this->whenLoaded('dataQualityIssues')),
        ]);
    }
}
