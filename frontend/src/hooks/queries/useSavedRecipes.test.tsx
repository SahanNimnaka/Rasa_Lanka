import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../services/api'
import type { PaginatedApiEnvelope } from '../../types/api'
import {
  itemEnvelope,
  paginatedEnvelope,
  recipeSummaryDto,
  savedRecipeDto,
} from '../../test/queryFixtures'
import {
  createQueryWrapper,
  createTestQueryClient,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import {
  useSaveRecipe,
  useSavedRecipes,
  useUnsaveRecipe,
} from './useSavedRecipes'

function pageEnvelope<T>(
  items: T[],
  page: number,
  hasNext: boolean,
): PaginatedApiEnvelope<T> {
  const envelope = paginatedEnvelope(items)

  return {
    ...envelope,
    links: {
      ...envelope.links,
      next: hasNext
        ? `http://localhost/api/v1/saved-recipes?page=${page + 1}`
        : null,
    },
    meta: {
      ...envelope.meta,
      pagination: {
        ...envelope.meta.pagination,
        current_page: page,
      },
    },
  }
}

describe('useSavedRecipes', () => {
  it('loads mapped saved recipes, shares the cache, and appends page 2', async () => {
    const wrapper = createQueryWrapper(createTestQueryClient())
    const secondSavedRecipeDto = {
      ...savedRecipeDto,
      id: 43,
      created_at: '2026-11-20T11:15:00+00:00',
      updated_at: '2026-11-20T11:15:00+00:00',
    }
    const adapter = vi.fn(
      async (config: Parameters<typeof makeAxiosResponse>[0]) => {
        const page = Number(config.params?.page ?? 1)
        return makeAxiosResponse(
          config,
          pageEnvelope(
            [page === 1 ? savedRecipeDto : secondSavedRecipeDto],
            page,
            page === 1,
          ),
        )
      },
    )
    setApiAdapter(adapter)

    const first = renderHook(() => useSavedRecipes(), { wrapper })
    const second = renderHook(() => useSavedRecipes(), { wrapper })

    await waitFor(() => {
      expect(first.result.current.data?.pages[0].data[0].id).toBe('42')
      expect(second.result.current.data?.pages[0].data[0].id).toBe('42')
    })

    expect(adapter).toHaveBeenCalledTimes(1)
    expect(adapter.mock.calls[0][0].url).toBe('/saved-recipes')
    expect(adapter.mock.calls[0][0].params).toEqual({ page: 1 })

    const firstItem = first.result.current.data?.pages[0].data[0]
    expect(firstItem).toMatchObject({
      id: '42',
      recipeId: '11',
      savedAt: '2026-11-20T10:15:00+00:00',
      updatedAt: '2026-11-20T10:15:00+00:00',
    })
    expect(firstItem?.recipe).toMatchObject({
      id: '11',
      recipeCode: 'PAR-001',
      name: 'Sri Lankan Parippu Curry',
      durationMinutes: 35,
      yieldPeople: '2.50',
    })
    expect(first.result.current.data?.pages[0].pagination.current_page).toBe(1)
    expect(first.result.current.data?.pageParams).toEqual([1])
    expect(first.result.current.hasNextPage).toBe(true)

    await act(async () => {
      await first.result.current.fetchNextPage()
    })

    await waitFor(() => {
      expect(first.result.current.data?.pages).toHaveLength(2)
    })

    expect(adapter).toHaveBeenCalledTimes(2)
    expect(adapter.mock.calls[1][0].params).toEqual({ page: 2 })
    expect(first.result.current.data?.pages[1].data[0].id).toBe('43')
    expect(first.result.current.data?.pageParams).toEqual([1, 2])
    expect(first.result.current.hasNextPage).toBe(false)
  })

  it('refetches saved recipes after save and unsave mutations', async () => {
    const wrapper = createQueryWrapper()
    let listRequests = 0

    setApiAdapter(async (config) => {
      if ((config.method ?? 'get').toLowerCase() !== 'get') {
        return makeAxiosResponse(config, itemEnvelope(savedRecipeDto), 201)
      }

      listRequests += 1
      return makeAxiosResponse(
        config,
        paginatedEnvelope([{ ...savedRecipeDto, id: 200 + listRequests }]),
      )
    })

    const { result } = renderHook(
      () => ({
        recipes: useSavedRecipes(),
        save: useSaveRecipe(),
        unsave: useUnsaveRecipe(),
      }),
      { wrapper },
    )

    await waitFor(() => {
      expect(result.current.recipes.isSuccess).toBe(true)
    })
    expect(listRequests).toBe(1)
    expect(result.current.recipes.data?.pages[0].data[0].id).toBe('201')

    await act(async () => {
      await result.current.save.mutateAsync(recipeSummaryDto.id)
    })

    await waitFor(() => {
      expect(result.current.recipes.data?.pages[0].data[0].id).toBe('202')
    })
    expect(result.current.save.isSuccess).toBe(true)

    await act(async () => {
      await result.current.unsave.mutateAsync(savedRecipeDto.id)
    })

    await waitFor(() => {
      expect(result.current.recipes.data?.pages[0].data[0].id).toBe('203')
    })
    expect(result.current.unsave.isSuccess).toBe(true)
    expect(listRequests).toBe(3)
  })

  it('propagates query API errors as ApiError', async () => {
    const wrapper = createQueryWrapper()

    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 503, 'SERVICE_UNAVAILABLE', 'Backend unavailable')
    })

    const { result } = renderHook(() => useSavedRecipes(), { wrapper })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })

    expect(result.current.error).toBeInstanceOf(ApiError)
    expect(result.current.error).toMatchObject({
      status: 503,
      code: 'SERVICE_UNAVAILABLE',
      message: 'Backend unavailable',
    })
  })

  it('propagates mutation API errors as ApiError', async () => {
    const wrapper = createQueryWrapper()

    setApiAdapter(async (config) => {
      if ((config.method ?? 'get').toLowerCase() === 'post') {
        throw makeAxiosError(config, 422, 'VALIDATION_ERROR', 'Already saved')
      }
      return makeAxiosResponse(config, paginatedEnvelope([savedRecipeDto]))
    })

    const { result } = renderHook(() => useSaveRecipe(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync(recipeSummaryDto.id).catch(() => undefined)
    })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })

    expect(result.current.error).toBeInstanceOf(ApiError)
    expect(result.current.error).toMatchObject({
      status: 422,
      code: 'VALIDATION_ERROR',
      message: 'Already saved',
    })
  })
})
