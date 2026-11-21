// @vitest-environment node
import type { InternalAxiosRequestConfig } from 'axios'
import { describe, expect, it, vi } from 'vitest'
import {
  itemEnvelope,
  paginatedEnvelope,
  recipeSummaryDto,
  recipeViewDto,
} from '../../test/queryFixtures'
import {
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import { ApiError, csrfClient } from '../api'
import {
  clearRecipeHistory,
  listRecipeHistory,
  recordRecipeView,
} from './recipeHistoryApiService'

function spyOnCsrfBootstrap() {
  const adapter = vi.fn(async (config: InternalAxiosRequestConfig) =>
    makeAxiosResponse(config, null, 204),
  )
  csrfClient.defaults.adapter = adapter
  return adapter
}

describe('recipeHistoryApiService', () => {
  it('unwraps the paginated recipe history envelope', async () => {
    const envelope = paginatedEnvelope([recipeViewDto])
    let capturedConfig: InternalAxiosRequestConfig | undefined

    setApiAdapter(async (config) => {
      capturedConfig = config
      return makeAxiosResponse(config, envelope)
    })

    const result = await listRecipeHistory({ page: 3, per_page: 5 })

    expect(capturedConfig?.url).toBe('/recipe-history')
    expect(capturedConfig?.method).toBe('get')
    expect(capturedConfig?.params).toEqual({ page: 3, per_page: 5 })
    expect(result.data).toEqual([recipeViewDto])
    expect(result.links).toEqual(envelope.links)
    expect(result.pagination).toEqual(envelope.meta.pagination)
    expect(result.meta).toEqual(envelope.meta)
  })

  it('bootstraps CSRF and posts the recipe id when recording a view', async () => {
    const calls: string[] = []
    const csrfAdapter = vi.fn(async (config: InternalAxiosRequestConfig) => {
      calls.push('csrf')
      return makeAxiosResponse(config, null, 204)
    })
    csrfClient.defaults.adapter = csrfAdapter

    let capturedConfig: InternalAxiosRequestConfig | undefined
    setApiAdapter(async (config) => {
      calls.push('api')
      capturedConfig = config
      return makeAxiosResponse(config, itemEnvelope(recipeViewDto), 201)
    })

    const result = await recordRecipeView(recipeSummaryDto.id)

    expect(calls).toEqual(['csrf', 'api'])
    expect(csrfAdapter.mock.calls[0][0].url).toBe('/sanctum/csrf-cookie')
    expect(capturedConfig?.url).toBe('/recipe-history')
    expect(capturedConfig?.method).toBe('post')
    expect(JSON.parse(String(capturedConfig?.data))).toEqual({
      recipe_id: recipeSummaryDto.id,
    })
    expect(result).toEqual(recipeViewDto)
  })

  it('clears recipe history after bootstrapping CSRF', async () => {
    const csrfAdapter = spyOnCsrfBootstrap()
    let capturedConfig: InternalAxiosRequestConfig | undefined

    setApiAdapter(async (config) => {
      capturedConfig = config
      return makeAxiosResponse(config, itemEnvelope({ deleted_count: 1 }))
    })

    await expect(clearRecipeHistory()).resolves.toBeUndefined()

    expect(csrfAdapter).toHaveBeenCalledTimes(1)
    expect(csrfAdapter.mock.calls[0][0].url).toBe('/sanctum/csrf-cookie')
    expect(capturedConfig?.url).toBe('/recipe-history')
    expect(capturedConfig?.method).toBe('delete')
  })

  it('throws ApiError with status and code on API failures', async () => {
    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 500, 'SERVER_ERROR', 'Backend unavailable')
    })

    const operations = [
      () => listRecipeHistory(),
      () => recordRecipeView(recipeSummaryDto.id),
      () => clearRecipeHistory(),
    ]

    for (const operation of operations) {
      await expect(operation()).rejects.toBeInstanceOf(ApiError)
      await expect(operation()).rejects.toMatchObject({
        status: 500,
        code: 'SERVER_ERROR',
        message: 'Backend unavailable',
      })
    }
  })
})
