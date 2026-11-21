import { clampServings } from './servingService'
import type { RecipeSortOption } from '../types/recipe'

export interface RecipeFilterOptions {
  highMatchOnly: boolean
  underThirtyMinutesOnly: boolean
}

export interface RecommendationOptions {
  servings: number
  sort: RecipeSortOption
  filters: RecipeFilterOptions
}

/**
 * The local ranking only needs these fields. API-backed candidates can supply
 * them without fabricating the rest of the legacy mock `Recipe` shape.
 */
export interface RecommendationCandidate {
  id: string
  name: string
  durationMinutes: number | null
  rating?: number | null
  matchPercent?: number | null
}

export const DEFAULT_SORT_OPTION: RecipeSortOption = 'best-match'

export const DEFAULT_RECIPE_FILTERS: RecipeFilterOptions = {
  highMatchOnly: false,
  underThirtyMinutesOnly: false,
}

function ratingOf(recipe: RecommendationCandidate): number {
  return recipe.rating ?? 0
}

function scoreOf(recipe: RecommendationCandidate): number {
  return recipe.matchPercent ?? 0
}

function durationOf(recipe: RecommendationCandidate): number {
  return recipe.durationMinutes ?? Number.MAX_SAFE_INTEGER
}

function compareRecipes<T extends RecommendationCandidate>(
  sort: RecipeSortOption,
): (a: T, b: T) => number {
  switch (sort) {
    case 'rating':
      return (a, b) => ratingOf(b) - ratingOf(a)
    case 'prep-time':
      return (a, b) => durationOf(a) - durationOf(b)
    case 'name':
      return (a, b) => a.name.localeCompare(b.name)
    case 'best-match':
    default:
      return (a, b) => scoreOf(b) - scoreOf(a)
  }
}

/**
 * Recommendation ranking remains local and deterministic. It sorts and filters
 * the supplied candidate catalogue; no backend recommendation endpoint is used.
 */
export function getRecommendedRecipes<T extends RecommendationCandidate>(
  recipes: T[],
  options: RecommendationOptions,
): Array<T & { servings: number }> {
  const servings = clampServings(options.servings)

  return recipes
    .filter((recipe) => {
      if (options.filters.highMatchOnly && scoreOf(recipe) < 80) {
        return false
      }
      if (
        options.filters.underThirtyMinutesOnly &&
        (recipe.durationMinutes === null ||
          recipe.durationMinutes >= 30)
      ) {
        return false
      }
      return true
    })
    .sort(compareRecipes(options.sort))
    .map((recipe) => ({ ...recipe, servings }))
}
