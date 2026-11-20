export interface AuthUser {
  id: number
  name: string
  email: string
  created_at: string | null
  updated_at: string | null
}

export interface AuthEnvelope<T> {
  success: boolean
  data: T
  meta: {
    api_version: string
  }
}

export interface LoginPayload {
  email: string
  password: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}

export type ValidationDetails = Record<string, string[]>
