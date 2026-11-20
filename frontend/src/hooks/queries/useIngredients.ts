import { skipToken, useQuery } from '@tanstack/react-query'
import type { ApiError } from '../../services/api'
import {
  getIngredient,
  listIngredients,
} from '../../services/api/ingredientApiService'
import {
  mapIngredient,
  mapIngredientDetail,
  type IngredientDetailViewModel,
  type IngredientViewModel,
} from '../../services/mappers/ingredientMappers'
import { parseApiId, queryKeys } from '../../services/queries/queryKeys'
import type { ApiListResult } from '../../types/api'
import type {
  IngredientDetailDto,
  IngredientDto,
  IngredientListParams,
  IngredientShowParams,
} from '../../types/apiIngredients'

export type IngredientIdInput = number | string | null | undefined

export function useIngredients(params: IngredientListParams = {}) {
  return useQuery<
    ApiListResult<IngredientDto>,
    ApiError,
    ApiListResult<IngredientViewModel>
  >({
    queryKey: queryKeys.ingredients.list(params),
    queryFn: () => listIngredients(params),
    select: (result) => ({
      ...result,
      data: result.data.map(mapIngredient),
    }),
  })
}

export function useIngredient(
  id: IngredientIdInput,
  params: IngredientShowParams = {},
) {
  const ingredientId = parseApiId(id)

  return useQuery<
    IngredientDetailDto,
    ApiError,
    IngredientDetailViewModel
  >({
    queryKey:
      ingredientId === null
        ? queryKeys.ingredients.invalidDetail(params)
        : queryKeys.ingredients.detail(ingredientId, params),
    queryFn:
      ingredientId === null ? skipToken : () => getIngredient(ingredientId, params),
    enabled: ingredientId !== null,
    select: mapIngredientDetail,
  })
}
