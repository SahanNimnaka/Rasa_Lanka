import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios'
import type { ValidationDetails } from '../types/auth'

const configuredBackendUrl = import.meta.env.VITE_API_URL?.trim()
const backendUrl = (configuredBackendUrl || 'http://127.0.0.1:8000').replace(
  /\/+$/,
  '',
)

export const BACKEND_URL = backendUrl

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details?: ValidationDetails

  constructor(
    message: string,
    status: number,
    code: string,
    details?: ValidationDetails,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.details = details
  }
}

interface ApiErrorPayload {
  error?: {
    code?: string
    message?: string
    details?: ValidationDetails
  }
}

export const apiClient = axios.create({
  baseURL: `${backendUrl}/api/v1`,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

export const csrfClient = axios.create({
  baseURL: backendUrl,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

type UnauthorizedHandler = () => void

let unauthorizedHandler: UnauthorizedHandler | null = null

export function setUnauthorizedHandler(
  handler: UnauthorizedHandler | null,
): void {
  unauthorizedHandler = handler
}

type HandlerAwareConfig = InternalAxiosRequestConfig & {
  _unauthorizedHandled?: boolean
}

function shouldIgnoreUnauthorized(url: string): boolean {
  return (
    url.includes('/auth/me') ||
    url.includes('/auth/login') ||
    url.includes('/auth/register')
  )
}

apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiErrorPayload>) => {
    const config = error.config as HandlerAwareConfig | undefined
    const url = config?.url ?? ''

    if (
      error.response?.status === 401 &&
      config &&
      !shouldIgnoreUnauthorized(url) &&
      !config._unauthorizedHandled
    ) {
      config._unauthorizedHandled = true
      unauthorizedHandler?.()
    }

    return Promise.reject(error)
  },
)

export async function bootstrapCsrf(): Promise<void> {
  await csrfClient.get('/sanctum/csrf-cookie')
}

export function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) {
    return error
  }

  if (axios.isAxiosError<ApiErrorPayload>(error)) {
    const payload = error.response?.data

    return new ApiError(
      payload?.error?.message ??
        'The request could not be completed. Please try again.',
      error.response?.status ?? 0,
      payload?.error?.code ?? 'UNKNOWN_ERROR',
      payload?.error?.details,
    )
  }

  return new ApiError(
    'The request could not be completed. Please try again.',
    0,
    'UNKNOWN_ERROR',
  )
}
