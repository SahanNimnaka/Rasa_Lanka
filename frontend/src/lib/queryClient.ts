import { isAxiosError } from 'axios'
import { QueryClient } from '@tanstack/react-query'
import { ApiError } from '../services/api'

export const DEFAULT_STALE_TIME = 5 * 60 * 1000
export const DEFAULT_GC_TIME = 30 * 60 * 1000
export const MAX_TRANSIENT_RETRIES = 2

const NON_RETRYABLE_CLIENT_STATUSES = new Set([400, 401, 403, 404])
const RETRYABLE_CLIENT_STATUSES = new Set([408, 425, 429])

function shouldRetryStatus(status: number, failureCount: number): boolean {
  if (NON_RETRYABLE_CLIENT_STATUSES.has(status)) {
    return false
  }

  if (status === 0 || RETRYABLE_CLIENT_STATUSES.has(status)) {
    return failureCount < MAX_TRANSIENT_RETRIES
  }

  return status >= 500 && failureCount < MAX_TRANSIENT_RETRIES
}

export function shouldRetryQuery(
  failureCount: number,
  error: unknown,
): boolean {
  if (error instanceof ApiError) {
    return shouldRetryStatus(error.status, failureCount)
  }

  if (isAxiosError(error)) {
    return shouldRetryStatus(error.response?.status ?? 0, failureCount)
  }

  return failureCount < MAX_TRANSIENT_RETRIES
}

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: DEFAULT_STALE_TIME,
        gcTime: DEFAULT_GC_TIME,
        retry: shouldRetryQuery,
        refetchOnWindowFocus: false,
        refetchOnReconnect: true,
        refetchOnMount: true,
      },
      mutations: {
        retry: false,
      },
    },
  })
}

export const queryClient = createQueryClient()
