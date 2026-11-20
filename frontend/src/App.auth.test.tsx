import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import {
  MemoryRouter,
  Route,
  Routes,
  useLocation,
  useNavigate,
} from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AppRoutes } from './App'
import { ProtectedRoute } from './components/auth/ProtectedRoute'
import { AuthProvider } from './context/AuthProvider'
import { useAuth } from './context/authStore'
import { apiClient } from './services/api'
import { authService } from './services/authService'
import { apiMockState } from './test/apiMock'
import { testUser } from './test/authFixtures'

type InitialEntry =
  string | { pathname: string; state?: Record<string, unknown> }

interface RedirectState {
  from?: { pathname?: string }
}

function LocationProbe() {
  const location = useLocation()
  const from = (location.state as RedirectState | null)?.from?.pathname

  return <p data-testid="intended-path">{from ?? 'none'}</p>
}

function NavigationControl() {
  const navigate = useNavigate()

  return (
    <button onClick={() => navigate('/profile')} type="button">
      Open profile
    </button>
  )
}

function renderApp(initialEntry: InitialEntry = '/home', withControls = false) {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <AppRoutes />
      <LocationProbe />
      {withControls && <NavigationControl />}
    </MemoryRouter>,
  )
}

function mockUnauthenticated() {
  apiMockState.authenticated = false
}

async function fillLoginForm(
  user: ReturnType<typeof userEvent.setup>,
  password = 'password123',
) {
  await user.type(screen.getByLabelText('Email'), 'sahan@example.com')
  await user.type(screen.getByLabelText('Password'), password)
}

async function fillRegisterForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText('Full Name'), 'Sahan Perera')
  await user.type(screen.getByLabelText('Email'), 'sahan@example.com')
  await user.type(screen.getByLabelText('Password'), 'password123')
  await user.type(screen.getByLabelText('Confirm Password'), 'password123')
  await user.click(screen.getByRole('checkbox'))
}

describe('frontend auth integration', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('registers a valid account and enters the app', async () => {
    const user = userEvent.setup()
    mockUnauthenticated()

    renderApp('/register')

    await fillRegisterForm(user)
    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(
      await screen.findByRole('heading', { name: 'Welcome, Sahan' }),
    ).toBeInTheDocument()
    expect(apiMockState.authenticated).toBe(true)
  })

  it('shows duplicate-email validation without entering the app', async () => {
    const user = userEvent.setup()
    mockUnauthenticated()
    apiMockState.registeredEmails.add('sahan@example.com')

    renderApp('/register')

    await fillRegisterForm(user)
    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(
      await screen.findByText('The email has already been taken.'),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('heading', { name: 'Welcome, Sahan' }),
    ).not.toBeInTheDocument()
  })

  it('rejects invalid registration input before calling the API', async () => {
    const user = userEvent.setup()
    mockUnauthenticated()
    const registerSpy = vi.spyOn(authService, 'register')

    renderApp('/register')

    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(await screen.findByText('Enter your full name')).toBeInTheDocument()
    expect(registerSpy).not.toHaveBeenCalled()
  })

  it('logs in with valid credentials and navigates home', async () => {
    const user = userEvent.setup()
    mockUnauthenticated()

    renderApp('/login')

    await fillLoginForm(user)
    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(
      await screen.findByRole('heading', { name: 'Welcome, Sahan' }),
    ).toBeInTheDocument()
    expect(apiMockState.authenticated).toBe(true)
  })

  it('shows a generic error for invalid credentials', async () => {
    const user = userEvent.setup()
    mockUnauthenticated()

    renderApp('/login')

    await fillLoginForm(user, 'wrong-password')
    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(await screen.findByRole('status')).toHaveTextContent(
      'The provided credentials are incorrect.',
    )
    expect(
      screen.queryByRole('heading', { name: 'Welcome, Sahan' }),
    ).not.toBeInTheDocument()
  })

  it('restores an existing session from /auth/me on app init', async () => {
    const getCurrentUser = vi.spyOn(authService, 'getCurrentUser')

    renderApp('/profile')

    expect(
      await screen.findByRole('heading', { name: 'Profile' }),
    ).toBeInTheDocument()
    expect(screen.getByText(testUser.email)).toBeInTheDocument()
    expect(getCurrentUser).toHaveBeenCalledTimes(1)
  })

  it('redirects a logged-out visitor from a protected route and preserves intent', async () => {
    mockUnauthenticated()

    renderApp('/profile')

    expect(
      await screen.findByRole('heading', { name: 'Welcome Back' }),
    ).toBeInTheDocument()
    expect(screen.getByTestId('intended-path')).toHaveTextContent('/profile')
  })

  it('logs out and clears protected access until the next login', async () => {
    const user = userEvent.setup()
    const logout = vi.spyOn(authService, 'logout')

    renderApp('/profile', true)

    expect(
      await screen.findByRole('heading', { name: 'Profile' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Sign out' }))

    expect(logout).toHaveBeenCalledTimes(1)
    expect(
      await screen.findByRole('heading', { name: 'Welcome Back' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Open profile' }))

    expect(
      await screen.findByRole('heading', { name: 'Welcome Back' }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('heading', { name: 'Profile' }),
    ).not.toBeInTheDocument()
  })

  it('clears auth state when an authenticated request expires with 401', async () => {
    const user = userEvent.setup()

    function SessionProbe() {
      const { status } = useAuth()

      async function expireSession() {
        try {
          await apiClient.get('/recipes')
        } catch {
          expect(apiMockState.authenticated).toBe(false)
        }
      }

      return (
        <div>
          <p data-testid="auth-status">{status}</p>
          <button onClick={expireSession} type="button">
            Expire session
          </button>
        </div>
      )
    }

    render(
      <MemoryRouter initialEntries={['/profile']}>
        <AuthProvider>
          <Routes>
            <Route
              element={
                <ProtectedRoute>
                  <p>Private profile</p>
                </ProtectedRoute>
              }
              path="/profile"
            />
            <Route element={<p>Login page</p>} path="/login" />
          </Routes>
          <SessionProbe />
        </AuthProvider>
      </MemoryRouter>,
    )

    expect(await screen.findByText('Private profile')).toBeInTheDocument()
    expect(screen.getByTestId('auth-status')).toHaveTextContent('authenticated')

    apiMockState.authenticated = false
    await user.click(screen.getByRole('button', { name: 'Expire session' }))

    await waitFor(() => {
      expect(screen.getByTestId('auth-status')).toHaveTextContent(
        'unauthenticated',
      )
    })
    expect(await screen.findByText('Login page')).toBeInTheDocument()
  })
})
