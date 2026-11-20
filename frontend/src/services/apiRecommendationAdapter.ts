import type {
  RecipeDetailViewModel,
  RecipeSummaryViewModel,
} from './mappers/recipeMappers'
import type { RecommendationCandidate } from './recommendationService'

export interface ApiRecommendationCandidate extends RecommendationCandidate {
  summary: RecipeSummaryViewModel
  detail?: RecipeDetailViewModel
  ingredientNames: string[]
  matchedIngredientNames: string[]
}

function normalizeIngredientName(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/\([^)]*\)/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

function uniqueNormalized(values: Array<string | null | undefined>): string[] {
  const names = new Set<string>()
  for (const value of values) {
    if (!value) {
      continue
    }
    const normalized = normalizeIngredientName(value)
    if (normalized) {
      names.add(normalized)
    }
  }
  return [...names]
}

function detailIngredientNames(detail: RecipeDetailViewModel): string[] {
  return uniqueNormalized(
    detail.recipeIngredients.flatMap((item) => [
      item.ingredient?.name ?? null,
      item.originalIngredientName,
    ]),
  )
}

function namesMatch(selected: string, recipeIngredient: string): boolean {
  return (
    selected === recipeIngredient ||
    selected.includes(recipeIngredient) ||
    recipeIngredient.includes(selected)
  )
}

function overlapScore(
  selectedNames: string[],
  recipeIngredientNames: string[],
): number {
  if (selectedNames.length === 0 || recipeIngredientNames.length === 0) {
    return 0
  }

  const matched = recipeIngredientNames.filter((ingredient) =>
    selectedNames.some((selected) => namesMatch(selected, ingredient)),
  )

  return Math.round((matched.length / recipeIngredientNames.length) * 100)
}

export function createApiRecommendationCandidates(
  summaries: RecipeSummaryViewModel[],
  details: Map<string, RecipeDetailViewModel>,
  selectedIngredientNames: string[],
): ApiRecommendationCandidate[] {
  const selectedNames = uniqueNormalized(selectedIngredientNames)

  return summaries.map((summary) => {
    const detail = details.get(summary.id)
    const ingredientNames = detail ? detailIngredientNames(detail) : []
    const matchedIngredientNames = ingredientNames.filter((ingredient) =>
      selectedNames.some((selected) => namesMatch(selected, ingredient)),
    )

    return {
      id: summary.id,
      name: summary.name,
      durationMinutes: summary.durationMinutes,
      matchPercent: overlapScore(selectedNames, ingredientNames),
      summary,
      ...(detail ? { detail } : {}),
      ingredientNames,
      matchedIngredientNames,
    }
  })
}
