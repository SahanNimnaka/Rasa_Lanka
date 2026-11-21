import { apiClient, bootstrapCsrf, toApiError } from '../api'
import type {
  ApiEnvelope,
  ApiListResult,
  PaginatedApiEnvelope,
} from '../../types/api'
import type {
  SavedRecipeDto,
  SavedRecipesListParams,
} from '../../types/apiUserRecipes'

export async function listSavedRecipes(
  params: SavedRecipesListParams = {},
): Promise<ApiListResult<SavedRecipeDto>> {
  try {
    const response = await apiClient.get<
      PaginatedApiEnvelope<SavedRecipeDto>
    >('/saved-recipes', { params })

    return {
      data: response.data.data,
      links: response.data.links,
      pagination: response.data.meta.pagination,
      meta: response.data.meta,
    }
  } catch (error) {
    throw toApiError(error)
  }
}

export async function saveRecipe(recipeId: number): Promise<SavedRecipeDto> {
  try {
    await bootstrapCsrf()
    const response = await apiClient.post<ApiEnvelope<SavedRecipeDto>>(
      '/saved-recipes',
      { recipe_id: recipeId },
    )

    return response.data.data
  } catch (error) {
    throw toApiError(error)
  }
}

export async function removeSavedRecipe(recipeId: number): Promise<void> {
  try {
    await bootstrapCsrf()
    await apiClient.delete(`/saved-recipes/${recipeId}`)
  } catch (error) {
    throw toApiError(error)
  }
}
