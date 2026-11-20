import type { RecipeSummaryViewModel } from '../services/mappers/recipeMappers'

export function recipeDurationLabel(
  minutes: number | null | undefined,
): string | null {
  return typeof minutes === 'number' && Number.isFinite(minutes)
    ? `${minutes} min`
    : null
}

export function recipeMetaLabel(
  recipe: Pick<
    RecipeSummaryViewModel,
    'recipeCategory' | 'region' | 'mainIngredient' | 'recipeType'
  >,
): string | null {
  return (
    recipe.recipeCategory?.name ??
    recipe.region ??
    recipe.mainIngredient ??
    recipe.recipeType ??
    null
  )
}
