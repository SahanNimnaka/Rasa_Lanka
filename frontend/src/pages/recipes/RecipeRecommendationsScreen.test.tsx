import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { RecipeRecommendationsScreen } from './RecipeRecommendationsScreen'

function RecipeDestination() {
  const location = useLocation()
  const state = location.state as {
    recipeId?: string
    servings?: number
  } | null

  return (
    <div data-testid="recipe-destination">
      {state?.recipeId} / {state?.servings}
    </div>
  )
}

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/recipes/recommendations']}>
      <CookingFlowProvider>
        <Routes>
          <Route
            element={<RecipeRecommendationsScreen />}
            path="/recipes/recommendations"
          />
          <Route element={<RecipeDestination />} path="/recipes/:recipeId" />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
  )
}

describe('RecipeRecommendationsScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('shows the wireframe recipes with mock recommendation labels', () => {
    renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Recommended Recipes' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Recipes for 2 people')).toBeInTheDocument()
    expect(
      screen.getByText(/not AI-generated recommendations/),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Dhal Curry' }),
    ).toBeInTheDocument()
    expect(screen.getByText('90%')).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Coconut Roti' }),
    ).toBeInTheDocument()
    expect(screen.getByText('75%')).toBeInTheDocument()
  })

  it('sorts recipes with Best Match as the default', async () => {
    const user = userEvent.setup()
    renderScreen()

    const headings = () =>
      screen
        .getAllByRole('heading', { level: 3 })
        .map((item) => item.textContent)
    expect(headings()[0]).toBe('Dhal Curry')

    await user.selectOptions(
      screen.getByRole('combobox', { name: 'Sort recipes' }),
      'name',
    )
    expect(headings()[0]).toBe('Coconut Roti')
  })

  it('filters recipes and clears the filters', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Filters' }))
    await user.click(screen.getByLabelText('Under 30 minutes'))

    expect(
      screen.getByRole('heading', { name: 'Dhal Curry' }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('heading', { name: 'Coconut Roti' }),
    ).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Clear filters' }))
    expect(
      screen.getByRole('heading', { name: 'Coconut Roti' }),
    ).toBeInTheDocument()
  })

  it('toggles favourites and opens the selected recipe', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(
      screen.getByRole('button', { name: 'Add Dhal Curry to favourites' }),
    )
    expect(
      screen.getByRole('button', { name: 'Remove Dhal Curry from favourites' }),
    ).toHaveAttribute('aria-pressed', 'true')

    await user.click(screen.getByRole('button', { name: /^Dhal Curry/ }))
    expect(screen.getByTestId('recipe-destination')).toHaveTextContent(
      'dhal-curry / 2',
    )
  })
})
