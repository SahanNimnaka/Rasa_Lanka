// @vitest-environment node
import { describe, expect, it } from 'vitest'
import { parseApiId, queryKeys } from './queryKeys'

describe('queryKeys', () => {
  it('produces stable keys for identical parameters', () => {
    const params = { search: 'parippu', page: 2, per_page: 15 }

    expect(queryKeys.recipes.list(params)).toEqual(
      queryKeys.recipes.list(params),
    )
    expect(queryKeys.ingredients.list(params)).toEqual(
      queryKeys.ingredients.list(params),
    )
  })

  it('separates different search terms', () => {
    expect(queryKeys.recipes.list({ search: 'dhal' })).not.toEqual(
      queryKeys.recipes.list({ search: 'curry' }),
    )
    expect(queryKeys.ingredients.list({ search: 'onion' })).not.toEqual(
      queryKeys.ingredients.list({ search: 'lentils' }),
    )
  })

  it('separates different pagination parameters', () => {
    expect(queryKeys.recipes.list({ page: 1 })).not.toEqual(
      queryKeys.recipes.list({ page: 2 }),
    )
    expect(queryKeys.recipes.list({ per_page: 15 })).not.toEqual(
      queryKeys.recipes.list({ per_page: 100 }),
    )
  })

  it('separates different ingredient filters', () => {
    expect(queryKeys.recipes.list({ ingredient_id: 81 })).not.toEqual(
      queryKeys.recipes.list({ ingredient_id: 70 }),
    )
    expect(
      queryKeys.ingredients.list({ ingredient_category_id: 13 }),
    ).not.toEqual(queryKeys.ingredients.list({ ingredient_category_id: 7 }))
  })

  it('normalizes include array order', () => {
    expect(
      queryKeys.ingredients.list({
        include: ['categories', 'recipes_count'],
      }),
    ).toEqual(
      queryKeys.ingredients.list({
        include: ['recipes_count', 'categories'],
      }),
    )
  })

  it('includes parameters in detail and category keys', () => {
    expect(queryKeys.recipes.detail(11)).toEqual(
      queryKeys.recipes.detail(11),
    )
    expect(queryKeys.recipes.detail(11)).not.toEqual(
      queryKeys.recipes.detail(12),
    )
    expect(
      queryKeys.ingredients.detail(81, { include: 'data_quality_issues' }),
    ).not.toEqual(queryKeys.ingredients.detail(81))
    expect(queryKeys.recipeCategories.list({ search: 'parippu' })).not.toEqual(
      queryKeys.recipeCategories.list({ search: 'spice' }),
    )
    expect(
      queryKeys.ingredientCategories.list({ include: ['ingredients_count'] }),
    ).not.toEqual(queryKeys.ingredientCategories.list())
  })

  it('parses valid IDs and rejects missing or invalid IDs', () => {
    expect(parseApiId(11)).toBe(11)
    expect(parseApiId('11')).toBe(11)
    expect(parseApiId(' 11 ')).toBe(11)
    expect(parseApiId(null)).toBeNull()
    expect(parseApiId(undefined)).toBeNull()
    expect(parseApiId(0)).toBeNull()
    expect(parseApiId(-1)).toBeNull()
    expect(parseApiId(1.5)).toBeNull()
    expect(parseApiId('abc')).toBeNull()
    expect(parseApiId('dhal-curry')).toBeNull()
  })
})
