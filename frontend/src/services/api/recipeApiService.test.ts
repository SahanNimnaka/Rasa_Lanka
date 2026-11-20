// @vitest-environment node
import {
  AxiosError,
  type AxiosAdapter,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'
import { describe, expect, it } from 'vitest'
import { apiClient, ApiError } from '../api'
import { getRecipe, listRecipes } from './recipeApiService'

function makeResponse<T>(
  config: InternalAxiosRequestConfig,
  data: T,
): AxiosResponse<T> {
  return {
    config,
    data,
    headers: {},
    status: 200,
    statusText: 'OK',
  }
}

describe('recipeApiService', () => {
  it('returns recipe summaries with pagination metadata', async () => {
    let capturedConfig: InternalAxiosRequestConfig | undefined

    const adapter: AxiosAdapter = async (config) => {
      capturedConfig = config
      return makeResponse(config, {
        success: true,
        data: [{ id: 11, name: 'Parippu Curry' }],
        links: {
          first: 'http://localhost/api/v1/recipes?page=1',
          last: null,
          prev: null,
          next: null,
        },
        meta: {
          api_version: 'v1',
          pagination: {
            current_page: 1,
            per_page: 15,
            total: 1,
            last_page: 1,
            from: 1,
            to: 1,
          },
        },
      })
    }

    apiClient.defaults.adapter = adapter

    const result = await listRecipes({ search: 'parippu', page: 1 })

    expect(capturedConfig?.url).toBe('/recipes')
    expect(capturedConfig?.params).toEqual({ search: 'parippu', page: 1 })
    expect(result.data).toEqual([{ id: 11, name: 'Parippu Curry' }])
    expect(result.pagination).toEqual({
      current_page: 1,
      per_page: 15,
      total: 1,
      last_page: 1,
      from: 1,
      to: 1,
    })
    expect(result.links.prev).toBeNull()
    expect(result.meta.api_version).toBe('v1')
  })

  it('unwraps a recipe detail response', async () => {
    apiClient.defaults.adapter = async (config) =>
      makeResponse(config, {
        success: true,
        data: { id: 11, name: 'Parippu Curry' },
        meta: { api_version: 'v1' },
      })

    const recipe = await getRecipe(11)

    expect(recipe).toEqual({ id: 11, name: 'Parippu Curry' })
  })

  it('propagates API errors as ApiError', async () => {
    apiClient.defaults.adapter = async (config) => {
      const response: AxiosResponse = {
        config,
        data: {
          success: false,
          error: {
            code: 'NOT_FOUND',
            message: 'The requested API resource was not found.',
          },
          meta: { api_version: 'v1' },
        },
        headers: {},
        status: 404,
        statusText: 'Not Found',
      }

      throw new AxiosError(
        'The requested API resource was not found.',
        'ERR_BAD_REQUEST',
        config,
        undefined,
        response,
      )
    }

    await expect(getRecipe(999)).rejects.toBeInstanceOf(ApiError)
    await expect(getRecipe(999)).rejects.toMatchObject({
      status: 404,
      code: 'NOT_FOUND',
      message: 'The requested API resource was not found.',
    })
  })
})
