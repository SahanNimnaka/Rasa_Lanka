import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'
import { AppStateProvider } from '../../context/AppStateContext'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import {
  ingredientDto,
  paginatedEnvelope,
  recipeCategoryDto,
  recipeSummaryDto,
} from '../../test/queryFixtures'
import { APP_STATE_STORAGE_KEY } from '../../services/appStateService'
import type { IngredientDto } from '../../types/apiIngredients'
import type { RecipeCategoryDto, RecipeSummaryDto } from '../../types/apiRecipes'
import { SearchScreen } from './SearchScreen'

interface SearchApiOptions {
  recipes?: RecipeSummaryDto[]
  secondPageRecipes?: RecipeSummaryDto[]
  categories?: RecipeCategoryDto[]
  ingredients?: IngredientDto[]
}

function apiPage<T>(data: T[], page = 1, lastPage = 1) {
  const envelope = paginatedEnvelope(data)
  return {
    ...envelope,
    links: {
      ...envelope.links,
      next: page < lastPage ? `http://localhost/recipes?page=${page + 1}` : null,
    },
    meta: {
      ...envelope.meta,
      pagination: {
        ...envelope.meta.pagination,
        current_page: page,
        last_page: lastPage,
      },
    },
  }
}

function apiRecipe(overrides: Partial<RecipeSummaryDto> = {}): RecipeSummaryDto {
  return {
    ...recipeSummaryDto,
    total_time_minutes: 20,
    ...overrides,
  }
}

function RecipeDestination() {
  const { recipeId } = useParams()
  return <p>Recipe destination {recipeId}</p>
}

function renderScreen() {
  const wrapper = createQueryWrapper()
  return render(
    <MemoryRouter initialEntries={['/search']}>
      <AppStateProvider>
        <Routes>
          <Route element={<SearchScreen />} path="/search" />
          <Route element={<RecipeDestination />} path="/recipes/:recipeId" />
        </Routes>
      </AppStateProvider>
    </MemoryRouter>,
    { wrapper },
  )
}

const extraIngredient: IngredientDto = {
  ...ingredientDto,
  id: 91,
  name: 'Black pepper',
  ingredient_key: 'black_pepper',
}

function mockSearchApi(options: SearchApiOptions = {}) {
  const recipeParams: Array<Record<string, unknown>> = []
  const recipes = options.recipes ?? [apiRecipe()]
  const secondPageRecipes = options.secondPageRecipes ?? []

  setApiAdapter(async (config) => {
    if (config.url === '/recipes') {
      const params = (config.params ?? {}) as Record<string, unknown>
      recipeParams.push(params)
      const page = Number(params.page ?? 1)
      const rows = page === 1 ? recipes : secondPageRecipes
      const filtered = params.search
        ? rows.filter((recipe) =>
            recipe.name.toLowerCase().includes(String(params.search).toLowerCase()),
          )
        : rows
      return makeAxiosResponse(
        config,
        apiPage(filtered, page, secondPageRecipes.length > 0 ? 2 : 1),
      )
    }
    if (config.url === '/recipe-categories') {
      return makeAxiosResponse(
        config,
        paginatedEnvelope(options.categories ?? [recipeCategoryDto]),
      )
    }
    if (config.url === '/ingredients') {
      return makeAxiosResponse(
        config,
        paginatedEnvelope(options.ingredients ?? [ingredientDto, extraIngredient]),
      )
    }
    throw new Error(`Unexpected request: ${config.url}`)
  })

  return recipeParams
}

describe('SearchScreen', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('loads recipes, categories and API ingredient options', async () => {
    mockSearchApi({ ingredients: [ingredientDto, extraIngredient] })

    renderScreen()

    expect(
      await screen.findByRole('heading', { name: 'Sri Lankan Parippu Curry' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Parippu' }),
    ).toBeInTheDocument()
    expect(screen.queryByText('4.8')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Add Ingredient' }))
    expect(
      await screen.findByRole('button', { name: 'Black pepper' }),
    ).toBeInTheDocument()
  })

  it('debounces the search input before requesting recipes', async () => {
    const recipeParams = mockSearchApi({
      recipes: [apiRecipe({ name: 'Dhal Curry' })],
    })
    const user = userEvent.setup()

    renderScreen()
    await screen.findByText('Dhal Curry')

    await user.type(
      screen.getByPlaceholderText('Search recipes or ingredients'),
      'curry',
    )

    expect(
      recipeParams.some((params) => params.search === 'curry'),
    ).toBe(false)

    await waitFor(
      () => {
        expect(
          recipeParams.some((params) => params.search === 'curry'),
        ).toBe(true)
      },
      { timeout: 2000 },
    )
  })

  it('applies API category and ingredient filters', async () => {
    const recipeParams = mockSearchApi({
      recipes: [apiRecipe({ id: 22, name: 'Category Recipe' })],
    })
    const user = userEvent.setup()

    renderScreen()
    await screen.findByText('Category Recipe')

    await user.click(screen.getByRole('button', { name: 'Parippu' }))
    await user.click(screen.getByRole('button', { name: 'Apply Filters' }))

    await waitFor(() => {
      const filteredRequest = recipeParams.find(
        (params) => params.recipe_category_id === 5,
      )
      expect(filteredRequest).toMatchObject({
        recipe_category_id: 5,
        ingredient_id: 81,
      })
    })
    expect(screen.getByText('Filters applied.')).toBeInTheDocument()
  })

  it('paginates API results', async () => {
    const recipeParams = mockSearchApi({
      recipes: [apiRecipe({ id: 31, name: 'First Page Recipe' })],
      secondPageRecipes: [apiRecipe({ id: 32, name: 'Second Page Recipe' })],
    })
    const user = userEvent.setup()

    renderScreen()
    expect(await screen.findByText('First Page Recipe')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Next/ }))

    expect(await screen.findByText('Second Page Recipe')).toBeInTheDocument()
    expect(recipeParams.some((params) => params.page === 2)).toBe(true)
  })

  it('shows an empty state when the API returns no recipes', async () => {
    mockSearchApi({ recipes: [] })

    renderScreen()

    expect(
      await screen.findByText('No recipes match these filters.'),
    ).toBeInTheDocument()
  })

  it('shows and recovers from API errors', async () => {
    let shouldFail = true
    setApiAdapter(async (config) => {
      if (config.url === '/recipes' && shouldFail) {
        throw makeAxiosError(config, 500, 'SERVER_ERROR', 'Backend unavailable')
      }
      if (config.url === '/recipes') {
        return makeAxiosResponse(config, paginatedEnvelope([apiRecipe()]))
      }
      if (config.url === '/recipe-categories') {
        return makeAxiosResponse(config, paginatedEnvelope([recipeCategoryDto]))
      }
      if (config.url === '/ingredients') {
        return makeAxiosResponse(config, paginatedEnvelope([ingredientDto]))
      }
      throw new Error(`Unexpected request: ${config.url}`)
    })
    const user = userEvent.setup()

    renderScreen()

    expect(await screen.findByText('Recipe search failed.')).toBeInTheDocument()
    shouldFail = false
    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(
      await screen.findByText('Sri Lankan Parippu Curry'),
    ).toBeInTheDocument()
  })

  it('navigates with the backend recipe ID and preserves stored filters', async () => {
    mockSearchApi({ recipes: [apiRecipe({ id: 44, name: 'Navigable Recipe' })] })
    const user = userEvent.setup()

    renderScreen()

    await user.click(await screen.findByRole('button', { name: /Navigable Recipe/ }))
    expect(screen.getByText('Recipe destination 44')).toBeInTheDocument()

    const stored = JSON.parse(
      window.localStorage.getItem(APP_STATE_STORAGE_KEY) ?? '{}',
    )
    expect(stored.searchFilters).toMatchObject({
      cookingTime: '15-30',
      ingredients: ['Red lentils', 'Coconut', 'Onion'],
    })
  })
})
