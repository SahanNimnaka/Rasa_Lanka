import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import {
  paginatedEnvelope,
  recipeCategoryDto,
  recipeSummaryDto,
} from '../../test/queryFixtures'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import type { RecipeSummaryDto } from '../../types/apiRecipes'
import { HomeScreen } from './HomeScreen'

function apiRecipe(overrides: Partial<RecipeSummaryDto> = {}): RecipeSummaryDto {
  return { ...recipeSummaryDto, ...overrides }
}

function RecipeDestination() {
  const { recipeId } = useParams()
  return <p>Recipe destination {recipeId}</p>
}

function renderHome() {
  const wrapper = createQueryWrapper()
  return render(
    <MemoryRouter initialEntries={['/home']}>
      <Routes>
        <Route element={<HomeScreen />} path="/home" />
        <Route element={<p>Camera destination</p>} path="/ingredients/camera" />
        <Route element={<p>Upload destination</p>} path="/ingredients/upload" />
        <Route element={<p>Search destination</p>} path="/search" />
        <Route element={<p>Saved destination</p>} path="/saved" />
        <Route element={<p>Profile destination</p>} path="/profile" />
        <Route element={<RecipeDestination />} path="/recipes/:recipeId" />
      </Routes>
    </MemoryRouter>,
    { wrapper },
  )
}

function mockApi(recipes: RecipeSummaryDto[]) {
  setApiAdapter(async (config) => {
    if (config.url === '/recipes') {
      return makeAxiosResponse(config, paginatedEnvelope(recipes))
    }
    if (config.url === '/recipe-categories') {
      return makeAxiosResponse(config, paginatedEnvelope([recipeCategoryDto]))
    }
    throw new Error(`Unexpected request: ${config.url}`)
  })
}

describe('HomeScreen', () => {
  it('loads and renders Laravel recipes with their real metadata', async () => {
    mockApi([
      apiRecipe(),
      apiRecipe({
        id: 12,
        name: 'Pol Sambol',
        recipe_category_id: null,
        recipe_category: undefined,
        total_time_minutes: null,
        region: null,
        main_ingredient: null,
      }),
    ])

    renderHome()

    expect(screen.getByRole('status')).toHaveTextContent('Loading recipes...')
    expect(
      await screen.findByRole('heading', {
        name: 'Sri Lankan Parippu Curry',
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Pol Sambol' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Parippu')).toBeInTheDocument()
    expect(screen.getByText('35 min')).toBeInTheDocument()
    expect(screen.queryByText('4.8')).not.toBeInTheDocument()
    expect(
      screen.queryByRole('img', { name: 'Dhal Curry' }),
    ).not.toBeInTheDocument()
  })

  it('shows an empty state when the API returns no recipes', async () => {
    mockApi([])

    renderHome()

    expect(
      await screen.findByText('No recipes are available yet.'),
    ).toBeInTheDocument()
  })

  it('shows an error state when the API request fails', async () => {
    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 500, 'SERVER_ERROR', 'Backend unavailable')
    })

    renderHome()

    expect(
      await screen.findByText('Recipes could not be loaded.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Backend unavailable')).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Try again' }),
    ).toBeInTheDocument()
  })

  it('navigates to recipe details using the backend ID', async () => {
    mockApi([apiRecipe()])
    const user = userEvent.setup()

    renderHome()

    await user.click(
      await screen.findByRole('button', {
        name: /Sri Lankan Parippu Curry/,
      }),
    )
    expect(screen.getByText('Recipe destination 11')).toBeInTheDocument()
  })

  it('keeps other home navigation intact', async () => {
    mockApi([apiRecipe()])
    const user = userEvent.setup()

    renderHome()

    await user.click(screen.getByRole('button', { name: /Scan Ingredients/ }))
    expect(screen.getByText('Camera destination')).toBeInTheDocument()
  })
})
