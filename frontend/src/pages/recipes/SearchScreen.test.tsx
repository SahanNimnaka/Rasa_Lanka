import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AppStateProvider } from '../../context/AppStateContext'
import { APP_STATE_STORAGE_KEY } from '../../services/appStateService'
import { SearchScreen } from './SearchScreen'

function renderScreen() {
  return render(
    <MemoryRouter initialEntries={['/search']}>
      <AppStateProvider>
        <Routes>
          <Route element={<SearchScreen />} path="/search" />
          <Route
            element={<p>Recipe destination</p>}
            path="/recipes/:recipeId"
          />
        </Routes>
      </AppStateProvider>
    </MemoryRouter>,
  )
}

describe('SearchScreen', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the search field, filter groups and selected defaults', () => {
    renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Search and Filters' }),
    ).toBeInTheDocument()
    expect(
      screen.getByPlaceholderText('Search recipes or ingredients'),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('group', { name: 'Cuisine type' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('group', { name: 'Cooking time' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sri Lankan' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(screen.getByRole('button', { name: '15-30 min' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    expect(
      screen.getByRole('button', { name: 'Remove Red lentils' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Remove Coconut' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Remove Onion' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Apply Filters' }),
    ).toBeInTheDocument()
  })

  it('applies filters, shows the empty state and persists the selection', async () => {
    const user = userEvent.setup()
    const { unmount } = renderScreen()

    expect(screen.getByText('Dhal Curry')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Reset' }))
    await user.click(screen.getByRole('button', { name: 'Other Asian' }))
    await user.click(screen.getByRole('button', { name: 'Apply Filters' }))

    expect(
      screen.getByText('No recipes match these filters.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Filters applied.')).toBeInTheDocument()

    const stored = JSON.parse(
      window.localStorage.getItem(APP_STATE_STORAGE_KEY) ?? '{}',
    )
    expect(stored.searchFilters.cuisine).toBe('Other Asian')

    unmount()
    renderScreen()

    expect(
      screen.getByText('No recipes match these filters.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Other Asian' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  it('adds and removes ingredient chips', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Add Ingredient' }))
    await user.click(screen.getByRole('button', { name: 'Red chilli' }))

    expect(
      screen.getByRole('button', { name: 'Remove Red chilli' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Remove Red chilli' }))
    expect(
      screen.queryByRole('button', { name: 'Remove Red chilli' }),
    ).not.toBeInTheDocument()
  })

  it('resets all filters', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Reset' }))

    expect(
      screen.getByPlaceholderText('Search recipes or ingredients'),
    ).toHaveValue('')
    expect(screen.getByRole('button', { name: 'Sri Lankan' })).toHaveAttribute(
      'aria-pressed',
      'false',
    )
    expect(
      screen.queryByRole('button', { name: 'Remove Red lentils' }),
    ).not.toBeInTheDocument()
    expect(screen.getByText('Dhal Curry')).toBeInTheDocument()
    expect(screen.getByText('Pol Sambol')).toBeInTheDocument()
    expect(screen.getByText('Coconut Roti')).toBeInTheDocument()
  })
})
