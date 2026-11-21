import { use, useCallback } from 'react'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { AuthContext } from '../../context/authStore'
import { parseApiId } from '../../services/queries/queryKeys'
import { useSaveRecipe, useUnsaveRecipe } from './useSavedRecipes'

export function useToggleSavedRecipe() {
  const auth = use(AuthContext)
  const { favoriteRecipeIds, toggleFavorite } = useCookingFlow()
  const { mutateAsync: saveRecipe } = useSaveRecipe()
  const { mutateAsync: unsaveRecipe } = useUnsaveRecipe()

  return useCallback(
    async (recipeId: string) => {
      const shouldSave = !favoriteRecipeIds.includes(recipeId)
      toggleFavorite(recipeId)

      if (!auth?.isAuthenticated) {
        return
      }

      const numericId = parseApiId(recipeId)
      if (numericId === null) {
        return
      }

      try {
        if (shouldSave) {
          await saveRecipe(numericId)
        } else {
          await unsaveRecipe(numericId)
        }
      } catch {
        toggleFavorite(recipeId)
      }
    },
    [
      auth?.isAuthenticated,
      favoriteRecipeIds,
      saveRecipe,
      toggleFavorite,
      unsaveRecipe,
    ],
  )
}
