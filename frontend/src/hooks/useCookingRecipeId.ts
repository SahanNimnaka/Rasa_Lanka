import { useEffect, useRef } from 'react'
import {
  useCookingFlow,
  type CookingFlowContextValue,
} from '../context/cookingFlowStore'
import { parseApiId } from '../services/queries/queryKeys'

export interface CookingRecipeIdResult {
  canonicalId: string | null
  flow: CookingFlowContextValue
  hasValidId: boolean
  numericId: number | null
}

/**
 * Syncs the route recipe with the cooking flow without resetting progress on
 * refetches. A genuine recipe change resets the stored step index.
 */
export function useCookingRecipeId(
  recipeId: string | undefined,
): CookingRecipeIdResult {
  const flow = useCookingFlow()
  const { selectRecipe, selectedRecipeId } = flow
  const numericId = parseApiId(recipeId)
  const canonicalId = numericId === null ? null : String(numericId)
  const previousIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (canonicalId === null) {
      previousIdRef.current = null
      return
    }

    const changedRecipe =
      previousIdRef.current !== null && previousIdRef.current !== canonicalId

    if (changedRecipe || selectedRecipeId !== canonicalId) {
      selectRecipe(canonicalId)
    }

    previousIdRef.current = canonicalId
  }, [canonicalId, selectRecipe, selectedRecipeId])

  return {
    canonicalId,
    flow,
    hasValidId: numericId !== null,
    numericId,
  }
}
