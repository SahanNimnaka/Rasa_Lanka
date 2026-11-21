import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { AppRoutes } from './App'
import { mockApiAdapter } from './test/apiMock'
import { itemEnvelope, recipeDetailDto } from './test/queryFixtures'
import { makeAxiosResponse, setApiAdapter } from './test/queryTestUtils'

function renderRoutes(initialEntry: string) {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <AppRoutes />
    </MemoryRouter>,
  )
}

describe('Screens 16–23 routes', () => {
  beforeEach(() => {
    sessionStorage.clear()
    localStorage.clear()
    delete document.documentElement.dataset.theme
    document.documentElement.classList.remove('theme-dark')
    setApiAdapter(async (config) => {
      if (config.url === '/recipes/11') {
        return makeAxiosResponse(config, itemEnvelope(recipeDetailDto))
      }
      return mockApiAdapter(config)
    })
  })

  it.each([
    ['/cooking/11/guidance', 'Cooking Guidance'],
    ['/saved', 'Saved Recipes'],
    ['/search', 'Search and Filters'],
    ['/history', 'History'],
    ['/profile', 'Profile'],
    ['/preferences', 'Cooking Preferences'],
    ['/settings', 'Settings'],
    ['/about', 'About'],
  ])('renders %s as %s', async (route, heading) => {
    renderRoutes(route)

    expect(
      await screen.findByRole('heading', { name: heading }),
    ).toBeInTheDocument()
  })

  it('keeps the existing demo history entries visible', async () => {
    renderRoutes('/history')

    expect(
      await screen.findByRole('heading', { name: 'History' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Dhal Curry')).toBeInTheDocument()
    expect(screen.queryByText('Your history is empty')).not.toBeInTheDocument()
  })

  it('uses the bottom navigation between Home, Search, Saved and Profile', async () => {
    const user = userEvent.setup()
    renderRoutes('/profile')

    expect(
      await screen.findByRole('heading', { name: 'Profile' }),
    ).toBeInTheDocument()

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
