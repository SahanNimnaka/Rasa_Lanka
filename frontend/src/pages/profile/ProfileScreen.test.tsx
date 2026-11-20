import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AppStateProvider } from '../../context/AppStateContext'
import { APP_STATE_STORAGE_KEY } from '../../services/appStateService'
import { renderWithAuth } from '../../test/authTestUtils'
import { ProfileScreen } from './ProfileScreen'

function renderScreen(authOverrides = {}) {
  return renderWithAuth(
    <MemoryRouter initialEntries={['/profile']}>
      <AppStateProvider>
        <Routes>
          <Route element={<ProfileScreen />} path="/profile" />
          <Route element={<p>Preferences destination</p>} path="/preferences" />
          <Route element={<p>Saved destination</p>} path="/saved" />
          <Route element={<p>History destination</p>} path="/history" />
          <Route element={<p>Settings destination</p>} path="/settings" />
          <Route element={<p>About destination</p>} path="/about" />
          <Route element={<p>Login destination</p>} path="/login" />
        </Routes>
      </AppStateProvider>
    </MemoryRouter>,
    authOverrides,
  )
}

describe('ProfileScreen', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the signed-in account and menu rows, including About', async () => {
    const user = userEvent.setup()
    renderScreen()

    expect(screen.getByRole('heading', { name: 'Profile' })).toBeInTheDocument()
    expect(
      screen.getByRole('img', { name: 'Profile image placeholder' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Sahan Perera')).toBeInTheDocument()
    expect(screen.getByText('sahan@example.com')).toBeInTheDocument()
    expect(
      screen.getByText('Signed in as sahan@example.com.'),
    ).toBeInTheDocument()

    for (const label of [
      'Cooking Preferences',
      'Saved Recipes',
      'History',
      'Settings',
      'About',
    ]) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument()
    }

    await user.click(screen.getByRole('button', { name: 'About' }))
    expect(screen.getByText('About destination')).toBeInTheDocument()
  })

  it('edits the local demo name with validation', async () => {
    const user = userEvent.setup()
    renderScreen({
      user: null,
      status: 'unauthenticated',
      isAuthenticated: false,
    })

    await user.click(screen.getByRole('button', { name: 'Edit profile' }))
    const nameInput = screen.getByLabelText('Full name')
    await user.clear(nameInput)
    await user.type(nameInput, 'A')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(await screen.findByText('Enter your full name')).toBeInTheDocument()

    await user.clear(nameInput)
    await user.type(nameInput, 'Demo Cook')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    expect(screen.getByText('Demo Cook')).toBeInTheDocument()
    expect(screen.getByText('Profile updated locally.')).toBeInTheDocument()

    const stored = JSON.parse(
      window.localStorage.getItem(APP_STATE_STORAGE_KEY) ?? '{}',
    )
    expect(stored.profile.fullName).toBe('Demo Cook')
  })

  it('signs out through auth context and returns to login', async () => {
    const user = userEvent.setup()
    let resolveLogout: () => void = () => {}
    const logout = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          resolveLogout = resolve
        }),
    )
    renderScreen({ logout })

    const signOut = screen.getByRole('button', { name: 'Sign out' })
    await user.click(signOut)

    expect(screen.getByText('Signing out…')).toBeInTheDocument()
    expect(signOut).toBeDisabled()
    expect(logout).toHaveBeenCalledTimes(1)

    resolveLogout()
    expect(await screen.findByText('Login destination')).toBeInTheDocument()
  })

  it('navigates from the menu to Cooking Preferences', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(
      screen.getByRole('button', { name: 'Cooking Preferences' }),
    )

    expect(screen.getByText('Preferences destination')).toBeInTheDocument()
  })
})
