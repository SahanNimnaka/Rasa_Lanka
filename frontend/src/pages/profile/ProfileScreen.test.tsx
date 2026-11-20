import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AppStateProvider } from '../../context/AppStateContext'
import { APP_STATE_STORAGE_KEY } from '../../services/appStateService'
import { ProfileScreen } from './ProfileScreen'

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/profile']}>
      <AppStateProvider>
        <Routes>
          <Route element={<ProfileScreen />} path="/profile" />
          <Route element={<p>Preferences destination</p>} path="/preferences" />
          <Route element={<p>Saved destination</p>} path="/saved" />
          <Route element={<p>History destination</p>} path="/history" />
          <Route element={<p>Settings destination</p>} path="/settings" />
        </Routes>
      </AppStateProvider>
    </MemoryRouter>,
  )
}

describe('ProfileScreen', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the demo profile, menu rows and an honest About response', async () => {
    const user = userEvent.setup()
    renderScreen()

    expect(screen.getByRole('heading', { name: 'Profile' })).toBeInTheDocument()
    expect(
      screen.getByRole('img', { name: 'Profile image placeholder' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Sahan Nimnaka')).toBeInTheDocument()
    expect(
      screen.getByText('Demo profile — no account is signed in.'),
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
    expect(
      screen.getByText('About details are not included in this build.'),
    ).toBeInTheDocument()
  })

  it('edits the demo name locally with validation', async () => {
    const user = userEvent.setup()
    renderScreen()

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

  it('navigates from the menu to Cooking Preferences', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(
      screen.getByRole('button', { name: 'Cooking Preferences' }),
    )

    expect(screen.getByText('Preferences destination')).toBeInTheDocument()
  })
})
