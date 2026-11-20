// @vitest-environment node
import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import { ApiError } from '../services/api'
import {
  createQueryClient,
  DEFAULT_GC_TIME,
  DEFAULT_STALE_TIME,
  MAX_TRANSIENT_RETRIES,
  shouldRetryQuery,
} from './queryClient'

describe('queryClient retry policy', () => {
  it('does not retry ordinary client errors', () => {
    for (const status of [400, 401, 403, 404]) {
      expect(
        shouldRetryQuery(0, new ApiError('Client error', status, 'CLIENT_ERROR')),
      ).toBe(false)
    }
  })

  it('does not retry unexpected 4xx responses', () => {
    expect(
      shouldRetryQuery(0, new ApiError('Invalid sort', 422, 'VALIDATION_FAILED')),
    ).toBe(false)
  })

  it('retries transient network and server failures up to the limit', () => {
    const networkError = new ApiError('Offline', 0, 'NETWORK_ERROR')
    const serverError = new ApiError('Unavailable', 503, 'SERVER_ERROR')

    expect(shouldRetryQuery(0, networkError)).toBe(true)
    expect(shouldRetryQuery(1, networkError)).toBe(true)
    expect(shouldRetryQuery(MAX_TRANSIENT_RETRIES, networkError)).toBe(false)
    expect(shouldRetryQuery(0, serverError)).toBe(true)
  })

  it('does not retry Axios client errors', () => {
    const axiosError = new AxiosError('Bad request')
    axiosError.response = {
      config: { headers: new AxiosHeaders() },
      data: {},
      headers: {},
      status: 404,
      statusText: 'Not Found',
    }

    expect(shouldRetryQuery(0, axiosError)).toBe(false)
  })

  it('uses catalogue-friendly query defaults', () => {
    const defaults = createQueryClient().getDefaultOptions().queries

    expect(defaults).toMatchObject({
      staleTime: DEFAULT_STALE_TIME,
      gcTime: DEFAULT_GC_TIME,
      refetchOnWindowFocus: false,
      refetchOnReconnect: true,
      refetchOnMount: true,
    })
  })
})
