<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Controller as ApiController;
use App\Http\Controllers\Api\V1\IngredientCategoryController;
use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\RecipeCategoryController;
use App\Http\Controllers\Api\V1\RecipeController;
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
});

Route::fallback(function () {
    return ApiController::error(
        'NOT_FOUND',
        'The requested API resource was not found.',
        404
    );
})->name('fallback');
