import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, beforeEach, vi } from 'vitest'
import { apiClient, csrfClient } from '../services/api'
import { mockApiAdapter, mockCsrfAdapter, resetApiMock } from './apiMock'

beforeEach(() => {
  resetApiMock()
  apiClient.defaults.adapter = mockApiAdapter
  csrfClient.defaults.adapter = mockCsrfAdapter
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})
