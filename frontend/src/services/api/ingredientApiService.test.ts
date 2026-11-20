// @vitest-environment node
import {
  type AxiosAdapter,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'
import { describe, expect, it } from 'vitest'
import { apiClient } from '../api'
import { getIngredient, listIngredients } from './ingredientApiService'

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

describe('ingredientApiService', () => {
  it('serializes include arrays and returns paginated ingredients', async () => {
    let capturedConfig: InternalAxiosRequestConfig | undefined

    const adapter: AxiosAdapter = async (config) => {
      capturedConfig = config
      return makeResponse(config, {
        success: true,
        data: [{ id: 81, name: 'Red lentils (masoor dhal)' }],
        links: {
          first: 'http://localhost/api/v1/ingredients?page=1',
          last: null,
          prev: null,
          next: null,
        },
        meta: {
          api_version: 'v1',
          pagination: {
            current_page: 1,
            per_page: 100,
            total: 1,
            last_page: 1,
            from: 1,
            to: 1,
          },
        },
      })
    }

    apiClient.defaults.adapter = adapter

    const result = await listIngredients({
      search: 'lentils',
      include: ['categories', 'recipes_count'],
      per_page: 100,
    })

    expect(capturedConfig?.url).toBe('/ingredients')
    expect(capturedConfig?.params).toEqual({
      search: 'lentils',
      per_page: 100,
      include: 'categories,recipes_count',
    })
    expect(result.data).toEqual([
      { id: 81, name: 'Red lentils (masoor dhal)' },
    ])
    expect(result.pagination.total).toBe(1)
  })

  it('requests ingredient detail with the include parameter', async () => {
    let capturedConfig: InternalAxiosRequestConfig | undefined

    apiClient.defaults.adapter = async (config) => {
      capturedConfig = config
      return makeResponse(config, {
        success: true,
        data: { id: 81, name: 'Red lentils (masoor dhal)' },
        meta: { api_version: 'v1' },
      })
    }

    const ingredient = await getIngredient(81, {
      include: 'data_quality_issues',
    })

    expect(capturedConfig?.url).toBe('/ingredients/81')
    expect(capturedConfig?.params).toEqual({
      include: 'data_quality_issues',
    })
    expect(ingredient.id).toBe(81)
  })
})
