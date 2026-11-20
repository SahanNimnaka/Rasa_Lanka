import { apiClient, toApiError } from '../api'
import type {
  ApiEnvelope,
  ApiListResult,
  PaginatedApiEnvelope,
} from '../../types/api'
import type {
  RecipeDetailDto,
  RecipeListParams,
  RecipeSummaryDto,
} from '../../types/apiRecipes'

export async function listRecipes(
  params: RecipeListParams = {},
): Promise<ApiListResult<RecipeSummaryDto>> {
  try {
    const response = await apiClient.get<
      PaginatedApiEnvelope<RecipeSummaryDto>
    >('/recipes', { params })

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

export async function getRecipe(id: number): Promise<RecipeDetailDto> {
  try {
    const response = await apiClient.get<ApiEnvelope<RecipeDetailDto>>(
      `/recipes/${id}`,
    )

    return response.data.data
  } catch (error) {
    throw toApiError(error)
  }
}
