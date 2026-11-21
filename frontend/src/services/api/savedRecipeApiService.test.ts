// @vitest-environment node
import type { InternalAxiosRequestConfig } from 'axios'
import { describe, expect, it, vi } from 'vitest'
import {
  itemEnvelope,
  paginatedEnvelope,
  recipeSummaryDto,
  savedRecipeDto,
} from '../../test/queryFixtures'
import {
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import { ApiError, csrfClient } from '../api'
import {
  listSavedRecipes,
  removeSavedRecipe,
  saveRecipe,
} from './savedRecipeApiService'

function spyOnCsrfBootstrap() {
  const adapter = vi.fn(async (config: InternalAxiosRequestConfig) =>
    makeAxiosResponse(config, null, 204),
  )
  csrfClient.defaults.adapter = adapter
  return adapter
}

describe('savedRecipeApiService', () => {
  it('unwraps the paginated saved recipes envelope', async () => {
    const envelope = paginatedEnvelope([savedRecipeDto])
    let capturedConfig: InternalAxiosRequestConfig | undefined

    setApiAdapter(async (config) => {
      capturedConfig = config
      return makeAxiosResponse(config, envelope)
    })

    const result = await listSavedRecipes({ page: 2, per_page: 10 })

    expect(capturedConfig?.url).toBe('/saved-recipes')
    expect(capturedConfig?.method).toBe('get')
    expect(capturedConfig?.params).toEqual({ page: 2, per_page: 10 })
    expect(result.data).toEqual([savedRecipeDto])
    expect(result.links).toEqual(envelope.links)
    expect(result.pagination).toEqual(envelope.meta.pagination)
    expect(result.meta).toEqual(envelope.meta)
  })

  it('bootstraps CSRF and posts the recipe id when saving a recipe', async () => {
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
      return makeAxiosResponse(config, itemEnvelope(savedRecipeDto), 201)
    })

    const result = await saveRecipe(recipeSummaryDto.id)

    expect(calls).toEqual(['csrf', 'api'])
    expect(csrfAdapter.mock.calls[0][0].url).toBe('/sanctum/csrf-cookie')
    expect(capturedConfig?.url).toBe('/saved-recipes')
    expect(capturedConfig?.method).toBe('post')
    expect(JSON.parse(String(capturedConfig?.data))).toEqual({
      recipe_id: recipeSummaryDto.id,
    })
    expect(result).toEqual(savedRecipeDto)
  })

  it('deletes a saved recipe by id after bootstrapping CSRF', async () => {
    const csrfAdapter = spyOnCsrfBootstrap()
    let capturedConfig: InternalAxiosRequestConfig | undefined

    setApiAdapter(async (config) => {
      capturedConfig = config
      return makeAxiosResponse(config, itemEnvelope(null))
    })

    await expect(removeSavedRecipe(savedRecipeDto.id)).resolves.toBeUndefined()

    expect(csrfAdapter).toHaveBeenCalledTimes(1)
    expect(csrfAdapter.mock.calls[0][0].url).toBe('/sanctum/csrf-cookie')
    expect(capturedConfig?.url).toBe(`/saved-recipes/${savedRecipeDto.id}`)
    expect(capturedConfig?.method).toBe('delete')
  })

  it('throws ApiError with status and code on API failures', async () => {
    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 403, 'FORBIDDEN', 'Not allowed')
    })

    const operations = [
      () => listSavedRecipes(),
      () => saveRecipe(recipeSummaryDto.id),
      () => removeSavedRecipe(savedRecipeDto.id),
    ]

    for (const operation of operations) {
      await expect(operation()).rejects.toBeInstanceOf(ApiError)
      await expect(operation()).rejects.toMatchObject({
        status: 403,
        code: 'FORBIDDEN',
        message: 'Not allowed',
      })
    }
  })
})
