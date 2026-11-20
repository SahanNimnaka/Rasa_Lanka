import { skipToken, useQueries, useQuery } from '@tanstack/react-query'
import type { ApiError } from '../../services/api'
import { getRecipe, listRecipes } from '../../services/api/recipeApiService'
import {
  mapRecipeDetail,
  mapRecipeSummary,
  type RecipeDetailViewModel,
  type RecipeSummaryViewModel,
} from '../../services/mappers/recipeMappers'
import { parseApiId, queryKeys } from '../../services/queries/queryKeys'
import type { ApiListResult } from '../../types/api'
import type {
  RecipeDetailDto,
  RecipeListParams,
  RecipeSummaryDto,
} from '../../types/apiRecipes'

export type RecipeIdInput = number | string | null | undefined

export function useRecipes(params: RecipeListParams = {}) {
  return useQuery<
    ApiListResult<RecipeSummaryDto>,
    ApiError,
    ApiListResult<RecipeSummaryViewModel>
  >({
    queryKey: queryKeys.recipes.list(params),
    queryFn: () => listRecipes(params),
    select: (result) => ({
      ...result,
      data: result.data.map(mapRecipeSummary),
    }),
  })
}

export function useRecipe(id: RecipeIdInput) {
  const recipeId = parseApiId(id)

  return useQuery<RecipeDetailDto, ApiError, RecipeDetailViewModel>({
    queryKey:
      recipeId === null
        ? queryKeys.recipes.invalidDetail()
        : queryKeys.recipes.detail(recipeId),
    queryFn: recipeId === null ? skipToken : () => getRecipe(recipeId),
    enabled: recipeId !== null,
    select: mapRecipeDetail,
  })
}

export interface RecipeDetailsResult {
  data: Map<string, RecipeDetailViewModel>
  isPending: boolean
  isFetching: boolean
  isError: boolean
}

/** Loads detail records for an API-backed candidate list using shared query keys. */
export function useRecipeDetails(ids: RecipeIdInput[]): RecipeDetailsResult {
  const recipeIds = [
    ...new Set(
      ids
        .map((id) => parseApiId(id))
        .filter((id): id is number => id !== null),
    ),
  ]

  const results = useQueries({
    queries: recipeIds.map((id) => ({
      queryKey: queryKeys.recipes.detail(id),
      queryFn: () => getRecipe(id),
      select: mapRecipeDetail,
    })),
  })

  const details = new Map<string, RecipeDetailViewModel>()
  for (const result of results) {
    if (result.data) {
      details.set(result.data.id, result.data)
    }
  }

  return {
    data: details,
    isPending: results.some((result) => result.isPending),
    isFetching: results.some((result) => result.isFetching),
    isError: results.some((result) => result.isError),
  }
}
