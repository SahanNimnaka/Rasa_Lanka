<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IndexIngredientsRequest;
use App\Http\Requests\Api\V1\ShowIngredientRequest;
use App\Http\Resources\Api\V1\IngredientDetailResource;
use App\Http\Resources\Api\V1\IngredientResource;
use App\Models\Ingredient;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\JsonResponse;

class IngredientController extends Controller
{
    public function index(IndexIngredientsRequest $request): JsonResponse
    {
        $search = $request->validated('search');
        $categoryId = $request->validated('ingredient_category_id');
        $include = $request->validated('include', []);

        $query = Ingredient::query()
            ->when(is_string($search) && $search !== '', function ($query) use ($search) {
                $term = '%'.strtolower($search).'%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(ingredient_key) LIKE ?', [$term])
                        ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ?", [$term]);
                });
            })
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->whereHas('ingredientCategories', function ($query) use ($categoryId) {
                    $query->where('ingredient_categories.id', $categoryId);
                });
            })
            ->orderBy('name')
            ->orderBy('id');

        if (in_array('categories', $include, true)) {
            $query->with([
                'ingredientCategories' => fn ($query) => $query
                    ->orderBy('ingredient_categories.name')
                    ->orderBy('ingredient_categories.id'),
            ]);
        }

        if (in_array('recipes_count', $include, true)) {
            $query->withAggregate(
                ['recipes as recipes_count'],
                new Expression('DISTINCT recipes.id'),
                'count'
            );
        }

        $ingredients = $query->paginate($request->validated('per_page', 15));

        return self::paginated($ingredients, IngredientResource::collection($ingredients));
    }

    public function show(ShowIngredientRequest $request, Ingredient $ingredient): JsonResponse
    {
        $include = $request->validated('include', []);

        $ingredient->load([
            'ingredientCategories' => fn ($query) => $query
                ->orderBy('ingredient_categories.name')
                ->orderBy('ingredient_categories.id'),
            'recipeIngredients' => fn ($query) => $query
                ->with('recipe')
                ->orderBy('recipe_ingredients.id'),
        ]);

        if (in_array('data_quality_issues', $include, true)) {
            $ingredient->load([
                'dataQualityIssues' => fn ($query) => $query->orderBy('data_quality_issues.id'),
            ]);
        }

        return self::success(new IngredientDetailResource($ingredient));
    }
}
