import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import type { AuthContextValue } from '../../context/authStore'
import type { AuthUser } from '../../types/auth'
import {
  duplicateEmailError,
  invalidCredentialsError,
  testUser,
} from '../../test/authFixtures'
import { renderWithAuth } from '../../test/authTestUtils'
import { LoginScreen } from './LoginScreen'
import { RegisterScreen } from './RegisterScreen'

interface RenderOptions {
  initialEntry?: string | { pathname: string; state?: Record<string, unknown> }
  authOverrides?: Partial<AuthContextValue>
}

function renderScreen(
  screenComponent: typeof LoginScreen | typeof RegisterScreen,
  { initialEntry = '/login', authOverrides = {} }: RenderOptions = {},
) {
  const ScreenComponent = screenComponent

  return renderWithAuth(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route element={<ScreenComponent />} path="/login" />
        <Route element={<ScreenComponent />} path="/register" />
        <Route element={<p>Destination: /home</p>} path="/home" />
        <Route element={<p>Destination: /profile</p>} path="/profile" />
      </Routes>
    </MemoryRouter>,
    authOverrides,
  )
}

function renderLogin(options: RenderOptions = {}) {
  return renderScreen(LoginScreen, {
    initialEntry: '/login',
    ...options,
  })
}

function renderRegister(options: RenderOptions = {}) {
  return renderScreen(RegisterScreen, {
    initialEntry: '/register',
    ...options,
  })
}

async function fillLoginForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText('Email'), 'sahan@example.com')
  await user.type(screen.getByLabelText('Password'), 'password123')
}

async function fillRegisterForm(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText('Full Name'), 'Sahan Perera')
  await user.type(screen.getByLabelText('Email'), 'sahan@example.com')
  await user.type(screen.getByLabelText('Password'), 'password123')
  await user.type(screen.getByLabelText('Confirm Password'), 'password123')
  await user.click(screen.getByRole('checkbox'))
}

describe('LoginScreen', () => {
  it('validates required fields and email format without signing in', async () => {
    const user = userEvent.setup()
    const login = vi.fn().mockResolvedValue(testUser)
    renderLogin({ authOverrides: { login } })

    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(await screen.findByText('Email is required')).toBeInTheDocument()
    expect(screen.getByText('Password is required')).toBeInTheDocument()
    expect(login).not.toHaveBeenCalled()

    await user.type(screen.getByLabelText('Email'), 'not-an-email')
    await user.type(screen.getByLabelText('Password'), 'password123')
    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(
      await screen.findByText('Enter a valid email address'),
    ).toBeInTheDocument()
    expect(login).not.toHaveBeenCalled()
  })

  it('signs in through auth context and navigates home', async () => {
    const user = userEvent.setup()
    const login = vi.fn().mockResolvedValue(testUser)
    renderLogin({ authOverrides: { login } })

    await fillLoginForm(user)
    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(await screen.findByText('Destination: /home')).toBeInTheDocument()
    expect(login).toHaveBeenCalledWith({
      email: 'sahan@example.com',
      password: 'password123',
    })
  })

  it('returns to the protected destination preserved before login', async () => {
    const user = userEvent.setup()
    const login = vi.fn().mockResolvedValue(testUser)
    renderLogin({
      initialEntry: {
        pathname: '/login',
        state: { from: { pathname: '/profile' } },
      },
      authOverrides: { login },
    })

    await fillLoginForm(user)
    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(await screen.findByText('Destination: /profile')).toBeInTheDocument()
  })

  it('shows a generic backend authentication error', async () => {
    const user = userEvent.setup()
    const login = vi.fn().mockRejectedValue(invalidCredentialsError())
    renderLogin({ authOverrides: { login } })

    await fillLoginForm(user)
    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(await screen.findByRole('status')).toHaveTextContent(
      'The provided credentials are incorrect.',
    )
    expect(screen.queryByText('Destination: /home')).not.toBeInTheDocument()
  })

  it('shows a loading state and prevents duplicate login submissions', async () => {
    const user = userEvent.setup()
    let resolveLogin: (user: AuthUser) => void = () => {}
    const login = vi.fn(
      () =>
        new Promise<AuthUser>((resolve) => {
          resolveLogin = resolve
        }),
    )
    renderLogin({ authOverrides: { login } })

    await fillLoginForm(user)
    const submit = screen.getByRole('button', { name: 'Login' })
    await user.click(submit)

    expect(submit).toBeDisabled()
    expect(screen.getByRole('status')).toHaveTextContent('Signing in…')
    expect(login).toHaveBeenCalledTimes(1)

    resolveLogin(testUser)
    expect(await screen.findByText('Destination: /home')).toBeInTheDocument()
  })

  it('toggles password visibility', async () => {
    const user = userEvent.setup()
    renderLogin()

    const password = screen.getByLabelText('Password')
    expect(password).toHaveAttribute('type', 'password')

    await user.click(
      screen.getByRole('button', { name: 'Show password for Password' }),
    )
    expect(password).toHaveAttribute('type', 'text')

    await user.click(
      screen.getByRole('button', { name: 'Hide password for Password' }),
    )
    expect(password).toHaveAttribute('type', 'password')
  })

  it('does not pretend that social authentication is configured', async () => {
    const user = userEvent.setup()
    renderLogin()

    await user.click(screen.getByRole('button', { name: 'Google sign-in' }))

    expect(screen.getByRole('status')).toHaveTextContent(
      'Google sign-in is unavailable until its OAuth provider is configured.',
    )
  })
})

describe('RegisterScreen', () => {
  it('validates every required field, password rules, confirmation and terms', async () => {
    const user = userEvent.setup()
    const register = vi.fn().mockResolvedValue(testUser)
    renderRegister({ authOverrides: { register } })

    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(await screen.findByText('Enter your full name')).toBeInTheDocument()
    expect(screen.getByText('Email is required')).toBeInTheDocument()
    expect(screen.getByText('Use at least 8 characters')).toBeInTheDocument()
    expect(screen.getByText('Re-enter your password')).toBeInTheDocument()
    expect(
      screen.getByText('Accept the Terms and Privacy Policy to continue'),
    ).toBeInTheDocument()
    expect(register).not.toHaveBeenCalled()

    await user.type(screen.getByLabelText('Full Name'), 'Sahan Perera')
    await user.type(screen.getByLabelText('Email'), 'sahan@example.com')
    await user.type(screen.getByLabelText('Password'), 'password123')
    await user.type(screen.getByLabelText('Confirm Password'), 'different123')
    await user.click(screen.getByRole('checkbox'))

    expect(await screen.findByText('Passwords must match')).toBeInTheDocument()
    expect(
      screen.queryByText('Accept the Terms and Privacy Policy to continue'),
    ).not.toBeInTheDocument()
  })

  it('rejects weak passwords before calling the API', async () => {
    const user = userEvent.setup()
    const register = vi.fn().mockResolvedValue(testUser)
    renderRegister({ authOverrides: { register } })

    await user.type(screen.getByLabelText('Full Name'), 'Sahan Perera')
    await user.type(screen.getByLabelText('Email'), 'sahan@example.com')
    await user.type(screen.getByLabelText('Password'), 'short')
    await user.type(screen.getByLabelText('Confirm Password'), 'short')
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(
      await screen.findByText('Use at least 8 characters'),
    ).toBeInTheDocument()
    expect(register).not.toHaveBeenCalled()
  })

  it('creates an account through auth context and navigates home', async () => {
    const user = userEvent.setup()
    const register = vi.fn().mockResolvedValue(testUser)
    renderRegister({ authOverrides: { register } })

    await fillRegisterForm(user)
    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(await screen.findByText('Destination: /home')).toBeInTheDocument()
    expect(register).toHaveBeenCalledWith({
      name: 'Sahan Perera',
      email: 'sahan@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    })
  })

  it('shows duplicate-email validation on the correct field', async () => {
    const user = userEvent.setup()
    const register = vi.fn().mockRejectedValue(duplicateEmailError())
    renderRegister({ authOverrides: { register } })

    await fillRegisterForm(user)
    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(
      await screen.findByText('The email has already been taken.'),
    ).toBeInTheDocument()
    expect(screen.queryByText('Destination: /home')).not.toBeInTheDocument()
  })

  it('shows a loading state and prevents duplicate registration submissions', async () => {
    const user = userEvent.setup()
    let resolveRegister: (user: AuthUser) => void = () => {}
    const register = vi.fn(
      () =>
        new Promise<AuthUser>((resolve) => {
          resolveRegister = resolve
        }),
    )
    renderRegister({ authOverrides: { register } })

    await fillRegisterForm(user)
    const submit = screen.getByRole('button', { name: 'Sign up' })
    await user.click(submit)

    expect(submit).toBeDisabled()
    expect(screen.getByRole('status')).toHaveTextContent('Creating account…')
    expect(register).toHaveBeenCalledTimes(1)

    resolveRegister(testUser)
    expect(await screen.findByText('Destination: /home')).toBeInTheDocument()
  })

  it('toggles both password fields independently', async () => {
    const user = userEvent.setup()
    renderRegister()

    const password = screen.getByLabelText('Password')
    const confirmPassword = screen.getByLabelText('Confirm Password')

    await user.click(
      screen.getByRole('button', { name: 'Show password for Password' }),
    )
    expect(password).toHaveAttribute('type', 'text')
    expect(confirmPassword).toHaveAttribute('type', 'password')

    await user.click(
      screen.getByRole('button', {
        name: 'Show password for Confirm Password',
      }),
    )
    expect(confirmPassword).toHaveAttribute('type', 'text')

    await user.click(
      screen.getByRole('button', { name: 'Hide password for Password' }),
    )
    expect(password).toHaveAttribute('type', 'password')
  })
})
