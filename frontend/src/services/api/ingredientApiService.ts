import { apiClient, toApiError } from '../api'
import type {
  ApiEnvelope,
  ApiListResult,
  PaginatedApiEnvelope,
} from '../../types/api'
import type {
  IngredientDetailDto,
  IngredientDto,
  IngredientListParams,
  IngredientShowParams,
} from '../../types/apiIngredients'

function serializeInclude(
  include: string | string[] | undefined,
): string | undefined {
  if (Array.isArray(include)) {
    return include.length > 0 ? include.join(',') : undefined
  }

  const trimmed = include?.trim()
  return trimmed ? trimmed : undefined
}

export async function listIngredients(
  params: IngredientListParams = {},
): Promise<ApiListResult<IngredientDto>> {
  try {
    const { include, ...rest } = params
    const serializedInclude = serializeInclude(include)

    const response = await apiClient.get<PaginatedApiEnvelope<IngredientDto>>(
      '/ingredients',
      {
        params: {
          ...rest,
          ...(serializedInclude ? { include: serializedInclude } : {}),
        },
      },
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

export async function getIngredient(
  id: number,
  params: IngredientShowParams = {},
): Promise<IngredientDetailDto> {
  try {
    const serializedInclude = serializeInclude(params.include)

    const response = await apiClient.get<ApiEnvelope<IngredientDetailDto>>(
      `/ingredients/${id}`,
      {
        params: serializedInclude ? { include: serializedInclude } : undefined,
      },
    )

    return response.data.data
  } catch (error) {
    throw toApiError(error)
  }
}
