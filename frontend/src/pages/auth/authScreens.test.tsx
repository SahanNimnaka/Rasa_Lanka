import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { LoginScreen } from './LoginScreen'
import { RegisterScreen } from './RegisterScreen'

function renderLogin() {
  render(
    <MemoryRouter>
      <LoginScreen />
    </MemoryRouter>,
  )
}

function renderRegister() {
  render(
    <MemoryRouter>
      <RegisterScreen />
    </MemoryRouter>,
  )
}

describe('LoginScreen', () => {
  it('validates required fields and email format without signing in', async () => {
    const user = userEvent.setup()
    renderLogin()

    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(await screen.findByText('Email is required')).toBeInTheDocument()
    expect(screen.getByText('Password is required')).toBeInTheDocument()

    await user.type(screen.getByLabelText('Email'), 'not-an-email')
    await user.type(screen.getByLabelText('Password'), 'password123')
    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(
      await screen.findByText('Enter a valid email address'),
    ).toBeInTheDocument()
    expect(
      screen.queryByText(
        'Authentication is not connected yet, so no sign-in was attempted.',
      ),
    ).not.toBeInTheDocument()
  })

  it('shows an authentication placeholder after a locally valid form', async () => {
    const user = userEvent.setup()
    renderLogin()

    await user.type(screen.getByLabelText('Email'), 'sahan@example.com')
    await user.type(screen.getByLabelText('Password'), 'password123')
    await user.click(screen.getByRole('button', { name: 'Login' }))

    expect(
      await screen.findByRole('status', {
        name: '',
      }),
    ).toHaveTextContent('Authentication is not connected yet')
    expect(screen.queryByRole('heading', { name: 'Welcome, Sahan' })).toBeNull()
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
    renderRegister()

    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(await screen.findByText('Enter your full name')).toBeInTheDocument()
    expect(screen.getByText('Email is required')).toBeInTheDocument()
    expect(screen.getByText('Use at least 8 characters')).toBeInTheDocument()
    expect(screen.getByText('Re-enter your password')).toBeInTheDocument()
    expect(
      screen.getByText('Accept the Terms and Privacy Policy to continue'),
    ).toBeInTheDocument()

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

  it('rejects weak passwords and accepts a locally valid form without creating an account', async () => {
    const user = userEvent.setup()
    renderRegister()

    await user.type(screen.getByLabelText('Full Name'), 'Sahan Perera')
    await user.type(screen.getByLabelText('Email'), 'sahan@example.com')
    await user.type(screen.getByLabelText('Password'), 'short')
    await user.type(screen.getByLabelText('Confirm Password'), 'short')
    await user.click(screen.getByRole('checkbox'))
    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(
      await screen.findByText('Use at least 8 characters'),
    ).toBeInTheDocument()

    await user.clear(screen.getByLabelText('Password'))
    await user.type(screen.getByLabelText('Password'), 'password123')
    await user.clear(screen.getByLabelText('Confirm Password'))
    await user.type(screen.getByLabelText('Confirm Password'), 'password123')
    await user.click(screen.getByRole('button', { name: 'Sign up' }))

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Account creation is not connected yet',
    )
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
