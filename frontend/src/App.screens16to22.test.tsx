import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useNavigate } from 'react-router-dom'
import { AppRoutes } from './App'
import {
  APP_STATE_STORAGE_KEY,
  createDefaultAppState,
} from './services/appStateService'

function NavigateToHistory() {
  const navigate = useNavigate()
  return (
    <button onClick={() => navigate('/history')} type="button">
      Open history
    </button>
  )
}

function renderRoutes(initialEntry: string, withHistoryControl = false) {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <AppRoutes />
      {withHistoryControl && <NavigateToHistory />}
    </MemoryRouter>,
  )
}

describe('Screens 16–22 routes', () => {
  beforeEach(() => {
    sessionStorage.clear()
    localStorage.clear()
    delete document.documentElement.dataset.theme
    document.documentElement.classList.remove('theme-dark')
  })

  it.each([
    ['/cooking/dhal-curry/guidance', 'Cooking Guidance'],
    ['/saved', 'Saved Recipes'],
    ['/search', 'Search and Filters'],
    ['/history', 'History'],
    ['/profile', 'Profile'],
    ['/preferences', 'Cooking Preferences'],
    ['/settings', 'Settings'],
  ])('renders %s as %s', (route, heading) => {
    renderRoutes(route)

    expect(screen.getByRole('heading', { name: heading })).toBeInTheDocument()
  })

  it('records real recipe opens in history', async () => {
    const user = userEvent.setup()
    const state = createDefaultAppState()
    state.history = []
    localStorage.setItem(APP_STATE_STORAGE_KEY, JSON.stringify(state))

    renderRoutes('/recipes/dhal-curry', true)

    expect(
      await screen.findByRole('button', { name: 'Start Cooking' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Open history' }))

    expect(
      await screen.findByRole('heading', { name: 'History' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Dhal Curry')).toBeInTheDocument()
    expect(screen.queryByText('Your history is empty')).not.toBeInTheDocument()
  })

  it('uses the bottom navigation between Home, Search, Saved and Profile', async () => {
    const user = userEvent.setup()
    renderRoutes('/profile')

    const navigation = screen.getByRole('navigation', {
      name: 'Primary navigation',
    })
    expect(navigation).toBeInTheDocument()

    await user.click(screen.getByRole('link', { name: 'Saved' }))
    expect(
      await screen.findByRole('heading', { name: 'Saved Recipes' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('link', { name: 'Home' }))
    expect(
      await screen.findByRole('heading', { name: 'Welcome, Sahan' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('link', { name: 'Search' }))
    expect(
      await screen.findByRole('heading', { name: 'Search and Filters' }),
    ).toBeInTheDocument()
  })
})
