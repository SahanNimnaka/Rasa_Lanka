// @vitest-environment node
import { describe, expect, it } from 'vitest'
import type {
  IngredientDetailDto,
  IngredientDto,
} from '../../types/apiIngredients'
import { mapIngredient, mapIngredientDetail } from './ingredientMappers'

const ingredientDto: IngredientDto = {
  id: 22,
  name: 'Curry leaves',
  ingredient_key: 'curry_leaves',
  description: null,
  categories: [
    {
      id: 5,
      name: 'Herb',
      description: null,
      ingredients_count: 10,
      provenance: {
        source_identifier: 'VIDEO',
        source_record_identifier: 'REC-22',
        source_category_text: 'Herb / aromatic',
      },
    },
    {
      id: 5,
      name: 'Herb',
      description: null,
      ingredients_count: 10,
      provenance: {
        source_identifier: 'BOOK',
        source_record_identifier: 'REC-22',
        source_category_text: 'Herb',
      },
    },
  ],
  recipes_count: 6,
}

const detailDto: IngredientDetailDto = {
  ...ingredientDto,
  recipe_ingredients: [
    {
      id: 67,
      recipe_id: 11,
      ingredient_id: 22,
      source_identifier: 'BOOK',
      source_record_identifier: 'PAR-001',
      original_ingredient_id: null,
      original_ingredient_name: 'Curry leaves',
      original_quantity_text: '1 sprig',
      original_unit: 'sprig',
      per_person_quantity_min: '0.10000',
      per_person_quantity_max: '0.10000',
      per_person_quantity_description: null,
      per_person_unit: 'sprig',
      expert_approved_quantity: null,
      expert_approved_unit: null,
      approval_status: 'Pending expert validation',
      original_requirement_label: null,
      ingredient_preparation: null,
      is_required: true,
      is_main_ingredient: false,
      ingredient_notes: null,
      data_quality_status: null,
      quantity_is_derived: true,
      quantity_description_is_estimated: false,
      quantity_depends_on_estimated_yield: false,
    },
  ],
  data_quality_issues: [],
}

describe('ingredient mappers', () => {
  it('maps numeric IDs and preserves category provenance rows', () => {
    const mapped = mapIngredient(ingredientDto)

    expect(mapped.id).toBe('22')
    expect(mapped.ingredientKey).toBe('curry_leaves')
    expect(mapped.recipesCount).toBe(6)
    expect(mapped.categories).toHaveLength(2)
    expect(mapped.categories?.[0]).toMatchObject({
      id: '5',
      ingredientsCount: 10,
      provenance: {
        sourceIdentifier: 'VIDEO',
        sourceRecordIdentifier: 'REC-22',
        sourceCategoryText: 'Herb / aromatic',
      },
    })
    expect(mapped.categories?.[1]?.provenance?.sourceIdentifier).toBe('BOOK')
  })

  it('maps optional detail relationships', () => {
    const mapped = mapIngredientDetail(detailDto)

    expect(mapped.recipeIngredients).toHaveLength(1)
    expect(mapped.recipeIngredients?.[0]).toMatchObject({
      id: '67',
      recipeId: '11',
      ingredientId: '22',
      originalQuantityText: '1 sprig',
      originalUnit: 'sprig',
      perPersonQuantityMin: '0.10000',
      approvalStatus: 'Pending expert validation',
      isExpertApproved: false,
      quantityIsDerived: true,
    })
    expect(mapped.dataQualityIssues).toEqual([])
  })

  it('omits missing optional relationships', () => {
    const mapped = mapIngredientDetail({
      id: 81,
      name: 'Red lentils (masoor dhal)',
      ingredient_key: 'red_lentils_masoor_dhal',
      description: null,
    })

    expect(mapped.id).toBe('81')
    expect(mapped).not.toHaveProperty('categories')
    expect(mapped).not.toHaveProperty('recipesCount')
    expect(mapped).not.toHaveProperty('recipeIngredients')
    expect(mapped).not.toHaveProperty('dataQualityIssues')
  })
})
