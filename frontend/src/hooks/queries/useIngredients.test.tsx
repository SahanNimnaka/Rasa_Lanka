import { renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../services/api'
import {
  ingredientDetailDto,
  ingredientDto,
  itemEnvelope,
  paginatedEnvelope,
} from '../../test/queryFixtures'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import { useIngredient, useIngredients } from './useIngredients'

describe('ingredient query hooks', () => {
  it('loads mapped ingredient summaries', async () => {
    const wrapper = createQueryWrapper()
    let params: Record<string, unknown> | undefined

    setApiAdapter(async (config) => {
      params = config.params as Record<string, unknown>
      return makeAxiosResponse(config, paginatedEnvelope([ingredientDto]))
    })

    const { result } = renderHook(
      () => useIngredients({ search: 'lentils', per_page: 100 }),
      { wrapper },
    )

    await waitFor(() => {
      expect(result.current.data?.data[0].id).toBe('81')
    })

    expect(params).toEqual({ search: 'lentils', per_page: 100 })
    expect(result.current.data?.data[0]).toMatchObject({
      name: 'Red lentils (masoor dhal)',
      ingredientKey: 'red_lentils_masoor_dhal',
      recipesCount: 3,
    })
    expect(result.current.data?.pagination.total).toBe(1)
  })

  it('loads mapped ingredient detail with include parameters', async () => {
    const wrapper = createQueryWrapper()
    let params: Record<string, unknown> | undefined

    setApiAdapter(async (config) => {
      params = config.params as Record<string, unknown>
      return makeAxiosResponse(config, itemEnvelope(ingredientDetailDto))
    })

    const { result } = renderHook(
      () =>
        useIngredient(81, {
          include: ['data_quality_issues'],
        }),
      { wrapper },
    )

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(params).toEqual({ include: 'data_quality_issues' })
    expect(result.current.data?.id).toBe('81')
    expect(result.current.data?.categories?.[0]).toMatchObject({
      id: '13',
      ingredientsCount: 4,
    })
  })

  it('disables ingredient detail queries for invalid IDs', () => {
    const wrapper = createQueryWrapper()
    const adapter = vi.fn(async () => {
      throw new Error('should not be called')
    })
    setApiAdapter(adapter)

    const { result } = renderHook(
      () => useIngredient(0, { include: 'data_quality_issues' }),
      { wrapper },
    )

    expect(result.current.fetchStatus).toBe('idle')
    expect(adapter).not.toHaveBeenCalled()
  })

  it('propagates API errors', async () => {
    const wrapper = createQueryWrapper()

    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 404, 'NOT_FOUND', 'Ingredient not found')
    })

    const { result } = renderHook(() => useIngredients(), { wrapper })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })

    expect(result.current.error).toBeInstanceOf(ApiError)
    expect(result.current.error).toMatchObject({
      status: 404,
      code: 'NOT_FOUND',
    })
  })
})
