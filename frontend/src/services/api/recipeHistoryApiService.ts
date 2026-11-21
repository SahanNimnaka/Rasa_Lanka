import { apiClient, bootstrapCsrf, toApiError } from '../api'
import type {
  ApiEnvelope,
  ApiListResult,
  PaginatedApiEnvelope,
} from '../../types/api'
import type {
  RecipeHistoryListParams,
  RecipeViewDto,
} from '../../types/apiUserRecipes'

export async function listRecipeHistory(
  params: RecipeHistoryListParams = {},
): Promise<ApiListResult<RecipeViewDto>> {
  try {
    const response = await apiClient.get<PaginatedApiEnvelope<RecipeViewDto>>(
      '/recipe-history',
      { params },
    )

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

export async function recordRecipeView(
  recipeId: number,
): Promise<RecipeViewDto> {
  try {
    await bootstrapCsrf()
    const response = await apiClient.post<ApiEnvelope<RecipeViewDto>>(
      '/recipe-history',
      { recipe_id: recipeId },
    )

    return response.data.data
  } catch (error) {
    throw toApiError(error)
  }
}

export async function clearRecipeHistory(): Promise<void> {
  try {
    await bootstrapCsrf()
    await apiClient.delete('/recipe-history')
  } catch (error) {
    throw toApiError(error)
  }
}
