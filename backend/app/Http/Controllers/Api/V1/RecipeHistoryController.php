<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IndexRecipeHistoryRequest;
use App\Http\Requests\Api\V1\StoreRecipeViewRequest;
use App\Http\Resources\Api\V1\RecipeViewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecipeHistoryController extends Controller
{
    public function index(IndexRecipeHistoryRequest $request): JsonResponse
    {
        $paginator = $request->user()
            ->recipeViews()
            ->with('recipe.recipeCategory')
            ->orderByDesc('viewed_at')
            ->orderByDesc('id')
            ->paginate($request->validated('per_page', 15));

        return self::paginated($paginator, RecipeViewResource::collection($paginator));
    }

    public function store(StoreRecipeViewRequest $request): JsonResponse
    {
        $view = $request->user()->recipeViews()->create([
            'recipe_id' => $request->validated('recipe_id'),
            'viewed_at' => now(),
        ]);

        $view->load('recipe.recipeCategory');

        return self::success(new RecipeViewResource($view), [], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $deleted = $request->user()->recipeViews()->delete();

        return self::success(['deleted_count' => $deleted], [
            'message' => 'Recipe history cleared.',
        ]);
    }
}
