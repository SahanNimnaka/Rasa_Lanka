import { useQuery } from '@tanstack/react-query'
import type { ApiError } from '../../services/api'
import {
  listIngredientCategories,
  listRecipeCategories,
} from '../../services/api/categoryApiService'
import {
  mapIngredientCategory,
  type IngredientCategoryViewModel,
} from '../../services/mappers/ingredientMappers'
import {
  mapRecipeCategory,
  type RecipeCategoryViewModel,
} from '../../services/mappers/recipeMappers'
import { queryKeys } from '../../services/queries/queryKeys'
import type { ApiListResult } from '../../types/api'
import type {
  IngredientCategoryDto,
  IngredientCategoryListParams,
  RecipeCategoryListParams,
} from '../../types/apiIngredients'
import type { RecipeCategoryDto } from '../../types/apiRecipes'

export function useRecipeCategories(params: RecipeCategoryListParams = {}) {
  return useQuery<
    ApiListResult<RecipeCategoryDto>,
    ApiError,
    ApiListResult<RecipeCategoryViewModel>
  >({
    queryKey: queryKeys.recipeCategories.list(params),
    queryFn: () => listRecipeCategories(params),
    select: (result) => ({
      ...result,
      data: result.data.map(mapRecipeCategory),
    }),
  })
}

export function useIngredientCategories(
  params: IngredientCategoryListParams = {},
) {
  return useQuery<
    ApiListResult<IngredientCategoryDto>,
    ApiError,
    ApiListResult<IngredientCategoryViewModel>
  >({
    queryKey: queryKeys.ingredientCategories.list(params),
    queryFn: () => listIngredientCategories(params),
    select: (result) => ({
      ...result,
      data: result.data.map(mapIngredientCategory),
    }),
  })
}
