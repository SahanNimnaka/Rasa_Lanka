<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IndexRecipeCategoriesRequest;
use App\Http\Resources\Api\V1\RecipeCategoryResource;
use App\Models\RecipeCategory;
use Illuminate\Http\JsonResponse;

class RecipeCategoryController extends Controller
{
    public function index(IndexRecipeCategoriesRequest $request): JsonResponse
    {
        $search = $request->validated('search');
        $include = $request->validated('include', []);

        $query = RecipeCategory::query()
            ->when(is_string($search) && $search !== '', function ($query) use ($search) {
                $term = '%'.strtolower($search).'%';

                $query->where(function ($query) use ($term) {
                    $query->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ?", [$term]);
                });
            })
            ->orderBy('name')
            ->orderBy('id');

        if (in_array('recipes_count', $include, true)) {
            $query->withCount('recipes');
        }

        $categories = $query->paginate($request->validated('per_page', 15));

        return self::paginated($categories, RecipeCategoryResource::collection($categories));
    }
}
