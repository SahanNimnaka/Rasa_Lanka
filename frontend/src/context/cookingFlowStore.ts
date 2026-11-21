import { createContext, use } from 'react'
import { normalizeUnit } from '../services/quantityService'
import { parseApiId } from '../services/queries/queryKeys'
import {
  clampServings,
  DEFAULT_SERVINGS,
} from '../services/servingService'
import type {
  IngredientQuantity,
  IngredientWithQuantity,
  SelectedIngredient,
} from '../types/ingredients'

export { clampServings } from '../services/servingService'

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
  servings: DEFAULT_SERVINGS,
  favoriteRecipeIds: [],
  stepIndex: 0,
}

export const CookingFlowContext = createContext<CookingFlowContextValue | null>(
  null,
)

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function isIngredientWithQuantity(value: unknown): value is IngredientWithQuantity {
  if (!isRecord(value)) {
    return false
  }

  return (
    typeof value.id === 'string' &&
    parseApiId(value.id) !== null &&
    typeof value.name === 'string' &&
    value.name.trim().length > 0 &&
    (value.source === 'demo' ||
      value.source === 'manual' ||
      value.source === 'catalogue') &&
    typeof value.quantity === 'string' &&
    typeof value.unit === 'string'
  )
}

function normalizeIngredient(
  value: IngredientWithQuantity,
): IngredientWithQuantity {
  const numericId = parseApiId(value.id)
  if (numericId === null) {
    throw new Error('Expected a numeric catalogue ingredient ID')
  }

  return {
    ...value,
    id: String(numericId),
    name: value.name.trim(),
    // Persisted selections must refer to real catalogue entries. Demo IDs and
    // legacy generated IDs are discarded when the flow is loaded.
    source: 'catalogue',
    unit: normalizeUnit(value.unit),
  }
}

function dedupeIngredients(
  ingredients: IngredientWithQuantity[],
): IngredientWithQuantity[] {
  const seen = new Set<string>()
  return ingredients.filter((ingredient) => {
    if (seen.has(ingredient.id)) {
      return false
    }
    seen.add(ingredient.id)
    return true
  })
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

    const ingredients = Array.isArray(parsed.ingredients)
      ? dedupeIngredients(
          parsed.ingredients
            .filter(isIngredientWithQuantity)
            .map(normalizeIngredient),
        )
      : []

    return {
      ingredients,
      servings:
        typeof parsed.servings === 'number'
          ? clampServings(parsed.servings)
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

export function useCookingFlow(): CookingFlowContextValue {
  const context = use(CookingFlowContext)
  if (!context) {
    throw new Error('useCookingFlow must be used inside CookingFlowProvider')
  }
  return context
}
