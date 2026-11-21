import type { RecipeSummaryDto } from './apiRecipes'

export interface SavedRecipeDto {
  id: number
  recipe_id: number
  recipe: RecipeSummaryDto
  created_at: string
  updated_at: string
}

export interface RecipeViewDto {
  id: number
  recipe_id: number
  recipe: RecipeSummaryDto
  viewed_at: string
  created_at: string
  updated_at: string
}

export interface SavedRecipesListParams {
  page?: number
  per_page?: number
}

export interface RecipeHistoryListParams {
  page?: number
  per_page?: number
}
