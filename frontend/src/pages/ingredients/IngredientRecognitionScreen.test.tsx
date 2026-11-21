import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { COOKING_FLOW_STORAGE_KEY } from '../../context/cookingFlowStore'
import { getDemoRecognitionResults } from '../../services/recognitionService'
import { mockApiAdapter } from '../../test/apiMock'
import {
  ingredientCategoryDto,
  ingredientDetailDto,
  ingredientDto,
  itemEnvelope,
  paginatedEnvelope,
} from '../../test/queryFixtures'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import type {
  IngredientCategoryDto,
  IngredientDetailDto,
  IngredientDto,
} from '../../types/apiIngredients'
import type { IngredientQuantitiesState } from '../../types/ingredients'
import { IngredientRecognitionScreen } from './IngredientRecognitionScreen'

const IMAGE_DATA_URL = 'data:image/png;base64,AAAA'

const catalogueTurmeric: IngredientDto = {
  ...ingredientDto,
  id: 82,
  name: 'Laravel Turmeric Root',
  ingredient_key: 'laravel_turmeric_root',
}

const cataloguePepper: IngredientDto = {
  ...ingredientDto,
  id: 83,
  name: 'Laravel Black Peppercorn',
  ingredient_key: 'laravel_black_peppercorn',
}

const catalogueCardamom: IngredientDto = {
  ...ingredientDto,
  id: 91,
  name: 'Laravel Cardamom Pods',
  ingredient_key: 'laravel_cardamom_pods',
}

interface RecordedRequest {
  url: string
  params: Record<string, unknown>
}

interface MockCatalogueOptions {
  ingredients?: IngredientDto[]
  secondPageIngredients?: IngredientDto[]
  categories?: IngredientCategoryDto[]
  detail?: IngredientDetailDto
}

function apiPage<T>(data: T[], page = 1, lastPage = 1) {
  const envelope = paginatedEnvelope(data)
  return {
    ...envelope,
    links: {
      ...envelope.links,
      next:
        page < lastPage
          ? `http://localhost/api/v1/ingredients?page=${page + 1}`
          : null,
    },
    meta: {
      ...envelope.meta,
      pagination: {
        ...envelope.meta.pagination,
        current_page: page,
        last_page: lastPage,
        per_page: 10,
      },
    },
  }
}

function mockCatalogueApi(options: MockCatalogueOptions = {}) {
  const requests: RecordedRequest[] = []
  const state = { failIngredients: false }
  const ingredients = options.ingredients ?? [catalogueTurmeric, cataloguePepper]
  const secondPageIngredients = options.secondPageIngredients ?? []
  const detail = options.detail ?? ingredientDetailDto

  setApiAdapter(async (config) => {
    const url = config.url ?? ''
    const params = (config.params ?? {}) as Record<string, unknown>
    requests.push({ url, params })

    if (url === '/ingredients') {
      if (state.failIngredients) {
        throw makeAxiosError(config, 500, 'SERVER_ERROR', 'Backend unavailable')
      }
      const page = Number(params.page ?? 1)
      const rows = page === 1 ? ingredients : secondPageIngredients
      const search = String(params.search ?? '').trim().toLowerCase()
      const filtered = search
        ? rows.filter((row) => row.name.toLowerCase().includes(search))
        : rows
      return makeAxiosResponse(
        config,
        apiPage(filtered, page, secondPageIngredients.length > 0 ? 2 : 1),
      )
    }

    if (url === '/ingredient-categories') {
      return makeAxiosResponse(
        config,
        apiPage(options.categories ?? [ingredientCategoryDto]),
      )
    }

    const detailMatch = url.match(/^\/ingredients\/(\d+)$/)
    if (detailMatch) {
      return makeAxiosResponse(
        config,
        itemEnvelope({ ...detail, id: Number(detailMatch[1]) }),
      )
    }
    if (url.startsWith('/ingredients/')) {
      throw makeAxiosError(config, 404, 'NOT_FOUND', 'Ingredient not found')
    }

    throw new Error(`Unexpected request: ${url}`)
  })

  return { requests, state }
}

function ReceivedIngredients() {
  const location = useLocation()
  const ingredients =
    (location.state as IngredientQuantitiesState | null)?.ingredients ?? []

  return (
    <div data-testid="received-ingredients">
      {ingredients.map((ingredient) => (
        <span data-testid="received-ingredient" key={ingredient.id}>
          {ingredient.id}:{ingredient.name}
        </span>
      ))}
    </div>
  )
}

function renderRecognition() {
  const wrapper = createQueryWrapper()
  return render(
    <MemoryRouter
      initialEntries={[
        {
          pathname: '/ingredients/recognition',
          state: {
            image: {
              dataUrl: IMAGE_DATA_URL,
              name: 'ingredients.png',
              source: 'upload',
            },
          },
        },
      ]}
    >
      <CookingFlowProvider>
        <Routes>
          <Route
            element={<IngredientRecognitionScreen />}
            path="/ingredients/recognition"
          />
          <Route
            element={<ReceivedIngredients />}
            path="/ingredients/quantities"
          />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
    { wrapper },
  )
}

function storedIngredients(): unknown[] {
  const raw = sessionStorage.getItem(COOKING_FLOW_STORAGE_KEY) ?? '{}'
  const parsed = JSON.parse(raw) as { ingredients?: unknown[] }
  return parsed.ingredients ?? []
}

describe('IngredientRecognitionScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
    setApiAdapter(mockApiAdapter)
  })

  it('shows the router-state image, demo notice and demo detections', () => {
    mockCatalogueApi()
    const demos = getDemoRecognitionResults()

    renderRecognition()

    expect(screen.getByAltText('Uploaded ingredients')).toHaveAttribute(
      'src',
      IMAGE_DATA_URL,
    )
    expect(screen.getByText('Demo data — not a model result')).toBeInTheDocument()
    expect(
      screen.getByText(/no AI recognition model is connected/i),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/not catalogue ingredients until you map them/i),
    ).toBeInTheDocument()
    expect(demos.map((demo) => demo.id)).toEqual([
      'demo-red-lentils',
      'demo-coconut-milk',
      'demo-onion',
    ])
    for (const demo of demos) {
      expect(
        screen.getByRole('checkbox', { name: `Select ${demo.name}` }),
      ).toBeInTheDocument()
      expect(
        screen.getByRole('button', { name: `Map ${demo.name} to catalogue` }),
      ).toBeInTheDocument()
      expect(screen.getByText(`Demo confidence: ${demo.confidence}%`)).toBeInTheDocument()
    }
  })

  it('never persists demo IDs and warns when unmapped detections are selected', async () => {
    mockCatalogueApi()
    const user = userEvent.setup()

    renderRecognition()

    for (const demo of getDemoRecognitionResults()) {
      await user.click(
        screen.getByRole('checkbox', { name: `Select ${demo.name}` }),
      )
    }

    expect(
      screen.getByRole('status'),
    ).toHaveTextContent(
      /3 demo detections cannot be continued until mapped to a catalogue ingredient/i,
    )
    expect(
      screen.getByRole('heading', { name: 'Final selection (0)' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Confirm & Continue' }))

    expect(screen.getByTestId('received-ingredients')).toBeEmptyDOMElement()
    expect(storedIngredients()).toEqual([])
    expect(sessionStorage.getItem(COOKING_FLOW_STORAGE_KEY)).not.toContain(
      'demo-',
    )
  })

  it('opens a Laravel catalogue panel with numeric catalogue IDs', async () => {
    const { requests } = mockCatalogueApi()
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )

    expect(
      await screen.findByRole('checkbox', {
        name: 'Select Laravel Turmeric Root',
      }),
    ).toBeInTheDocument()
    expect(screen.getAllByText(/Laravel catalogue ingredient/).length).toBeGreaterThan(0)
    expect(
      screen.getByText('Laravel catalogue ingredient #82'),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', {
        name: 'Find an ingredient in the Laravel catalogue',
      }),
    ).toBeInTheDocument()
    await waitFor(() => {
      expect(requests).toContainEqual({
        url: '/ingredients',
        params: { page: 1, per_page: 10 },
      })
      expect(requests).toContainEqual({
        url: '/ingredient-categories',
        params: { page: 1, per_page: 100 },
      })
    })
  })

  it('filters catalogue results through config.params.search', async () => {
    const { requests } = mockCatalogueApi()
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )
    await screen.findByRole('checkbox', { name: 'Select Laravel Turmeric Root' })

    await user.type(
      screen.getByLabelText('Search ingredient catalogue'),
      'Pepper',
    )

    expect(
      await screen.findByRole('checkbox', {
        name: 'Select Laravel Black Peppercorn',
      }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('checkbox', { name: 'Select Laravel Turmeric Root' }),
    ).not.toBeInTheDocument()
    expect(
      requests.some(
        (request) =>
          request.url === '/ingredients' && request.params.search === 'Pepper',
      ),
    ).toBe(true)
  })

  it('loads categories and sends the selected category id to the catalogue query', async () => {
    const { requests } = mockCatalogueApi()
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )

    const categorySelect = await screen.findByLabelText('Category')
    expect(
      screen.getByRole('option', { name: 'Spice' }),
    ).toHaveValue(String(ingredientCategoryDto.id))

    await user.selectOptions(categorySelect, String(ingredientCategoryDto.id))

    await waitFor(() => {
      expect(
        requests.some(
          (request) =>
            request.url === '/ingredients' &&
            request.params.ingredient_category_id === 13,
        ),
      ).toBe(true)
    })
  })

  it('paginates catalogue results with Previous and Next controls', async () => {
    const { requests } = mockCatalogueApi({
      secondPageIngredients: [catalogueCardamom],
    })
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )
    await screen.findByRole('checkbox', { name: 'Select Laravel Turmeric Root' })

    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
    await user.click(screen.getByRole('button', { name: 'Next' }))

    expect(
      await screen.findByRole('checkbox', {
        name: 'Select Laravel Cardamom Pods',
      }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('checkbox', { name: 'Select Laravel Turmeric Root' }),
    ).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Previous' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
    expect(
      requests.some(
        (request) =>
          request.url === '/ingredients' && request.params.page === 2,
      ),
    ).toBe(true)
  })

  it('selects a real catalogue ingredient once and removes it without keeping a copy', async () => {
    mockCatalogueApi()
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )
    const catalogueCheckbox = await screen.findByRole('checkbox', {
      name: 'Select Laravel Turmeric Root',
    })

    await user.click(catalogueCheckbox)
    await user.click(catalogueCheckbox)
    expect(
      screen.getByRole('heading', { name: 'Final selection (1)' }),
    ).toBeInTheDocument()

    await user.click(
      screen.getByRole('button', { name: 'Remove Laravel Turmeric Root' }),
    )
    expect(
      screen.getByRole('heading', { name: 'Final selection (0)' }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('button', {
        name: 'Remove Laravel Turmeric Root',
      }),
    ).not.toBeInTheDocument()

    await user.click(catalogueCheckbox)
    await user.click(screen.getByRole('button', { name: 'Confirm & Continue' }))

    const received = screen.getAllByTestId('received-ingredient')
    expect(received).toHaveLength(1)
    expect(received[0]).toHaveTextContent('82:Laravel Turmeric Root')
    expect(received[0]).not.toHaveTextContent(/demo-|manual-/)
  })

  it('maps a demo detection to a catalogue row selected in mapping mode', async () => {
    mockCatalogueApi()
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Map Red lentils to catalogue' }),
    )

    expect(
      await screen.findByRole('heading', {
        name: 'Map Red lentils to catalogue',
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByLabelText('Catalogue ingredient ID'),
    ).toBeInTheDocument()

    await user.click(
      await screen.findByRole('checkbox', {
        name: 'Select Laravel Turmeric Root',
      }),
    )

    expect(
      screen.getByRole('button', { name: 'Map Red lentils to catalogue' }),
    ).toHaveTextContent('Change mapping')
    expect(screen.getByText('Mapped to Laravel Turmeric Root (#82)')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Confirm & Continue' }))

    const received = screen.getAllByTestId('received-ingredient')
    expect(received).toHaveLength(1)
    expect(received[0]).toHaveTextContent('82:Laravel Turmeric Root')
    expect(screen.getByTestId('received-ingredients')).not.toHaveTextContent(
      /demo-|manual-/,
    )
  })

  it('maps by numeric catalogue ID and rejects nonnumeric demo IDs', async () => {
    const { requests } = mockCatalogueApi()
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Map Coconut milk to catalogue' }),
    )
    const lookup = await screen.findByLabelText('Catalogue ingredient ID')

    await user.type(lookup, 'demo-red-lentils')
    expect(
      screen.getByText('Enter a positive numeric catalogue ID'),
    ).toBeInTheDocument()
    expect(
      requests.some(
        (request) => request.url === '/ingredients/demo-red-lentils',
      ),
    ).toBe(false)

    await user.clear(lookup)
    await user.type(lookup, '81')

    expect(
      await screen.findByRole('button', { name: 'Use this ingredient' }),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Red lentils (masoor dhal) (#81)'),
    ).toBeInTheDocument()
    await waitFor(() => {
      expect(
        requests.some((request) => request.url === '/ingredients/81'),
      ).toBe(true)
    })

    await user.click(screen.getByRole('button', { name: 'Use this ingredient' }))
    await user.click(screen.getByRole('button', { name: 'Confirm & Continue' }))

    const received = screen.getAllByTestId('received-ingredient')
    expect(received).toHaveLength(1)
    expect(received[0]).toHaveTextContent('81:Red lentils (masoor dhal)')
    expect(screen.getByTestId('received-ingredients')).not.toHaveTextContent(
      /demo-|manual-/,
    )
  })

  it('shows an empty state when no catalogue ingredients match', async () => {
    mockCatalogueApi({ ingredients: [] })
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )

    expect(
      await screen.findByText('No catalogue ingredients match this search.'),
    ).toBeInTheDocument()
  })

  it('shows the catalogue error and recovers with Try again', async () => {
    const { state } = mockCatalogueApi()
    state.failIngredients = true
    const user = userEvent.setup()

    renderRecognition()
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )

    expect(await screen.findByText('Catalogue unavailable.')).toBeInTheDocument()
    expect(screen.getByText('Backend unavailable')).toBeInTheDocument()

    state.failIngredients = false
    await user.click(screen.getByRole('button', { name: 'Try again' }))

    expect(
      await screen.findByRole('checkbox', {
        name: 'Select Laravel Turmeric Root',
      }),
    ).toBeInTheDocument()
  })
})
