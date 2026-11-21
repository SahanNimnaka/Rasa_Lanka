import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../services/api'
import type { PaginatedApiEnvelope } from '../../types/api'
import {
  itemEnvelope,
  paginatedEnvelope,
  recipeSummaryDto,
  recipeViewDto,
} from '../../test/queryFixtures'
import {
  createQueryWrapper,
  createTestQueryClient,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import {
  useClearRecipeHistory,
  useRecipeHistory,
  useRecordRecipeView,
} from './useRecipeHistory'

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
        ? `http://localhost/api/v1/recipe-history?page=${page + 1}`
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

describe('useRecipeHistory', () => {
  it('loads mapped recipe views, shares the cache, and appends page 2', async () => {
    const wrapper = createQueryWrapper(createTestQueryClient())
    const secondRecipeViewDto = {
      ...recipeViewDto,
      id: 78,
      viewed_at: '2026-11-21T09:30:00+00:00',
      created_at: '2026-11-21T09:30:00+00:00',
      updated_at: '2026-11-21T09:30:00+00:00',
    }
    const adapter = vi.fn(
      async (config: Parameters<typeof makeAxiosResponse>[0]) => {
        const page = Number(config.params?.page ?? 1)
        return makeAxiosResponse(
          config,
          pageEnvelope(
            [page === 1 ? recipeViewDto : secondRecipeViewDto],
            page,
            page === 1,
          ),
        )
      },
    )
    setApiAdapter(adapter)

    const first = renderHook(() => useRecipeHistory(), { wrapper })
    const second = renderHook(() => useRecipeHistory(), { wrapper })

    await waitFor(() => {
      expect(first.result.current.data?.pages[0].data[0].id).toBe('77')
      expect(second.result.current.data?.pages[0].data[0].id).toBe('77')
    })

    expect(adapter).toHaveBeenCalledTimes(1)
    expect(adapter.mock.calls[0][0].url).toBe('/recipe-history')
    expect(adapter.mock.calls[0][0].params).toEqual({ page: 1 })

    const firstItem = first.result.current.data?.pages[0].data[0]
    expect(firstItem).toMatchObject({
      id: '77',
      recipeId: '11',
      viewedAt: '2026-11-21T08:30:00+00:00',
      createdAt: '2026-11-21T08:30:00+00:00',
      updatedAt: '2026-11-21T08:30:00+00:00',
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
    expect(first.result.current.data?.pages[1].data[0].id).toBe('78')
    expect(first.result.current.data?.pageParams).toEqual([1, 2])
    expect(first.result.current.hasNextPage).toBe(false)
  })

  it('refetches recipe history after recording a view and clearing history', async () => {
    const wrapper = createQueryWrapper()
    let listRequests = 0

    setApiAdapter(async (config) => {
      if ((config.method ?? 'get').toLowerCase() !== 'get') {
        return makeAxiosResponse(config, itemEnvelope({ deleted_count: 1 }), 201)
      }

      listRequests += 1
      return makeAxiosResponse(
        config,
        paginatedEnvelope([{ ...recipeViewDto, id: 300 + listRequests }]),
      )
    })

    const { result } = renderHook(
      () => ({
        history: useRecipeHistory(),
        recordView: useRecordRecipeView(),
        clearHistory: useClearRecipeHistory(),
      }),
      { wrapper },
    )

    await waitFor(() => {
      expect(result.current.history.isSuccess).toBe(true)
    })
    expect(listRequests).toBe(1)
    expect(result.current.history.data?.pages[0].data[0].id).toBe('301')

    await act(async () => {
      await result.current.recordView.mutateAsync(recipeSummaryDto.id)
    })

    await waitFor(() => {
      expect(result.current.history.data?.pages[0].data[0].id).toBe('302')
    })
    expect(result.current.recordView.isSuccess).toBe(true)

    await act(async () => {
      await result.current.clearHistory.mutateAsync(undefined)
    })

    await waitFor(() => {
      expect(result.current.history.data?.pages[0].data[0].id).toBe('303')
    })
    expect(result.current.clearHistory.isSuccess).toBe(true)
    expect(listRequests).toBe(3)
  })

  it('propagates query API errors as ApiError', async () => {
    const wrapper = createQueryWrapper()

    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 500, 'SERVER_ERROR', 'Backend unavailable')
    })

    const { result } = renderHook(() => useRecipeHistory(), { wrapper })

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

  it('propagates mutation API errors as ApiError', async () => {
    const wrapper = createQueryWrapper()

    setApiAdapter(async (config) => {
      if ((config.method ?? 'get').toLowerCase() === 'post') {
        throw makeAxiosError(config, 404, 'NOT_FOUND', 'Recipe not found')
      }
      return makeAxiosResponse(config, paginatedEnvelope([recipeViewDto]))
    })

    const { result } = renderHook(() => useRecordRecipeView(), { wrapper })

    await act(async () => {
      await result.current.mutateAsync(recipeSummaryDto.id).catch(() => undefined)
    })

    await waitFor(() => {
      expect(result.current.isError).toBe(true)
    })

    expect(result.current.error).toBeInstanceOf(ApiError)
    expect(result.current.error).toMatchObject({
      status: 404,
      code: 'NOT_FOUND',
      message: 'Recipe not found',
    })
  })
})
