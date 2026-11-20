<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;

class RecipeDetailResource extends RecipeSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'recipe_ingredients' => RecipeIngredientResource::collection($this->whenLoaded('recipeIngredients')),
            'method_summary' => $this->whenLoaded('recipeInstructions', function () {
                $summary = $this->recipeInstructions->firstWhere('is_method_summary', true);

                return $summary ? new RecipeInstructionResource($summary) : null;
            }),
            'instructions' => $this->whenLoaded('recipeInstructions', function () {
                return RecipeInstructionResource::collection(
                    $this->recipeInstructions->where('is_method_summary', false)->values()
                );
            }),
            'recipe_sources' => RecipeSourceResource::collection($this->whenLoaded('recipeSources')),
            'data_quality_issues' => DataQualityIssueResource::collection($this->whenLoaded('dataQualityIssues')),
        ]);
    }
}
