import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import {
  COOKING_FLOW_STORAGE_KEY,
  useCookingFlow,
} from '../../context/cookingFlowStore'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import {
  ingredientDto,
  paginatedEnvelope,
  recipeDetailDto,
  recipeSummaryDto,
} from '../../test/queryFixtures'
import type { RecipeDetailDto } from '../../types/apiRecipes'
import { RecipeRecommendationsScreen } from './RecipeRecommendationsScreen'

function apiSummary(
  id: number,
  name: string,
  totalTime = 20,
) {
  return {
    ...recipeSummaryDto,
    id,
    name,
    total_time_minutes: totalTime,
  }
}

function apiDetail(
  id: number,
  name: string,
  ingredientNames: string[],
): RecipeDetailDto {
  const baseIngredient = recipeDetailDto.recipe_ingredients[0]
  return {
    ...recipeDetailDto,
    id,
    name,
    recipe_ingredients: ingredientNames.map((ingredientName, index) => ({
      ...baseIngredient,
      id: 1000 + id * 10 + index,
      recipe_id: id,
      original_ingredient_name: ingredientName,
      ingredient: {
        ...ingredientDto,
        id: 2000 + id * 10 + index,
        name: ingredientName,
      },
    })),
    instructions: [],
    recipe_sources: [],
    data_quality_issues: [],
  }
}

function FlowProbe() {
  const flow = useCookingFlow()
  const ingredients = flow.ingredients
    .map((item) => `${item.id}:${item.name}:${item.quantity} ${item.unit}`)
    .join(', ')
  return <div data-testid="flow-probe">{`${ingredients} | ${flow.servings}`}</div>
}

function RecipeDestination() {
  const { recipeId } = useParams()
  return <p>Recipe destination {recipeId}</p>
}

function renderScreen() {
  const wrapper = createQueryWrapper()
  return render(
    <MemoryRouter initialEntries={['/recipes/recommendations']}>
      <CookingFlowProvider>
        <FlowProbe />
        <Routes>
          <Route
            element={<RecipeRecommendationsScreen />}
            path="/recipes/recommendations"
          />
          <Route element={<RecipeDestination />} path="/recipes/:recipeId" />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
    { wrapper },
  )
}

function seedFlow() {
  sessionStorage.setItem(
    COOKING_FLOW_STORAGE_KEY,
    JSON.stringify({
      ingredients: [
        {
          id: '81',
          name: 'Red lentils',
          source: 'catalogue',
          quantity: '200',
          unit: 'g',
        },
        {
          id: '82',
          name: 'Coconut',
          source: 'catalogue',
          quantity: '1',
          unit: 'cup',
        },
      ],
      servings: 4,
      favoriteRecipeIds: [],
      stepIndex: 0,
    }),
  )
}

function mockRecommendationApi() {
  const summaries = [
    apiSummary(12, 'Partial Match Curry', 20),
    apiSummary(11, 'Full Match Curry', 25),
    apiSummary(13, 'Slow Curry', 45),
  ]
  const details = new Map<number, RecipeDetailDto>([
    [
      11,
      apiDetail(11, 'Full Match Curry', ['Red lentils', 'Coconut']),
    ],
    [12, apiDetail(12, 'Partial Match Curry', ['Red lentils', 'Pepper'])],
    [13, apiDetail(13, 'Slow Curry', ['Red lentils'])],
  ])

  setApiAdapter(async (config) => {
    if (config.url === '/recipes') {
      return makeAxiosResponse(config, paginatedEnvelope(summaries))
    }
    const match = /^\/recipes\/(\d+)$/.exec(config.url ?? '')
    if (match) {
      const detail = details.get(Number(match[1]))
      if (detail) {
        return makeAxiosResponse(config, { success: true, data: detail, meta: { api_version: 'v1' } })
      }
      throw makeAxiosError(config, 404, 'NOT_FOUND', 'Recipe not found')
    }
    throw new Error(`Unexpected request: ${config.url}`)
  })
}

describe('RecipeRecommendationsScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
    seedFlow()
  })

  it('uses Laravel recipe data without mock percentages or ratings', async () => {
    mockRecommendationApi()

    renderScreen()

    expect(await screen.findByText('Full Match Curry')).toBeInTheDocument()
    expect(screen.getByText('Partial Match Curry')).toBeInTheDocument()
    expect(screen.getByText('Slow Curry')).toBeInTheDocument()
    expect(screen.queryByText('90%')).not.toBeInTheDocument()
    expect(screen.queryByText('75%')).not.toBeInTheDocument()
    expect(screen.queryByText('4.8')).not.toBeInTheDocument()
    expect(
      screen.getByText(/not AI-generated recommendations/),
    ).toBeInTheDocument()
    expect(screen.getByTestId('flow-probe')).toHaveTextContent(
      '81:Red lentils:200 g, 82:Coconut:1 cup | 4',
    )
  })

  it('ranks by locally derived ingredient overlap from API details', async () => {
    mockRecommendationApi()

    renderScreen()

    await waitFor(() => {
      const headings = screen
        .getAllByRole('heading', { level: 3 })
        .map((item) => item.textContent)
      expect(headings[0]).toBe('Full Match Curry')
    })
    expect(screen.getByText(/2 matching ingredients/)).toBeInTheDocument()
  })

  it('keeps filtering and sorting behavior with API candidates', async () => {
    mockRecommendationApi()
    const user = userEvent.setup()

    renderScreen()
    await screen.findByText('Full Match Curry')

    await user.click(screen.getByRole('button', { name: 'Filters' }))
    await user.click(screen.getByLabelText('Under 30 minutes'))

    expect(screen.getByText('Full Match Curry')).toBeInTheDocument()
    expect(screen.getByText('Partial Match Curry')).toBeInTheDocument()
    expect(screen.queryByText('Slow Curry')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Clear filters' }))
    expect(screen.getByText('Slow Curry')).toBeInTheDocument()

    await user.selectOptions(
      screen.getByRole('combobox', { name: 'Sort recipes' }),
      'name',
    )
    const headings = screen
      .getAllByRole('heading', { level: 3 })
      .map((item) => item.textContent)
    expect(headings[0]).toBe('Full Match Curry')
  })

  it('navigates with backend IDs and preserves favourites and servings', async () => {
    mockRecommendationApi()
    const user = userEvent.setup()

    renderScreen()
    await screen.findByText('Full Match Curry')

    await user.click(
      screen.getByRole('button', {
        name: 'Add Full Match Curry to favourites',
      }),
    )
    expect(
      screen.getByRole('button', {
        name: 'Remove Full Match Curry from favourites',
      }),
    ).toHaveAttribute('aria-pressed', 'true')

    await user.click(
      screen.getByRole('button', { name: /^Full Match Curry/ }),
    )
    expect(screen.getByTestId('flow-probe')).toHaveTextContent('| 4')
    expect(screen.getByText('Recipe destination 11')).toBeInTheDocument()
  })

  it('shows an error state when the catalogue request fails', async () => {
    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 500, 'SERVER_ERROR', 'Backend unavailable')
    })

    renderScreen()

    expect(
      await screen.findByText('Recipe catalogue unavailable.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Backend unavailable')).toBeInTheDocument()
  })

  it('still renders summaries when detail data is missing', async () => {
    setApiAdapter(async (config) => {
      if (config.url === '/recipes') {
        return makeAxiosResponse(
          config,
          paginatedEnvelope([apiSummary(99, 'Detail Missing Recipe')]),
        )
      }
      throw makeAxiosError(config, 404, 'NOT_FOUND', 'Recipe not found')
    })

    renderScreen()

    expect(
      await screen.findByText('Detail Missing Recipe'),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/Ingredient overlap could not be loaded/),
    ).toBeInTheDocument()
  })
})
