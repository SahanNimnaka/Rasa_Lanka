import {
  AxiosError,
  type AxiosAdapter,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'
import { ApiError } from '../services/api'
import {
  duplicateEmailError,
  invalidCredentialsError,
  testUser,
} from './authFixtures'

interface MockState {
  authenticated: boolean
  registeredEmails: Set<string>
}

export const apiMockState: MockState = {
  authenticated: true,
  registeredEmails: new Set<string>(),
}

export function resetApiMock(): void {
  apiMockState.authenticated = true
  apiMockState.registeredEmails = new Set<string>()
}

function makeResponse<T>(
  config: InternalAxiosRequestConfig,
  data: T,
  status = 200,
): AxiosResponse<T> {
  return {
    config,
    data,
    headers: {},
    status,
    statusText: status === 201 ? 'Created' : 'OK',
  }
}

function errorFromApiError(
  config: InternalAxiosRequestConfig,
  error: ApiError,
): never {
  const response: AxiosResponse = {
    config,
    data: {
      success: false,
      error: {
        code: error.code,
        message: error.message,
        details: error.details,
      },
      meta: { api_version: 'v1' },
    },
    headers: {},
    status: error.status,
    statusText: error.status === 401 ? 'Unauthorized' : 'Unprocessable Entity',
  }

  throw new AxiosError(
    error.message,
    'ERR_BAD_REQUEST',
    config,
    undefined,
    response,
  )
}

function readPayload(
  config: InternalAxiosRequestConfig,
): Record<string, unknown> {
  if (typeof config.data !== 'string') {
    return {}
  }

  try {
    return JSON.parse(config.data) as Record<string, unknown>
  } catch {
    return {}
  }
}

export const mockApiAdapter: AxiosAdapter = async (config) => {
  const method = config.method?.toLowerCase() ?? 'get'
  const url = config.url ?? ''
  const payload = readPayload(config)

  if (method === 'get' && url === '/auth/me') {
    if (!apiMockState.authenticated) {
      return errorFromApiError(
        config,
        new ApiError('Unauthenticated.', 401, 'UNAUTHENTICATED'),
      )
    }

    return makeResponse(config, {
      success: true,
      data: testUser,
      meta: { api_version: 'v1' },
    })
  }

  if (method === 'post' && url === '/auth/login') {
    if (
      payload.email !== 'sahan@example.com' ||
      payload.password !== 'password123'
    ) {
      return errorFromApiError(config, invalidCredentialsError())
    }

    apiMockState.authenticated = true
    return makeResponse(config, {
      success: true,
      data: testUser,
      meta: { api_version: 'v1' },
    })
  }

  if (method === 'post' && url === '/auth/register') {
    const email = String(payload.email ?? '')

    if (apiMockState.registeredEmails.has(email)) {
      return errorFromApiError(config, duplicateEmailError())
    }

    apiMockState.registeredEmails.add(email)
    apiMockState.authenticated = true
    return makeResponse(
      config,
      {
        success: true,
        data: testUser,
        meta: { api_version: 'v1' },
      },
      201,
    )
  }

  if (method === 'post' && url === '/auth/logout') {
    apiMockState.authenticated = false
    return makeResponse(config, {
      success: true,
      data: null,
      meta: { api_version: 'v1' },
    })
  }

  if (!apiMockState.authenticated) {
    return errorFromApiError(
      config,
      new ApiError('Unauthenticated.', 401, 'UNAUTHENTICATED'),
    )
  }

  return makeResponse(config, {
    success: true,
    data: [],
    meta: { api_version: 'v1' },
  })
}

export const mockCsrfAdapter: AxiosAdapter = async (config) =>
  makeResponse(config, null, 204)
