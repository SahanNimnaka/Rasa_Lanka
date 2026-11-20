// @vitest-environment node
import {
  type AxiosAdapter,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'
import { describe, expect, it } from 'vitest'
import { apiClient } from '../api'
import {
  listIngredientCategories,
  listRecipeCategories,
} from './categoryApiService'

function paginatedResponse<T>(
  config: InternalAxiosRequestConfig,
  data: T[],
): AxiosResponse {
  return makeResponse(config, {
    success: true,
    data,
    links: {
      first: 'http://localhost/api/v1/categories?page=1',
      last: null,
      prev: null,
      next: null,
    },
    meta: {
      api_version: 'v1',
      pagination: {
        current_page: 1,
        per_page: 15,
        total: data.length,
        last_page: 1,
        from: data.length > 0 ? 1 : null,
        to: data.length,
      },
    },
  })
}

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

describe('categoryApiService', () => {
  it('lists recipe categories with counts', async () => {
    let capturedConfig: InternalAxiosRequestConfig | undefined

    const adapter: AxiosAdapter = async (config) => {
      capturedConfig = config
      return paginatedResponse(config, [
        { id: 5, name: 'Parippu', description: null, recipes_count: 2 },
      ])
    }

    apiClient.defaults.adapter = adapter

    const result = await listRecipeCategories({ include: ['recipes_count'] })

    expect(capturedConfig?.url).toBe('/recipe-categories')
    expect(capturedConfig?.params).toEqual({ include: 'recipes_count' })
    expect(result.data[0]).toMatchObject({ id: 5, recipes_count: 2 })
    expect(result.pagination.total).toBe(1)
  })

  it('lists ingredient categories', async () => {
    let capturedConfig: InternalAxiosRequestConfig | undefined

    const adapter: AxiosAdapter = async (config) => {
      capturedConfig = config
      return paginatedResponse(config, [
        { id: 13, name: 'Spice', description: null },
      ])
    }

    apiClient.defaults.adapter = adapter

    const result = await listIngredientCategories({ search: 'spice' })

    expect(capturedConfig?.url).toBe('/ingredient-categories')
    expect(capturedConfig?.params).toEqual({ search: 'spice' })
    expect(result.data[0]).toEqual({ id: 13, name: 'Spice', description: null })
  })
})
