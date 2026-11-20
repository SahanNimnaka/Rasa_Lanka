import { getRecipeCatalog } from './mockRecipeService'
import type { SearchFilterState } from '../types/appState'
import type { Recipe } from '../types/recipe'

function normalize(value: string): string {
  return value.trim().toLowerCase()
}

function ingredientNames(recipe: Recipe): string[] {
  return [...recipe.availableIngredients, ...recipe.missingIngredients].map(
    (ingredient) => ingredient.name,
  )
}

function matchesIngredient(recipe: Recipe, selected: string): boolean {
  const wanted = normalize(selected)
  if (!wanted) {
    return true
  }
  return ingredientNames(recipe).some((name) => {
    const candidate = normalize(name)
    return (
      candidate === wanted ||
      candidate.includes(wanted) ||
      wanted.includes(candidate)
    )
  })
}

function matchesCookingTime(
  recipe: Recipe,
  filter: SearchFilterState['cookingTime'],
): boolean {
  switch (filter) {
    case 'under-15':
      return recipe.durationMinutes < 15
    case '15-30':
      return recipe.durationMinutes >= 15 && recipe.durationMinutes < 30
    case '30-plus':
      return recipe.durationMinutes >= 30
    case 'all':
    default:
      return true
  }
}

/** Local, deterministic filtering over the shared mock recipe catalog. */
export function filterRecipesBySearch(
  recipes: Recipe[],
  filters: SearchFilterState,
): Recipe[] {
  const query = normalize(filters.query)

  return recipes
    .filter((recipe) => {
      if (filters.cuisine !== 'all' && recipe.cuisine !== filters.cuisine) {
        return false
      }
      if (!matchesCookingTime(recipe, filters.cookingTime)) {
        return false
      }
      if (
        filters.ingredients.some(
          (ingredient) => !matchesIngredient(recipe, ingredient),
        )
      ) {
        return false
      }
      if (!query) {
        return true
      }
      return (
        normalize(recipe.name).includes(query) ||
        ingredientNames(recipe).some((name) => normalize(name).includes(query))
      )
    })
    .sort(
      (a, b) => b.matchPercent - a.matchPercent || a.name.localeCompare(b.name),
    )
}

export function getSearchIngredientOptions(): string[] {
  const names = new Set<string>()
  for (const recipe of getRecipeCatalog()) {
    for (const name of ingredientNames(recipe)) {
      names.add(name)
    }
  }
  return [...names].sort((a, b) => a.localeCompare(b))
}
