import { apiClient, bootstrapCsrf } from './api'
import type {
  AuthEnvelope,
  AuthUser,
  LoginPayload,
  RegisterPayload,
} from '../types/auth'

async function unwrap(response: {
  data: AuthEnvelope<AuthUser>
}): Promise<AuthUser> {
  return response.data.data
}

export const authService = {
  async register(payload: RegisterPayload): Promise<AuthUser> {
    await bootstrapCsrf()
    return unwrap(
      await apiClient.post<AuthEnvelope<AuthUser>>('/auth/register', payload),
    )
  },

  async login(payload: LoginPayload): Promise<AuthUser> {
    await bootstrapCsrf()
    return unwrap(
      await apiClient.post<AuthEnvelope<AuthUser>>('/auth/login', payload),
    )
  },

  async logout(): Promise<void> {
    await bootstrapCsrf()
    await apiClient.post('/auth/logout')
  },

  async getCurrentUser(): Promise<AuthUser> {
    return unwrap(await apiClient.get<AuthEnvelope<AuthUser>>('/auth/me'))
  },
}
