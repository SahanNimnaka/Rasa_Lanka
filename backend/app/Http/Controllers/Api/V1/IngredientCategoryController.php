<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IndexIngredientCategoriesRequest;
use App\Http\Resources\Api\V1\IngredientCategoryResource;
use App\Models\IngredientCategory;
use Illuminate\Http\JsonResponse;

class IngredientCategoryController extends Controller
{
    public function index(IndexIngredientCategoriesRequest $request): JsonResponse
    {
        $search = $request->validated('search');
        $include = $request->validated('include', []);

        $query = IngredientCategory::query()
            ->when(is_string($search) && $search !== '', function ($query) use ($search) {
                $term = '%'.strtolower($search).'%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ?", [$term]);
                });
            })
            ->orderBy('name')
            ->orderBy('id');

        if (in_array('ingredients_count', $include, true)) {
            $query->withCount('ingredients');
        }

        $categories = $query->paginate($request->validated('per_page', 15));

        return self::paginated($categories, IngredientCategoryResource::collection($categories));
    }
}
