// @vitest-environment node
import { describe, expect, it } from 'vitest'
import type {
  RecipeDetailDto,
  RecipeIngredientDto,
  RecipeSummaryDto,
} from '../../types/apiRecipes'
import {
  mapRecipeDetail,
  mapRecipeIngredient,
  mapRecipeSummary,
} from './recipeMappers'

const summaryDto: RecipeSummaryDto = {
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
  recipe_category: {
    id: 5,
    name: 'Parippu',
    description: null,
    recipes_count: 2,
  },
  recipe_ingredients_count: 12,
  recipe_instructions_count: 3,
  recipe_sources_count: 1,
  data_quality_issues_count: 1,
}

const ingredientDto: RecipeIngredientDto = {
  id: 67,
  recipe_id: 11,
  ingredient_id: 81,
  ingredient: {
    id: 81,
    name: 'Red lentils (masoor dhal)',
    ingredient_key: 'red_lentils_masoor_dhal',
    description: null,
    categories: [
      {
        id: 7,
        name: 'Main ingredient',
        description: null,
        provenance: {
          source_identifier: 'BOOK',
          source_record_identifier: 'PAR-001',
          source_category_text: 'Main ingredient',
        },
      },
    ],
    recipes_count: 3,
  },
  source_identifier: 'BOOK',
  source_record_identifier: 'PAR-001',
  original_ingredient_id: 'ING-81',
  original_ingredient_name: 'Red lentils',
  original_quantity_text: '2 medium',
  original_unit: 'whole',
  per_person_quantity_min: '0.80000',
  per_person_quantity_max: '0.80000',
  per_person_quantity_description: '2 medium lentils per person',
  per_person_unit: 'medium',
  expert_approved_quantity: null,
  expert_approved_unit: null,
  approval_status: 'Pending expert validation',
  original_requirement_label: 'Main',
  ingredient_preparation: 'Rinsed',
  is_required: true,
  is_main_ingredient: true,
  ingredient_notes: null,
  data_quality_status: 'REVIEW',
  quantity_is_derived: true,
  quantity_description_is_estimated: true,
  quantity_depends_on_estimated_yield: true,
}

const detailDto: RecipeDetailDto = {
  ...summaryDto,
  recipe_ingredients: [ingredientDto],
  method_summary: {
    id: 1,
    recipe_id: 11,
    step_number: 0,
    is_method_summary: true,
    cooking_method_paragraph: 'Temper the spices, then simmer the lentils.',
    cooking_steps_ordered: 'Temper spices\nSimmer lentils',
    instruction_text: null,
  },
  instructions: [
    {
      id: 2,
      recipe_id: 11,
      step_number: 3,
      is_method_summary: false,
      cooking_method_paragraph: null,
      cooking_steps_ordered: null,
      instruction_text: 'Serve with rice.',
    },
  ],
  recipe_sources: [
    {
      id: 1,
      source_name: 'Recipe book',
      source_identifier: 'BOOK',
      source_record_identifier: 'PAR-001',
      source_location: 'Page 20',
      source_uri: null,
      attribution: null,
      notes: null,
    },
  ],
  data_quality_issues: [
    {
      id: 1,
      source_file: 'recipes.csv',
      source_row: 11,
      entity_type: 'recipe',
      entity_reference: 'PAR-001',
      field_name: 'total_time_minutes',
      severity: 'warning',
      issue_type: 'ESTIMATED_TIME',
      description: 'Time is estimated.',
      recommended_action: 'Verify against source.',
      resolution_status: 'open',
      recipe_id: 11,
      ingredient_id: null,
    },
  ],
}

describe('recipe mappers', () => {
  it('maps numeric IDs, decimal strings and duration consistently', () => {
    const mapped = mapRecipeSummary(summaryDto)

    expect(mapped.id).toBe('11')
    expect(mapped.recipeCategoryId).toBe('5')
    expect(mapped.recipeCategory?.id).toBe('5')
    expect(mapped.yieldPeople).toBe('2.50')
    expect(mapped.sourceYield).toBe('2.5')
    expect(mapped.durationMinutes).toBe(35)
    expect(mapped.timeIsEstimated).toBe(true)
    expect(mapped.yieldIsEstimated).toBe(true)
    expect(mapped.timeArithmeticStatus).toBe('MATCH')
    expect(mapped).not.toHaveProperty('image')
    expect(mapped).not.toHaveProperty('imageAlt')
    expect(mapped).not.toHaveProperty('rating')
    expect(mapped).not.toHaveProperty('matchPercent')
  })

  it('maps recipe ingredients without losing quantity provenance', () => {
    const mapped = mapRecipeIngredient(ingredientDto)

    expect(mapped.id).toBe('67')
    expect(mapped.recipeId).toBe('11')
    expect(mapped.ingredientId).toBe('81')
    expect(mapped.originalQuantityText).toBe('2 medium')
    expect(mapped.originalUnit).toBe('whole')
    expect(mapped.perPersonQuantityMin).toBe('0.80000')
    expect(mapped.perPersonQuantityMax).toBe('0.80000')
    expect(mapped.perPersonQuantityDescription).toBe(
      '2 medium lentils per person',
    )
    expect(mapped.perPersonUnit).toBe('medium')
    expect(mapped.originalUnit).toBe('whole')
    expect(mapped.approvalStatus).toBe('Pending expert validation')
    expect(mapped.expertApprovedQuantity).toBeNull()
    expect(mapped.expertApprovedUnit).toBeNull()
    expect(mapped.isExpertApproved).toBe(false)
    expect(mapped.quantityIsDerived).toBe(true)
    expect(mapped.quantityDescriptionIsEstimated).toBe(true)
    expect(mapped.quantityDependsOnEstimatedYield).toBe(true)
    expect(mapped.ingredient?.categories?.[0]?.provenance).toEqual({
      sourceIdentifier: 'BOOK',
      sourceRecordIdentifier: 'PAR-001',
      sourceCategoryText: 'Main ingredient',
    })
    expect(mapped).not.toHaveProperty('available')
    expect(mapped).not.toHaveProperty('unit')
  })

  it('never presents a derived quantity as expert-approved', () => {
    const mapped = mapRecipeIngredient({
      ...ingredientDto,
      approval_status: 'approved',
      expert_approved_quantity: '1.00000',
      expert_approved_unit: 'cup',
    })

    expect(mapped.expertApprovedQuantity).toBe('1.00000')
    expect(mapped.expertApprovedUnit).toBe('cup')
    expect(mapped.approvalStatus).toBe('approved')
    expect(mapped.isExpertApproved).toBe(false)
  })

  it('maps step zero separately from numbered instructions', () => {
    const mapped = mapRecipeDetail(detailDto)

    expect(mapped.methodSummary).toMatchObject({
      id: '1',
      recipeId: '11',
      stepNumber: 0,
      isMethodSummary: true,
      cookingMethodParagraph: 'Temper the spices, then simmer the lentils.',
    })
    expect(mapped.instructions).toHaveLength(1)
    expect(mapped.instructions[0]).toMatchObject({
      id: '2',
      stepNumber: 3,
      isMethodSummary: false,
      instructionText: 'Serve with rice.',
    })
    expect(mapped.instructions.some((step) => step.stepNumber === 0)).toBe(
      false,
    )
    expect(mapped.recipeSources[0].id).toBe('1')
    expect(mapped.dataQualityIssues[0].recipeId).toBe('11')
  })

  it('preserves a null method summary', () => {
    const mapped = mapRecipeDetail({ ...detailDto, method_summary: null })

    expect(mapped.methodSummary).toBeNull()
  })

  it('omits missing optional relationships', () => {
    const mapped = mapRecipeSummary({
      id: 12,
      recipe_category_id: null,
      recipe_code: null,
      name: 'Minimal recipe',
      main_ingredient: null,
      recipe_type: null,
      cooking_variation: null,
      region: null,
      description: null,
      preparation_time_minutes: null,
      cooking_time_minutes: null,
      total_time_minutes: null,
      source_yield: null,
      yield_people: null,
      time_is_estimated: false,
      yield_is_estimated: false,
      time_arithmetic_status: null,
    })

    expect(mapped.id).toBe('12')
    expect(mapped.recipeCategoryId).toBeNull()
    expect(mapped).not.toHaveProperty('recipeCategory')
    expect(mapped).not.toHaveProperty('recipeIngredientsCount')
    expect(mapped).not.toHaveProperty('recipeInstructionsCount')
    expect(mapped).not.toHaveProperty('recipeSourcesCount')
    expect(mapped).not.toHaveProperty('dataQualityIssuesCount')
  })
})
