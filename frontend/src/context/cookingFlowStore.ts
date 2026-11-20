import { createContext, use } from 'react'
import {
  isMeasurementUnit,
  withDefaultQuantity,
} from '../services/quantityService'
import type {
  IngredientQuantity,
  IngredientWithQuantity,
  SelectedIngredient,
} from '../types/ingredients'

export const COOKING_FLOW_STORAGE_KEY = 'rasalanka.cooking-flow.v1'

export interface CookingFlowState {
  ingredients: IngredientWithQuantity[]
  servings: number
  selectedRecipeId?: string
  favoriteRecipeIds: string[]
  stepIndex: number
}

export interface CookingFlowActions {
  setIngredients: (
    ingredients: Array<SelectedIngredient | IngredientWithQuantity>,
  ) => void
  updateIngredientQuantity: (
    id: string,
    update: Partial<IngredientQuantity>,
  ) => void
  setServings: (servings: number) => void
  selectRecipe: (recipeId: string) => void
  toggleFavorite: (recipeId: string) => void
  setStepIndex: (index: number) => void
}

export type CookingFlowContextValue = CookingFlowState & CookingFlowActions

export const DEFAULT_COOKING_FLOW_STATE: CookingFlowState = {
  ingredients: [],
  servings: 2,
  favoriteRecipeIds: [],
  stepIndex: 0,
}

export const CookingFlowContext = createContext<CookingFlowContextValue | null>(
  null,
)

function isSelectedIngredient(value: unknown): value is SelectedIngredient {
  if (typeof value !== 'object' || value === null) {
    return false
  }
  const candidate = value as Partial<SelectedIngredient>
  return (
    typeof candidate.id === 'string' &&
    typeof candidate.name === 'string' &&
    (candidate.source === 'demo' || candidate.source === 'manual')
  )
}

function isIngredientWithQuantity(
  value: unknown,
): value is IngredientWithQuantity {
  if (!isSelectedIngredient(value)) {
    return false
  }
  const candidate = value as Partial<IngredientWithQuantity>
  return (
    typeof candidate.quantity === 'string' && isMeasurementUnit(candidate.unit)
  )
}

export function loadCookingFlowState(): CookingFlowState {
  if (typeof window === 'undefined') {
    return DEFAULT_COOKING_FLOW_STATE
  }

  try {
    const raw = window.sessionStorage.getItem(COOKING_FLOW_STORAGE_KEY)
    if (!raw) {
      return DEFAULT_COOKING_FLOW_STATE
    }
    const parsed = JSON.parse(raw) as Partial<CookingFlowState>

    return {
      ingredients: Array.isArray(parsed.ingredients)
        ? parsed.ingredients
            .filter(isIngredientWithQuantity)
            .map(withDefaultQuantity)
        : [],
      servings:
        typeof parsed.servings === 'number' &&
        Number.isFinite(parsed.servings) &&
        parsed.servings >= 1
          ? Math.floor(parsed.servings)
          : DEFAULT_COOKING_FLOW_STATE.servings,
      selectedRecipeId:
        typeof parsed.selectedRecipeId === 'string'
          ? parsed.selectedRecipeId
          : undefined,
      favoriteRecipeIds: Array.isArray(parsed.favoriteRecipeIds)
        ? parsed.favoriteRecipeIds.filter(
            (id): id is string => typeof id === 'string',
          )
        : [],
      stepIndex:
        typeof parsed.stepIndex === 'number' &&
        Number.isInteger(parsed.stepIndex) &&
        parsed.stepIndex >= 0
          ? parsed.stepIndex
          : 0,
    }
  } catch {
    return DEFAULT_COOKING_FLOW_STATE
  }
}

export function clampServings(servings: number): number {
  if (!Number.isFinite(servings)) {
    return DEFAULT_COOKING_FLOW_STATE.servings
  }
  return Math.max(1, Math.min(20, Math.floor(servings)))
}

export function useCookingFlow(): CookingFlowContextValue {
  const context = use(CookingFlowContext)
  if (!context) {
    throw new Error('useCookingFlow must be used inside CookingFlowProvider')
  }
  return context
}
