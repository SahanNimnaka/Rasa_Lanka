<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\B1RuntimeHealthController;
use App\Http\Controllers\Api\V1\B1ValidationController;
use App\Http\Controllers\Api\V1\Controller as ApiController;
use App\Http\Controllers\Api\V1\IngredientCategoryController;
use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\RecipeCategoryController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\RecipeHistoryController;
use App\Http\Controllers\Api\V1\SavedRecipeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->as('v1.')->group(function () {
    Route::get('/health', function () {
        return ApiController::success([
            'status' => 'ok',
            'checked_at' => now()->toIso8601String(),
        ]);
    })->name('health');

    Route::get('/recipe-categories', [RecipeCategoryController::class, 'index'])
        ->name('recipe-categories.index');

    Route::get('/ingredient-categories', [IngredientCategoryController::class, 'index'])
        ->name('ingredient-categories.index');

    Route::get('/ingredients', [IngredientController::class, 'index'])
        ->name('ingredients.index');

    Route::get('/ingredients/{ingredient}', [IngredientController::class, 'show'])
        ->name('ingredients.show');

    Route::get('/recipes', [RecipeController::class, 'index'])
        ->name('recipes.index');

    Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])
        ->name('recipes.show');

    Route::prefix('auth')->as('auth.')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:register')
            ->name('register');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/saved-recipes', [SavedRecipeController::class, 'index'])
            ->name('saved-recipes.index');
        Route::post('/saved-recipes', [SavedRecipeController::class, 'store'])
            ->name('saved-recipes.store');
        Route::delete('/saved-recipes/{recipe}', [SavedRecipeController::class, 'destroy'])
            ->name('saved-recipes.destroy');

        Route::get('/recipe-history', [RecipeHistoryController::class, 'index'])
            ->name('recipe-history.index');
        Route::post('/recipe-history', [RecipeHistoryController::class, 'store'])
            ->name('recipe-history.store');
        Route::delete('/recipe-history', [RecipeHistoryController::class, 'destroy'])
            ->name('recipe-history.destroy');

        Route::prefix('annotations')->as('annotations.')->group(function () {
            Route::post('/validate', [B1ValidationController::class, 'validate'])
                ->middleware('can:annotation.validate')
                ->name('validate');
            Route::get('/runtime-health', [B1RuntimeHealthController::class, 'show'])
                ->name('runtime-health');
        });
    });
});

Route::fallback(function () {
    return ApiController::error(
        'NOT_FOUND',
        'The requested API resource was not found.',
        404
    );
})->name('fallback');
