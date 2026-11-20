import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../services/api'
import {
  itemEnvelope,
  paginatedEnvelope,
  recipeDetailDto,
  recipeSummaryDto,
} from '../../test/queryFixtures'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import { useRecipe, useRecipes } from './useRecipes'

describe('recipe query hooks', () => {
  it('loads mapped recipe summaries and caches identical queries', async () => {
    const wrapper = createQueryWrapper()
    const adapter = vi.fn(async (config: Parameters<typeof makeAxiosResponse>[0]) =>
      makeAxiosResponse(config, paginatedEnvelope([recipeSummaryDto])),
    )
    setApiAdapter(adapter)

    const first = renderHook(() => useRecipes({ search: 'parippu' }), {
      wrapper,
    })
    const second = renderHook(() => useRecipes({ search: 'parippu' }), {
      wrapper,
    })

    await waitFor(() => {
      expect(first.result.current.data?.data[0].id).toBe('11')
      expect(second.result.current.data?.data[0].id).toBe('11')
    })

    expect(adapter).toHaveBeenCalledTimes(1)
    expect(first.result.current.data?.data[0]).toMatchObject({
      name: 'Sri Lankan Parippu Curry',
      durationMinutes: 35,
      yieldPeople: '2.50',
      id: '11',
    })
    expect(first.result.current.data?.pagination.total).toBe(1)

    await act(async () => {
      await first.result.current.refetch()
    })

    expect(adapter).toHaveBeenCalledTimes(2)
  })

  it('loads a mapped recipe detail', async () => {
    const wrapper = createQueryWrapper()
    let requestUrl: string | undefined

    setApiAdapter(async (config) => {
      requestUrl = config.url
      return makeAxiosResponse(config, itemEnvelope(recipeDetailDto))
    })

    const { result } = renderHook(() => useRecipe('11'), { wrapper })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(requestUrl).toBe('/recipes/11')
    expect(result.current.data?.id).toBe('11')
    expect(result.current.data?.methodSummary).toBeNull()
    expect(result.current.data?.instructions[0]).toMatchObject({
      id: '2',
      stepNumber: 1,
      instructionText: 'Simmer until tender.',
    })
    expect(result.current.data?.recipeIngredients[0]).toMatchObject({
      id: '67',
      approvalStatus: 'Pending expert validation',
      expertApprovedQuantity: null,
      isExpertApproved: false,
    })
  })

  it('disables detail queries for invalid IDs', () => {
    const wrapper = createQueryWrapper()
    const adapter = vi.fn(async () => {
      throw new Error('should not be called')
    })
    setApiAdapter(adapter)

    const { result } = renderHook(() => useRecipe('dhal-curry'), { wrapper })

    expect(result.current.fetchStatus).toBe('idle')
    expect(result.current.isFetching).toBe(false)
    expect(adapter).not.toHaveBeenCalled()
  })

  it('propagates API errors', async () => {
    const wrapper = createQueryWrapper()

    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 500, 'SERVER_ERROR', 'Backend unavailable')
    })

    const { result } = renderHook(() => useRecipes(), { wrapper })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })

    expect(result.current.error).toBeInstanceOf(ApiError)
    expect(result.current.error).toMatchObject({
      status: 500,
      code: 'SERVER_ERROR',
      message: 'Backend unavailable',
    })
  })
})
