import type {
  IngredientCategoryDto,
  IngredientDetailDto,
  IngredientDto,
} from '../../types/apiIngredients'
import {
  mapDataQualityIssue,
  mapIngredientCategory,
  mapRecipeIngredient,
  type DataQualityIssueViewModel,
  type IngredientCategoryViewModel,
  type RecipeIngredientViewModel,
} from './recipeMappers'

export type {
  IngredientCategoryProvenanceViewModel,
  IngredientCategoryViewModel,
} from './recipeMappers'
export { mapIngredientCategory } from './recipeMappers'

export interface IngredientViewModel {
  id: string
  name: string
  ingredientKey: string | null
  description: string | null
  categories?: IngredientCategoryViewModel[]
  recipesCount?: number
}

export interface IngredientDetailViewModel extends IngredientViewModel {
  recipeIngredients?: RecipeIngredientViewModel[]
  dataQualityIssues?: DataQualityIssueViewModel[]
}

function toViewId(id: number): string {
  return String(id)
}

function mapCategory(
  dto: IngredientCategoryDto,
): IngredientCategoryViewModel {
  return mapIngredientCategory(dto)
}

export function mapIngredient(dto: IngredientDto): IngredientViewModel {
  return {
    id: toViewId(dto.id),
    name: dto.name,
    ingredientKey: dto.ingredient_key,
    description: dto.description,
    ...(dto.categories === undefined
      ? {}
      : { categories: dto.categories.map(mapCategory) }),
    ...(dto.recipes_count === undefined
      ? {}
      : { recipesCount: dto.recipes_count }),
  }
}

export function mapIngredientDetail(
  dto: IngredientDetailDto,
): IngredientDetailViewModel {
  return {
    ...mapIngredient(dto),
    ...(dto.recipe_ingredients === undefined
      ? {}
      : {
          recipeIngredients: dto.recipe_ingredients.map(mapRecipeIngredient),
        }),
    ...(dto.data_quality_issues === undefined
      ? {}
      : {
          dataQualityIssues: dto.data_quality_issues.map(mapDataQualityIssue),
        }),
  }
}
