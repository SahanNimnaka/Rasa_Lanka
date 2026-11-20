import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import {
  MemoryRouter,
  Route,
  Routes,
  useLocation,
  useParams,
} from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { COOKING_FLOW_STORAGE_KEY } from '../../context/cookingFlowStore'
import { RecipeDetailsScreen } from './RecipeDetailsScreen'

function CookingDestination() {
  const location = useLocation()
  const { recipeId } = useParams()
  const state = location.state as { servings?: number } | null

  return (
    <div data-testid="cooking-destination">
      {recipeId} / {state?.servings}
    </div>
  )
}

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/recipes/dhal-curry']}>
      <CookingFlowProvider>
        <Routes>
          <Route element={<RecipeDetailsScreen />} path="/recipes/:recipeId" />
          <Route element={<CookingDestination />} path="/cooking/:recipeId" />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
  )
}

function seedFlow() {
  sessionStorage.setItem(
    COOKING_FLOW_STORAGE_KEY,
    JSON.stringify({
      ingredients: [
        {
          id: 'demo-red-lentils',
          name: 'Red lentils',
          source: 'demo',
          quantity: '250',
          unit: 'g',
        },
      ],
      servings: 4,
      selectedRecipeId: 'dhal-curry',
      favoriteRecipeIds: [],
      stepIndex: 0,
    }),
  )
}

describe('RecipeDetailsScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
    seedFlow()
  })

  it('distinguishes available and missing ingredients with quantities', () => {
    renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Available Ingredients' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Missing Ingredients' }),
    ).toBeInTheDocument()
    expect(screen.getByText('250 g')).toBeInTheDocument()
    expect(screen.getByText('1 cup')).toBeInTheDocument()
    expect(screen.getByText('1 piece')).toBeInTheDocument()
    expect(screen.getByText('½ tsp')).toBeInTheDocument()
    expect(screen.getByText('1 sprig')).toBeInTheDocument()
    expect(screen.getByText('4 servings')).toBeInTheDocument()
  })

  it('switches between ingredients, instructions and honest video tabs', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('tab', { name: 'Instructions' }))
    expect(screen.getByText('Rinse the lentils')).toBeInTheDocument()

    await user.click(screen.getByRole('tab', { name: 'Video' }))
    expect(
      screen.getByRole('heading', { name: 'Video unavailable' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('tab', { name: 'Ingredients' }))
    expect(
      screen.getByRole('heading', { name: 'Available Ingredients' }),
    ).toBeInTheDocument()
  })

  it('starts cooking with the selected recipe and servings', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Start Cooking' }))

    expect(screen.getByTestId('cooking-destination')).toHaveTextContent(
      'dhal-curry / 4',
    )
  })
})
