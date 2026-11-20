import { ApiError } from '../services/api'
import type { AuthUser } from '../types/auth'

export const testUser: AuthUser = {
  id: 1,
  name: 'Sahan Perera',
  email: 'sahan@example.com',
  created_at: '2026-11-20T00:00:00.000000Z',
  updated_at: '2026-11-20T00:00:00.000000Z',
}

export function duplicateEmailError(): ApiError {
  return new ApiError(
    'The email has already been taken. (and 1 other error)',
    422,
    'VALIDATION_ERROR',
    { email: ['The email has already been taken.'] },
  )
}

export function invalidCredentialsError(): ApiError {
  return new ApiError(
    'The provided credentials are incorrect.',
    401,
    'UNAUTHENTICATED',
  )
}
