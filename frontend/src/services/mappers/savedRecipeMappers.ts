import type {
  RecipeViewDto,
  SavedRecipeDto,
} from '../../types/apiUserRecipes'
import {
  mapRecipeSummary,
  type RecipeSummaryViewModel,
} from './recipeMappers'

export interface SavedRecipeViewModel {
  id: string
  recipeId: string
  recipe: RecipeSummaryViewModel
  savedAt: string
  updatedAt: string
}

export interface RecipeViewViewModel {
  id: string
  recipeId: string
  recipe: RecipeSummaryViewModel
  viewedAt: string
  createdAt: string
  updatedAt: string
}

export function mapSavedRecipe(dto: SavedRecipeDto): SavedRecipeViewModel {
  return {
    id: String(dto.id),
    recipeId: String(dto.recipe_id),
    recipe: mapRecipeSummary(dto.recipe),
    savedAt: dto.created_at,
    updatedAt: dto.updated_at,
  }
}

export function mapRecipeView(dto: RecipeViewDto): RecipeViewViewModel {
  return {
    id: String(dto.id),
    recipeId: String(dto.recipe_id),
    recipe: mapRecipeSummary(dto.recipe),
    viewedAt: dto.viewed_at,
    createdAt: dto.created_at,
    updatedAt: dto.updated_at,
  }
}
