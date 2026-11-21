import {
  useInfiniteQuery,
  useMutation,
  useQueryClient,
} from '@tanstack/react-query'
import type { ApiError } from '../../services/api'
import {
  listSavedRecipes,
  removeSavedRecipe,
  saveRecipe,
} from '../../services/api/savedRecipeApiService'
import {
  mapSavedRecipe,
  type SavedRecipeViewModel,
} from '../../services/mappers/savedRecipeMappers'
import { queryKeys } from '../../services/queries/queryKeys'
import type { ApiListResult } from '../../types/api'
import type { SavedRecipeDto } from '../../types/apiUserRecipes'

export function useSavedRecipes() {
  return useInfiniteQuery({
    queryKey: queryKeys.savedRecipes.lists(),
    queryFn: ({ pageParam }) => listSavedRecipes({ page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (lastPage) =>
      lastPage?.links.next ? lastPage.pagination.current_page + 1 : undefined,
    select: (data) => ({
      ...data,
      pages: data.pages.map((page) => ({
        ...page,
        data: page.data.map(mapSavedRecipe),
      })),
    }),
  })
}

export function useSaveRecipe() {
  const queryClient = useQueryClient()

  return useMutation<SavedRecipeDto, ApiError, number>({
    mutationFn: saveRecipe,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.savedRecipes.all(),
      })
    },
  })
}

export function useUnsaveRecipe() {
  const queryClient = useQueryClient()

  return useMutation<void, ApiError, number>({
    mutationFn: removeSavedRecipe,
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: queryKeys.savedRecipes.all(),
      })
    },
  })
}

export type SavedRecipesPage = ApiListResult<SavedRecipeViewModel>
