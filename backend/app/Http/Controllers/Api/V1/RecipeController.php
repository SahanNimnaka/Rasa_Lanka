<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IndexRecipesRequest;
use App\Http\Requests\Api\V1\ShowRecipeRequest;
use App\Http\Resources\Api\V1\RecipeDetailResource;
use App\Http\Resources\Api\V1\RecipeSummaryResource;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;

class RecipeController extends Controller
{
    public function index(IndexRecipesRequest $request): JsonResponse
    {
        $search = $request->validated('search');
        $categoryId = $request->validated('recipe_category_id');
        $ingredientId = $request->validated('ingredient_id');
        $recipeType = $request->validated('recipe_type');
        $region = $request->validated('region');
        $sort = $request->validated('sort', 'id');

        $query = Recipe::query()
            ->with('recipeCategory')
            ->withCount([
                'recipeIngredients',
                'recipeInstructions',
                'recipeSources',
                'dataQualityIssues',
            ])
            ->when(is_string($search) && $search !== '', function ($query) use ($search) {
                $term = '%'.strtolower($search).'%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw("LOWER(COALESCE(recipe_code, '')) LIKE ?", [$term])
                        ->orWhereRaw("LOWER(COALESCE(main_ingredient, '')) LIKE ?", [$term])
                        ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ?", [$term])
                        ->orWhereRaw("LOWER(COALESCE(recipe_type, '')) LIKE ?", [$term])
                        ->orWhereRaw("LOWER(COALESCE(region, '')) LIKE ?", [$term]);
                });
            })
            ->when($categoryId, fn ($query) => $query->where('recipe_category_id', $categoryId))
            ->when($ingredientId, function ($query) use ($ingredientId) {
                $query->whereHas('ingredients', function ($query) use ($ingredientId) {
                    $query->where('ingredients.id', $ingredientId);
                });
            })
            ->when(is_string($recipeType) && $recipeType !== '', fn ($query) => $query->where('recipe_type', $recipeType))
            ->when(is_string($region) && $region !== '', fn ($query) => $query->where('region', $region))
            ->orderBy($sort);

        if ($sort !== 'id') {
            $query->orderBy('id');
        }

        $recipes = $query->paginate($request->validated('per_page', 15));

        return self::paginated($recipes, RecipeSummaryResource::collection($recipes));
    }

    public function show(ShowRecipeRequest $request, Recipe $recipe): JsonResponse
    {
        $recipe->load([
            'recipeCategory',
            'recipeIngredients' => fn ($query) => $query
                ->with('ingredient')
                ->orderBy('recipe_ingredients.id'),
            'recipeInstructions' => fn ($query) => $query->orderBy('step_number'),
            'recipeSources' => fn ($query) => $query->orderBy('recipe_sources.id'),
            'dataQualityIssues' => fn ($query) => $query->orderBy('data_quality_issues.id'),
        ]);

        return self::success(new RecipeDetailResource($recipe));
    }
}
