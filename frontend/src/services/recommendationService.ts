import type { Recipe, RecipeSortOption } from '../types/recipe'

export interface RecipeFilterOptions {
  highMatchOnly: boolean
  underThirtyMinutesOnly: boolean
}

export interface RecommendationOptions {
  servings: number
  sort: RecipeSortOption
  filters: RecipeFilterOptions
}

export const DEFAULT_SORT_OPTION: RecipeSortOption = 'best-match'

export const DEFAULT_RECIPE_FILTERS: RecipeFilterOptions = {
  highMatchOnly: false,
  underThirtyMinutesOnly: false,
}

function clampServings(servings: number): number {
  if (!Number.isFinite(servings)) {
    return 2
  }
  return Math.max(1, Math.min(20, Math.floor(servings)))
}

function compareRecipes(
  sort: RecipeSortOption,
): (a: Recipe, b: Recipe) => number {
  switch (sort) {
    case 'rating':
      return (a, b) => b.rating - a.rating
    case 'prep-time':
      return (a, b) => a.durationMinutes - b.durationMinutes
    case 'name':
      return (a, b) => a.name.localeCompare(b.name)
    case 'best-match':
    default:
      return (a, b) => b.matchPercent - a.matchPercent
  }
}

/**
 * Recommendation ranking is intentionally local and deterministic. It only
 * sorts and filters mock data until a real recommendation API is connected.
 */
export function getRecommendedRecipes(
  recipes: Recipe[],
  options: RecommendationOptions,
): Recipe[] {
  const servings = clampServings(options.servings)

  return recipes
    .filter((recipe) => {
      if (options.filters.highMatchOnly && recipe.matchPercent < 80) {
        return false
      }
      if (
        options.filters.underThirtyMinutesOnly &&
        recipe.durationMinutes >= 30
      ) {
        return false
      }
      return true
    })
    .sort(compareRecipes(options.sort))
    .map((recipe) => ({ ...recipe, servings }))
}
