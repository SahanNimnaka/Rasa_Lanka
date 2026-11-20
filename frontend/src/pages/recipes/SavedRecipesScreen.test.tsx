import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import {
  COOKING_FLOW_STORAGE_KEY,
  DEFAULT_COOKING_FLOW_STATE,
} from '../../context/cookingFlowStore'
import { SavedRecipesScreen } from './SavedRecipesScreen'

function seedFavorites(recipeIds: string[]) {
  sessionStorage.setItem(
    COOKING_FLOW_STORAGE_KEY,
    JSON.stringify({
      ...DEFAULT_COOKING_FLOW_STATE,
      favoriteRecipeIds: recipeIds,
    }),
  )
}

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/saved']}>
      <CookingFlowProvider>
        <Routes>
          <Route element={<SavedRecipesScreen />} path="/saved" />
          <Route element={<p>Search destination</p>} path="/search" />
          <Route
            element={<p>Recipe destination</p>}
            path="/recipes/:recipeId"
          />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
  )
}

describe('SavedRecipesScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('shows category filters, an empty state and bottom navigation', () => {
    renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Saved Recipes' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'All' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
    for (const label of ['Curries', 'Sambols', 'Others']) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument()
    }
    expect(screen.getByText('No saved recipes yet')).toBeInTheDocument()
    expect(
      screen.getByRole('navigation', { name: 'Primary navigation' }),
    ).toBeInTheDocument()
  })

  it('filters saved recipes by category', async () => {
    const user = userEvent.setup()
    seedFavorites(['dhal-curry', 'pol-sambol', 'coconut-roti'])
    renderScreen()

    expect(screen.getByText('Dhal Curry')).toBeInTheDocument()
    expect(screen.getByText('Pol Sambol')).toBeInTheDocument()
    expect(screen.getByText('Coconut Roti')).toBeInTheDocument()
    expect(
      screen.getAllByRole('button', {
        name: /^Remove .* from favourites$/,
      }),
    ).toHaveLength(3)

    await user.click(screen.getByRole('button', { name: 'Sambols' }))
    expect(screen.getByText('Pol Sambol')).toBeInTheDocument()
    expect(screen.queryByText('Dhal Curry')).not.toBeInTheDocument()
    expect(screen.queryByText('Coconut Roti')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Others' }))
    expect(screen.getByText('Coconut Roti')).toBeInTheDocument()
    expect(screen.queryByText('Pol Sambol')).not.toBeInTheDocument()
  })

  it('removes a favourite immediately', async () => {
    const user = userEvent.setup()
    seedFavorites(['dhal-curry', 'pol-sambol'])
    renderScreen()

    await user.click(
      screen.getByRole('button', {
        name: 'Remove Pol Sambol from favourites',
      }),
    )

    expect(screen.queryByText('Pol Sambol')).not.toBeInTheDocument()
    expect(screen.getByText('Dhal Curry')).toBeInTheDocument()
  })
})
