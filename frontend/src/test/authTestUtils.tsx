import { render, type RenderResult } from '@testing-library/react'
import type { ReactElement } from 'react'
import { vi } from 'vitest'
import { AuthContext } from '../context/authStore'
import type { AuthContextValue } from '../context/authStore'
import { testUser } from './authFixtures'

export function createAuthValue(
  overrides: Partial<AuthContextValue> = {},
): AuthContextValue {
  return {
    user: testUser,
    status: 'authenticated',
    isAuthenticated: true,
    login: vi.fn().mockResolvedValue(testUser),
    register: vi.fn().mockResolvedValue(testUser),
    logout: vi.fn().mockResolvedValue(undefined),
    refresh: vi.fn().mockResolvedValue(undefined),
    ...overrides,
  }
}

export function renderWithAuth(
  ui: ReactElement,
  overrides: Partial<AuthContextValue> = {},
): RenderResult {
  return render(
    <AuthContext value={createAuthValue(overrides)}>{ui}</AuthContext>,
  )
}
