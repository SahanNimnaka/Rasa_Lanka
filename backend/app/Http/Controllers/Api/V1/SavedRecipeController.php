<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\IndexSavedRecipesRequest;
use App\Http\Requests\Api\V1\StoreSavedRecipeRequest;
use App\Http\Resources\Api\V1\SavedRecipeResource;
use App\Models\Recipe;
use App\Models\SavedRecipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedRecipeController extends Controller
{
    public function index(IndexSavedRecipesRequest $request): JsonResponse
    {
        $paginator = $request->user()
            ->savedRecipes()
            ->with('recipe.recipeCategory')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->validated('per_page', 15));

        return self::paginated($paginator, SavedRecipeResource::collection($paginator));
    }

    public function store(StoreSavedRecipeRequest $request): JsonResponse
    {
        $savedRecipe = SavedRecipe::firstOrCreate([
            'user_id' => $request->user()->id,
            'recipe_id' => $request->validated('recipe_id'),
        ]);

        $savedRecipe->load('recipe.recipeCategory');

        return self::success(
            new SavedRecipeResource($savedRecipe),
            [],
            $savedRecipe->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Recipe $recipe, Request $request): JsonResponse
    {
        $deleted = $request->user()
            ->savedRecipes()
            ->where('recipe_id', $recipe->id)
            ->delete();

        if ($deleted === 0) {
            return self::error('NOT_FOUND', 'Saved recipe not found.', 404);
        }

        return self::success(null, [
            'message' => 'Recipe removed from saved recipes.',
        ]);
    }
}
