import type { IngredientDto } from './apiIngredients'

export interface RecipeCategoryDto {
  id: number
  name: string
  description: string | null
  recipes_count?: number
}

export interface RecipeSummaryDto {
  id: number
  recipe_category_id: number | null
  recipe_code: string | null
  name: string
  main_ingredient: string | null
  recipe_type: string | null
  cooking_variation: string | null
  region: string | null
  description: string | null
  preparation_time_minutes: number | null
  cooking_time_minutes: number | null
  total_time_minutes: number | null
  source_yield: string | null
  yield_people: string | null
  time_is_estimated: boolean
  yield_is_estimated: boolean
  time_arithmetic_status: string | null
  recipe_category?: RecipeCategoryDto | null
  recipe_ingredients_count?: number
  recipe_instructions_count?: number
  recipe_sources_count?: number
  data_quality_issues_count?: number
}

export interface RecipeSourceDto {
  id: number
  source_name: string | null
  source_identifier: string | null
  source_record_identifier: string | null
  source_location: string | null
  source_uri: string | null
  attribution: string | null
  notes: string | null
}

export interface DataQualityIssueDto {
  id: number
  source_file: string | null
  source_row: number | null
  entity_type: string | null
  entity_reference: string | null
  field_name: string | null
  severity: string | null
  issue_type: string | null
  description: string | null
  recommended_action: string | null
  resolution_status: string | null
  recipe_id: number | null
  ingredient_id: number | null
}

export interface RecipeInstructionDto {
  id: number
  recipe_id: number
  step_number: number
  is_method_summary: boolean
  cooking_method_paragraph: string | null
  cooking_steps_ordered: string | null
  instruction_text: string | null
}

export interface RecipeIngredientRecipeDto {
  id: number
  recipe_code: string | null
  name: string
}

export type RecipeIngredientApprovalStatus = string | null

export interface RecipeIngredientDto {
  id: number
  recipe_id: number
  ingredient_id: number | null
  recipe?: RecipeIngredientRecipeDto
  ingredient?: IngredientDto
  source_identifier: string | null
  source_record_identifier: string | null
  original_ingredient_id: string | null
  original_ingredient_name: string | null
  original_quantity_text: string | null
  original_unit: string | null
  per_person_quantity_min: string | null
  per_person_quantity_max: string | null
  per_person_quantity_description: string | null
  per_person_unit: string | null
  expert_approved_quantity: string | null
  expert_approved_unit: string | null
  approval_status: RecipeIngredientApprovalStatus
  original_requirement_label: string | null
  ingredient_preparation: string | null
  is_required: boolean | null
  is_main_ingredient: boolean | null
  ingredient_notes: string | null
  data_quality_status: string | null
  quantity_is_derived: boolean
  quantity_description_is_estimated: boolean
  quantity_depends_on_estimated_yield: boolean
}

export interface RecipeDetailDto extends RecipeSummaryDto {
  recipe_ingredients: RecipeIngredientDto[]
  method_summary: RecipeInstructionDto | null
  instructions: RecipeInstructionDto[]
  recipe_sources: RecipeSourceDto[]
  data_quality_issues: DataQualityIssueDto[]
}

export interface RecipeListParams {
  page?: number
  per_page?: number
  search?: string
  recipe_category_id?: number
  ingredient_id?: number
  recipe_type?: string
  region?: string
  sort?: 'name' | 'created_at' | 'id'
}
