import {
  useInfiniteQuery,
  useMutation,
  useQueryClient,
} from '@tanstack/react-query'
import type { ApiError } from '../../services/api'
import {
  clearRecipeHistory,
  listRecipeHistory,
  recordRecipeView,
} from '../../services/api/recipeHistoryApiService'
import {
  mapRecipeView,
  type RecipeViewViewModel,
} from '../../services/mappers/savedRecipeMappers'
import { queryKeys } from '../../services/queries/queryKeys'
import type { ApiListResult } from '../../types/api'
import type { RecipeViewDto } from '../../types/apiUserRecipes'

export function useRecipeHistory() {
  return useInfiniteQuery({
    queryKey: queryKeys.recipeHistory.lists(),
    queryFn: ({ pageParam }) => listRecipeHistory({ page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (lastPage) =>
      lastPage?.links.next ? lastPage.pagination.current_page + 1 : undefined,
    select: (data) => ({
      ...data,
      pages: data.pages.map((page) => ({
        ...page,
        data: page.data.map(mapRecipeView),
      })),
    }),
  })
}

export function useRecordRecipeView() {
  const queryClient = useQueryClient()

  return useMutation<RecipeViewDto, ApiError, number>({
    mutationFn: recordRecipeView,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.recipeHistory.all(),
      })
    },
  })
}

export function useClearRecipeHistory() {
  const queryClient = useQueryClient()

  return useMutation<void, ApiError, void>({
    mutationFn: clearRecipeHistory,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.recipeHistory.all(),
      })
    },
  })
}

export type RecipeHistoryPage = ApiListResult<RecipeViewViewModel>
