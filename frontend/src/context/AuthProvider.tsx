import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { authService } from '../services/authService'
import { setUnauthorizedHandler } from '../services/api'
import type { AuthUser, LoginPayload, RegisterPayload } from '../types/auth'
import {
  AuthContext,
  type AuthContextValue,
  type AuthStatus,
} from './authStore'

export interface AuthProviderProps {
  children: ReactNode
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [status, setStatus] = useState<AuthStatus>('loading')
  const requestId = useRef(0)

  const clearUser = useCallback(() => {
    requestId.current += 1
    setUser(null)
    setStatus('unauthenticated')
  }, [])

  const setAuthenticatedUser = useCallback((authenticatedUser: AuthUser) => {
    requestId.current += 1
    setUser(authenticatedUser)
    setStatus('authenticated')
  }, [])

  const refresh = useCallback(async () => {
    const currentRequestId = ++requestId.current
    setStatus('loading')

    try {
      const currentUser = await authService.getCurrentUser()
      if (requestId.current === currentRequestId) {
        setUser(currentUser)
        setStatus('authenticated')
      }
    } catch {
      if (requestId.current === currentRequestId) {
        clearUser()
      }
    }
  }, [clearUser])

  useEffect(() => {
    const initialRequestId = ++requestId.current
    let active = true

    authService
      .getCurrentUser()
      .then((currentUser) => {
        if (active && requestId.current === initialRequestId) {
          setUser(currentUser)
          setStatus('authenticated')
        }
      })
      .catch(() => {
        if (active && requestId.current === initialRequestId) {
          clearUser()
        }
      })

    return () => {
      active = false
    }
  }, [clearUser])

  useEffect(() => {
    setUnauthorizedHandler(clearUser)

    return () => {
      setUnauthorizedHandler(null)
    }
  }, [clearUser])

  const login = useCallback(
    async (payload: LoginPayload) => {
      const authenticatedUser = await authService.login(payload)
      setAuthenticatedUser(authenticatedUser)
      return authenticatedUser
    },
    [setAuthenticatedUser],
  )

  const register = useCallback(
    async (payload: RegisterPayload) => {
      const registeredUser = await authService.register(payload)
      setAuthenticatedUser(registeredUser)
      return registeredUser
    },
    [setAuthenticatedUser],
  )

  const logout = useCallback(async () => {
    try {
      await authService.logout()
    } finally {
      clearUser()
    }
  }, [clearUser])

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      status,
      isAuthenticated: status === 'authenticated',
      login,
      register,
      logout,
      refresh,
    }),
    [login, logout, refresh, register, status, user],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}
