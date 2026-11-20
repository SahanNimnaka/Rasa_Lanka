import { apiClient, toApiError } from '../api'
import type {
  ApiListResult,
  PaginatedApiEnvelope,
} from '../../types/api'
import type {
  IngredientCategoryDto,
  IngredientCategoryListParams,
  RecipeCategoryListParams,
} from '../../types/apiIngredients'
import type { RecipeCategoryDto } from '../../types/apiRecipes'

function serializeInclude(
  include: string | string[] | undefined,
): string | undefined {
  if (Array.isArray(include)) {
    return include.length > 0 ? include.join(',') : undefined
  }

  const trimmed = include?.trim()
  return trimmed ? trimmed : undefined
}

export async function listRecipeCategories(
  params: RecipeCategoryListParams = {},
): Promise<ApiListResult<RecipeCategoryDto>> {
  try {
    const { include, ...rest } = params
    const serializedInclude = serializeInclude(include)

    const response = await apiClient.get<
      PaginatedApiEnvelope<RecipeCategoryDto>
    >('/recipe-categories', {
      params: {
        ...rest,
        ...(serializedInclude ? { include: serializedInclude } : {}),
      },
    })

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

export async function listIngredientCategories(
  params: IngredientCategoryListParams = {},
): Promise<ApiListResult<IngredientCategoryDto>> {
  try {
    const { include, ...rest } = params
    const serializedInclude = serializeInclude(include)

    const response = await apiClient.get<
      PaginatedApiEnvelope<IngredientCategoryDto>
    >('/ingredient-categories', {
      params: {
        ...rest,
        ...(serializedInclude ? { include: serializedInclude } : {}),
      },
    })

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
