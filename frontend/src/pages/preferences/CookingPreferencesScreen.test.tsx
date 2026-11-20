import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { AppStateProvider } from '../../context/AppStateContext'
import {
  APP_STATE_STORAGE_KEY,
  createDefaultAppState,
} from '../../services/appStateService'
import { CookingPreferencesScreen } from './CookingPreferencesScreen'

function renderScreen() {
  return render(
    <MemoryRouter initialEntries={['/preferences']}>
      <AppStateProvider>
        <CookingPreferencesScreen />
      </AppStateProvider>
    </MemoryRouter>,
  )
}

describe('CookingPreferencesScreen', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the approved defaults and saves changes locally', async () => {
    const user = userEvent.setup()
    const { unmount } = renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Cooking Preferences' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Choose how you like to cook.')).toBeInTheDocument()

    expect(screen.getByRole('button', { name: 'None' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(screen.getByRole('button', { name: 'Medium' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(screen.getByRole('button', { name: 'Sri Lankan' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )

    await user.click(screen.getByRole('button', { name: 'Vegan' }))
    await user.click(screen.getByRole('button', { name: 'Spicy' }))
    await user.click(screen.getByRole('button', { name: 'Other Asian' }))
    await user.click(screen.getByRole('button', { name: 'Save Preferences' }))

    expect(screen.getByText('Preferences saved locally.')).toBeInTheDocument()

    const stored = JSON.parse(
      window.localStorage.getItem(APP_STATE_STORAGE_KEY) ?? '{}',
    )
    expect(stored.preferences).toEqual({
      dietary: 'vegan',
      spice: 'spicy',
      cuisine: 'Other Asian',
    })

    unmount()
    renderScreen()

    expect(screen.getByRole('button', { name: 'Vegan' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(screen.getByRole('button', { name: 'Spicy' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(screen.getByRole('button', { name: 'Other Asian' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  it('restores previously saved preferences', () => {
    const state = createDefaultAppState()
    state.preferences = {
      dietary: 'vegetarian',
      spice: 'mild',
      cuisine: 'Other Asian',
    }
    localStorage.setItem(APP_STATE_STORAGE_KEY, JSON.stringify(state))

    renderScreen()

    expect(screen.getByRole('button', { name: 'Vegetarian' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(screen.getByRole('button', { name: 'Mild' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(screen.getByRole('button', { name: 'Other Asian' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })
})
