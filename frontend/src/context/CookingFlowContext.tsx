import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { withDefaultQuantity } from '../services/quantityService'
import {
  MEASUREMENT_UNITS,
  type IngredientQuantity,
  type IngredientWithQuantity,
  type SelectedIngredient,
} from '../types/ingredients'
import {
  clampServings,
  CookingFlowContext,
  COOKING_FLOW_STORAGE_KEY,
  loadCookingFlowState,
  type CookingFlowContextValue,
  type CookingFlowState,
} from './cookingFlowStore'

export function CookingFlowProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<CookingFlowState>(loadCookingFlowState)

  useEffect(() => {
    try {
      window.sessionStorage.setItem(
        COOKING_FLOW_STORAGE_KEY,
        JSON.stringify(state),
      )
    } catch {
      // Storage can be unavailable in private browsing. In-memory state still
      // works for the current page session.
    }
  }, [state])

  const setIngredients = useCallback(
    (ingredients: Array<SelectedIngredient | IngredientWithQuantity>) => {
      setState((current) => ({
        ...current,
        ingredients: ingredients.map(withDefaultQuantity),
        stepIndex: 0,
      }))
    },
    [],
  )

  const updateIngredientQuantity = useCallback(
    (id: string, update: Partial<IngredientQuantity>) => {
      setState((current) => ({
        ...current,
        ingredients: current.ingredients.map((ingredient) =>
          ingredient.id === id
            ? {
                ...ingredient,
                quantity:
                  update.quantity === undefined
                    ? ingredient.quantity
                    : String(update.quantity),
                unit:
                  update.unit && MEASUREMENT_UNITS.includes(update.unit)
                    ? update.unit
                    : ingredient.unit,
              }
            : ingredient,
        ),
      }))
    },
    [],
  )

  const setServings = useCallback((servings: number) => {
    setState((current) => ({
      ...current,
      servings: clampServings(servings),
    }))
  }, [])

  const selectRecipe = useCallback((recipeId: string) => {
    setState((current) => ({
      ...current,
      selectedRecipeId: recipeId,
      stepIndex: 0,
    }))
  }, [])

  const toggleFavorite = useCallback((recipeId: string) => {
    setState((current) => ({
      ...current,
      favoriteRecipeIds: current.favoriteRecipeIds.includes(recipeId)
        ? current.favoriteRecipeIds.filter((id) => id !== recipeId)
        : [...current.favoriteRecipeIds, recipeId],
    }))
  }, [])

  const setStepIndex = useCallback((index: number) => {
    setState((current) => ({
      ...current,
      stepIndex: Math.max(0, Number.isInteger(index) ? index : 0),
    }))
  }, [])

  const value = useMemo<CookingFlowContextValue>(
    () => ({
      ...state,
      setIngredients,
      updateIngredientQuantity,
      setServings,
      selectRecipe,
      toggleFavorite,
      setStepIndex,
    }),
    [
      state,
      setIngredients,
      updateIngredientQuantity,
      setServings,
      selectRecipe,
      toggleFavorite,
      setStepIndex,
    ],
  )

  return <CookingFlowContext value={value}>{children}</CookingFlowContext>
}
