import type {
  DataQualityIssueDto,
  RecipeIngredientDto,
} from './apiRecipes'

export interface IngredientCategoryProvenanceDto {
  source_identifier: string | null
  source_record_identifier: string | null
  source_category_text: string | null
}

export interface IngredientCategoryDto {
  id: number
  name: string
  description: string | null
  ingredients_count?: number
  provenance?: IngredientCategoryProvenanceDto
}

export interface IngredientDto {
  id: number
  name: string
  ingredient_key: string | null
  description: string | null
  categories?: IngredientCategoryDto[]
  recipes_count?: number
}

export interface IngredientDetailDto extends IngredientDto {
  recipe_ingredients?: RecipeIngredientDto[]
  data_quality_issues?: DataQualityIssueDto[]
}

export interface IngredientListParams {
  page?: number
  per_page?: number
  search?: string
  ingredient_category_id?: number
  include?: string | string[]
}

export interface IngredientShowParams {
  include?: string | string[]
}

export interface IngredientCategoryListParams {
  page?: number
  per_page?: number
  search?: string
  include?: string | string[]
}

export interface RecipeCategoryListParams {
  page?: number
  per_page?: number
  search?: string
  include?: string | string[]
}
