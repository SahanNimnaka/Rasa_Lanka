import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import {
  AxiosError,
  type AxiosAdapter,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'
import type { ReactNode } from 'react'
import { apiClient } from '../services/api'

export function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        staleTime: 0,
        gcTime: 0,
        refetchOnWindowFocus: false,
      },
      mutations: {
        retry: false,
      },
    },
  })
}

export function createQueryWrapper(queryClient = createTestQueryClient()) {
  return function QueryWrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    )
  }
}

export function makeAxiosResponse<T>(
  config: InternalAxiosRequestConfig,
  data: T,
  status = 200,
): AxiosResponse<T> {
  return {
    config,
    data,
    headers: {},
    status,
    statusText: status === 200 ? 'OK' : 'Error',
  }
}

export function makeAxiosError(
  config: InternalAxiosRequestConfig,
  status: number,
  code: string,
  message: string,
): AxiosError {
  const response: AxiosResponse = {
    config,
    data: {
      success: false,
      error: { code, message },
      meta: { api_version: 'v1' },
    },
    headers: {},
    status,
    statusText: status === 404 ? 'Not Found' : 'Server Error',
  }

  return new AxiosError(message, 'ERR_BAD_REQUEST', config, undefined, response)
}

export function setApiAdapter(adapter: AxiosAdapter): void {
  apiClient.defaults.adapter = adapter
}
