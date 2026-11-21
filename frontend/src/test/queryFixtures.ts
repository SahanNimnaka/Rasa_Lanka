import type { ApiEnvelope, PaginatedApiEnvelope } from '../types/api'
import type {
  IngredientCategoryDto,
  IngredientDetailDto,
  IngredientDto,
} from '../types/apiIngredients'
import type {
  RecipeCategoryDto,
  RecipeDetailDto,
  RecipeSummaryDto,
} from '../types/apiRecipes'
import type {
  RecipeViewDto,
  SavedRecipeDto,
} from '../types/apiUserRecipes'

export function itemEnvelope<T>(data: T): ApiEnvelope<T> {
  return {
    success: true,
    data,
    meta: { api_version: 'v1' },
  }
}

export function paginatedEnvelope<T>(data: T[]): PaginatedApiEnvelope<T> {
  return {
    success: true,
    data,
    links: {
      first: 'http://localhost/api/v1/recipes?page=1',
      last: null,
      prev: null,
      next: null,
    },
    meta: {
      api_version: 'v1',
      pagination: {
        current_page: 1,
        per_page: 15,
        total: data.length,
        last_page: 1,
        from: data.length > 0 ? 1 : null,
        to: data.length > 0 ? data.length : null,
      },
    },
  }
}

export const recipeCategoryDto: RecipeCategoryDto = {
  id: 5,
  name: 'Parippu',
  description: null,
  recipes_count: 2,
}

export const ingredientCategoryDto: IngredientCategoryDto = {
  id: 13,
  name: 'Spice',
  description: null,
  ingredients_count: 4,
}

export const recipeSummaryDto: RecipeSummaryDto = {
  id: 11,
  recipe_category_id: 5,
  recipe_code: 'PAR-001',
  name: 'Sri Lankan Parippu Curry',
  main_ingredient: 'Red lentils',
  recipe_type: 'Dhal curry',
  cooking_variation: 'Coconut milk and tempering',
  region: 'Sri Lanka',
  description: 'A coconut milk dhal curry.',
  preparation_time_minutes: 10,
  cooking_time_minutes: 25,
  total_time_minutes: 35,
  source_yield: '2.5',
  yield_people: '2.50',
  time_is_estimated: true,
  yield_is_estimated: true,
  time_arithmetic_status: 'MATCH',
  recipe_category: recipeCategoryDto,
  recipe_ingredients_count: 1,
  recipe_instructions_count: 1,
  recipe_sources_count: 0,
  data_quality_issues_count: 0,
}

export const recipeDetailDto: RecipeDetailDto = {
  ...recipeSummaryDto,
  recipe_ingredients: [
    {
      id: 67,
      recipe_id: 11,
      ingredient_id: 81,
      source_identifier: 'BOOK',
      source_record_identifier: 'PAR-001',
      original_ingredient_id: null,
      original_ingredient_name: 'Red lentils',
      original_quantity_text: '200 g',
      original_unit: 'g',
      per_person_quantity_min: '0.80000',
      per_person_quantity_max: '0.80000',
      per_person_quantity_description: 'Red lentils per person',
      per_person_unit: 'g',
      expert_approved_quantity: null,
      expert_approved_unit: null,
      approval_status: 'Pending expert validation',
      original_requirement_label: null,
      ingredient_preparation: null,
      is_required: true,
      is_main_ingredient: true,
      ingredient_notes: null,
      data_quality_status: null,
      quantity_is_derived: true,
      quantity_description_is_estimated: false,
      quantity_depends_on_estimated_yield: false,
    },
  ],
  method_summary: null,
  instructions: [
    {
      id: 2,
      recipe_id: 11,
      step_number: 1,
      is_method_summary: false,
      cooking_method_paragraph: null,
      cooking_steps_ordered: null,
      instruction_text: 'Simmer until tender.',
    },
  ],
  recipe_sources: [],
  data_quality_issues: [],
}

export const ingredientDto: IngredientDto = {
  id: 81,
  name: 'Red lentils (masoor dhal)',
  ingredient_key: 'red_lentils_masoor_dhal',
  description: null,
  categories: [ingredientCategoryDto],
  recipes_count: 3,
}

export const ingredientDetailDto: IngredientDetailDto = {
  ...ingredientDto,
}

export const savedRecipeDto: SavedRecipeDto = {
  id: 42,
  recipe_id: recipeSummaryDto.id,
  recipe: recipeSummaryDto,
  created_at: '2026-11-20T10:15:00+00:00',
  updated_at: '2026-11-20T10:15:00+00:00',
}

export const recipeViewDto: RecipeViewDto = {
  id: 77,
  recipe_id: recipeSummaryDto.id,
  recipe: recipeSummaryDto,
  viewed_at: '2026-11-21T08:30:00+00:00',
  created_at: '2026-11-21T08:30:00+00:00',
  updated_at: '2026-11-21T08:30:00+00:00',
}
