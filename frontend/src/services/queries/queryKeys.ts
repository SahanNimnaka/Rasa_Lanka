import type {
  IngredientCategoryListParams,
  IngredientListParams,
  IngredientShowParams,
  RecipeCategoryListParams,
} from '../../types/apiIngredients'
import type { RecipeListParams } from '../../types/apiRecipes'
import type {
  RecipeHistoryListParams,
  SavedRecipesListParams,
} from '../../types/apiUserRecipes'

type SerializableParamValue =
  | string
  | number
  | boolean
  | readonly string[]
  | readonly number[]

type NormalizedParams = Record<string, SerializableParamValue>

function normalizeValue(value: unknown): SerializableParamValue | undefined {
  if (
    typeof value === 'string' ||
    typeof value === 'number' ||
    typeof value === 'boolean'
  ) {
    return value
  }

  if (Array.isArray(value)) {
    return value
      .filter(
        (item): item is string | number =>
          typeof item === 'string' || typeof item === 'number',
      )
      .map(String)
      .sort()
  }

  return undefined
}

function normalizeParams<T extends object>(params: T | undefined): NormalizedParams {
  if (!params) {
    return {}
  }

  const entries = Object.entries(params as Record<string, unknown>)
    .filter(([, value]) => value !== undefined && value !== null)
    .map(([key, value]) => [key, normalizeValue(value)] as const)
    .filter(
      (entry): entry is readonly [string, SerializableParamValue] =>
        entry[1] !== undefined,
    )
    .sort(([first], [second]) => first.localeCompare(second))

  return Object.fromEntries(entries)
}

export function parseApiId(value: unknown): number | null {
  if (typeof value === 'number') {
    return Number.isSafeInteger(value) && value > 0 ? value : null
  }

  if (typeof value === 'string') {
    const trimmed = value.trim()
    if (/^\d+$/.test(trimmed)) {
      const parsed = Number(trimmed)
      return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null
    }
  }

  return null
}

export const queryKeys = {
  all: ['rasalanka'] as const,

  recipeCategories: {
    all: () => [...queryKeys.all, 'recipe-categories'] as const,
    lists: () => [...queryKeys.recipeCategories.all(), 'list'] as const,
    list: (params: RecipeCategoryListParams = {}) =>
      [...queryKeys.recipeCategories.lists(), normalizeParams(params)] as const,
  },

  ingredientCategories: {
    all: () => [...queryKeys.all, 'ingredient-categories'] as const,
    lists: () =>
      [...queryKeys.ingredientCategories.all(), 'list'] as const,
    list: (params: IngredientCategoryListParams = {}) =>
      [...queryKeys.ingredientCategories.lists(), normalizeParams(params)] as const,
  },

  recipes: {
    all: () => [...queryKeys.all, 'recipes'] as const,
    lists: () => [...queryKeys.recipes.all(), 'list'] as const,
    list: (params: RecipeListParams = {}) =>
      [...queryKeys.recipes.lists(), normalizeParams(params)] as const,
    details: () => [...queryKeys.recipes.all(), 'detail'] as const,
    detail: (id: number) =>
      [...queryKeys.recipes.details(), { id }] as const,
    invalidDetail: () => [...queryKeys.recipes.details(), 'invalid'] as const,
  },

  savedRecipes: {
    all: () => [...queryKeys.all, 'saved-recipes'] as const,
    lists: () => [...queryKeys.savedRecipes.all(), 'list'] as const,
    list: (params: SavedRecipesListParams = {}) =>
      [...queryKeys.savedRecipes.lists(), normalizeParams(params)] as const,
  },

  recipeHistory: {
    all: () => [...queryKeys.all, 'recipe-history'] as const,
    lists: () => [...queryKeys.recipeHistory.all(), 'list'] as const,
    list: (params: RecipeHistoryListParams = {}) =>
      [...queryKeys.recipeHistory.lists(), normalizeParams(params)] as const,
  },

  ingredients: {
    all: () => [...queryKeys.all, 'ingredients'] as const,
    lists: () => [...queryKeys.ingredients.all(), 'list'] as const,
    list: (params: IngredientListParams = {}) =>
      [...queryKeys.ingredients.lists(), normalizeParams(params)] as const,
    details: () => [...queryKeys.ingredients.all(), 'detail'] as const,
    detail: (id: number, params: IngredientShowParams = {}) =>
      [...queryKeys.ingredients.details(), { id, params: normalizeParams(params) }] as const,
    invalidDetail: (params: IngredientShowParams = {}) =>
      [...queryKeys.ingredients.details(), 'invalid', normalizeParams(params)] as const,
  },
}
