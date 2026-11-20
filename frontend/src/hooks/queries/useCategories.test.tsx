import { renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  ingredientCategoryDto,
  paginatedEnvelope,
  recipeCategoryDto,
} from '../../test/queryFixtures'
import {
  createQueryWrapper,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import {
  useIngredientCategories,
  useRecipeCategories,
} from './useCategories'

describe('category query hooks', () => {
  it('loads mapped recipe categories', async () => {
    const wrapper = createQueryWrapper()
    let params: Record<string, unknown> | undefined

    setApiAdapter(async (config) => {
      params = config.params as Record<string, unknown>
      return makeAxiosResponse(
        config,
        paginatedEnvelope([recipeCategoryDto]),
      )
    })

    const { result } = renderHook(
      () => useRecipeCategories({ include: ['recipes_count'] }),
      { wrapper },
    )

    await waitFor(() => {
      expect(result.current.data?.data[0].id).toBe('5')
    })

    expect(params).toEqual({ include: 'recipes_count' })
    expect(result.current.data?.data[0]).toMatchObject({
      name: 'Parippu',
      recipesCount: 2,
    })
  })

  it('loads mapped ingredient categories', async () => {
    const wrapper = createQueryWrapper()

    setApiAdapter(async (config) =>
      makeAxiosResponse(config, paginatedEnvelope([ingredientCategoryDto])),
    )

    const { result } = renderHook(() => useIngredientCategories(), { wrapper })

    await waitFor(() => {
      expect(result.current.data?.data[0].id).toBe('13')
    })

    expect(result.current.data?.data[0]).toMatchObject({
      name: 'Spice',
      ingredientsCount: 4,
    })
  })
})
