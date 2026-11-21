import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import {
  MemoryRouter,
  Route,
  Routes,
  useLocation,
} from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { COOKING_FLOW_STORAGE_KEY } from '../../context/cookingFlowStore'
import { itemEnvelope, recipeDetailDto } from '../../test/queryFixtures'
import {
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import type {
  RecipeDetailDto,
  RecipeInstructionDto,
} from '../../types/apiRecipes'
import { CookingGuidanceScreen } from './CookingGuidanceScreen'

const DETAIL_ID = 11
const DETAIL_URL = `/recipes/${DETAIL_ID}`
const GUIDANCE_URL = `/cooking/${DETAIL_ID}/guidance`

function methodSummary(
  overrides: Partial<RecipeInstructionDto> = {},
): RecipeInstructionDto {
  return {
    id: 1,
    recipe_id: DETAIL_ID,
    step_number: 0,
    is_method_summary: true,
    cooking_method_paragraph: 'Simmer gently.',
    cooking_steps_ordered: 'Stir often.',
    instruction_text: null,
    ...overrides,
  }
}

function instruction(
  id: number,
  stepNumber: number,
  text: string,
): RecipeInstructionDto {
  return {
    id,
    recipe_id: DETAIL_ID,
    step_number: stepNumber,
    is_method_summary: false,
    cooking_method_paragraph: null,
    cooking_steps_ordered: null,
    instruction_text: text,
  }
}

function detail(overrides: Partial<RecipeDetailDto> = {}): RecipeDetailDto {
  return {
    ...recipeDetailDto,
    id: DETAIL_ID,
    method_summary: methodSummary(),
    instructions: [
      instruction(2, 1, 'Rinse the lentils.'),
      instruction(3, 2, 'Simmer until tender.'),
    ],
    ...overrides,
  }
}

function RecipeDestination() {
  const location = useLocation()
  const state = location.state as { tab?: string } | null

  return <p>Recipe destination: {state?.tab}</p>
}

function seedFlow(selectedRecipeId?: string, stepIndex = 0) {
  sessionStorage.setItem(
    COOKING_FLOW_STORAGE_KEY,
    JSON.stringify({
      ingredients: [],
      servings: 3,
      selectedRecipeId,
      favoriteRecipeIds: [],
      stepIndex,
    }),
  )
}

function renderScreen(initialEntry = GUIDANCE_URL) {
  const wrapper = createQueryWrapper()

  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <CookingFlowProvider>
        <Routes>
          <Route
            element={<CookingGuidanceScreen />}
            path="/cooking/:recipeId/guidance"
          />
          <Route element={<RecipeDestination />} path="/recipes/:recipeId" />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
    { wrapper },
  )
}

function mockDetail(payload: RecipeDetailDto) {
  setApiAdapter(async (config) => {
    if (config.url === DETAIL_URL) {
      return makeAxiosResponse(config, itemEnvelope(payload))
    }
    throw new Error(`Unexpected request: ${config.url}`)
  })
}

function storedFlow() {
  const raw = sessionStorage.getItem(COOKING_FLOW_STORAGE_KEY)
  return raw
    ? (JSON.parse(raw) as { selectedRecipeId?: string; stepIndex: number })
    : null
}

describe('CookingGuidanceScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('loads real recipe guidance from the API', async () => {
    mockDetail(detail())

    renderScreen()

    expect(
      await screen.findByRole('heading', {
        name: 'Sri Lankan Parippu Curry',
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Cooking Guidance' }),
    ).toBeInTheDocument()
    expect(screen.getByText('A coconut milk dhal curry.')).toBeInTheDocument()
    expect(screen.getByText('10 min')).toBeInTheDocument()
    expect(screen.getByText('25 min')).toBeInTheDocument()
    expect(screen.getByText('2 numbered instructions')).toBeInTheDocument()
    expect(screen.getByText(/Simmer gently\./)).toBeInTheDocument()
    expect(screen.getByText(/Stir often\./)).toBeInTheDocument()
    expect(
      screen.getByText('No video timeline is available.'),
    ).toBeInTheDocument()
    expect(screen.queryByText('01:20')).not.toBeInTheDocument()
    expect(screen.queryByText('08:30')).not.toBeInTheDocument()
    expect(
      screen.queryByText('Rinse lentils until the water runs clear.'),
    ).not.toBeInTheDocument()
  })

  it('shows a loading state while the API request is pending', async () => {
    setApiAdapter(() => new Promise<never>(() => {}))

    renderScreen()

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Loading cooking guidance...',
    )
  })

  it('shows an API error and recovers on retry', async () => {
    let shouldFail = true
    setApiAdapter(async (config) => {
      if (config.url === DETAIL_URL && shouldFail) {
        shouldFail = false
        throw makeAxiosError(config, 500, 'SERVER_ERROR', 'Backend unavailable')
      }
      if (config.url === DETAIL_URL) {
        return makeAxiosResponse(config, itemEnvelope(detail()))
      }
      throw new Error(`Unexpected request: ${config.url}`)
    })
    const user = userEvent.setup()

    renderScreen()

    expect(
      await screen.findByText('Guidance could not be loaded.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Backend unavailable')).toBeInTheDocument()
    expect(screen.queryByText('Dhal Curry')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Try again' }))
    expect(
      await screen.findByRole('heading', {
        name: 'Sri Lankan Parippu Curry',
      }),
    ).toBeInTheDocument()
  })

  it('shows a not-found state for a missing recipe', async () => {
    setApiAdapter(async (config) => {
      throw makeAxiosError(config, 404, 'NOT_FOUND', 'Recipe not found')
    })

    renderScreen()

    expect(await screen.findByText('Recipe not found.')).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Try again' }),
    ).not.toBeInTheDocument()
  })

  it('rejects non-numeric recipe IDs without calling the API', async () => {
    setApiAdapter(async (config) => {
      throw new Error(`Unexpected request: ${config.url}`)
    })

    renderScreen('/cooking/dhal-curry/guidance')

    expect(await screen.findByText('Invalid recipe ID.')).toBeInTheDocument()
    expect(
      screen.getByText(
        'This cooking link does not contain a valid numeric recipe ID.',
      ),
    ).toBeInTheDocument()
  })

  it('navigates from the ingredients tab to recipe ingredients', async () => {
    mockDetail(detail())
    const user = userEvent.setup()

    renderScreen()
    await screen.findByRole('heading', { name: 'Sri Lankan Parippu Curry' })
    await user.click(screen.getByRole('tab', { name: 'Ingredients' }))

    expect(
      screen.getByText('Recipe destination: ingredients'),
    ).toBeInTheDocument()
  })

  it('navigates from the instructions tab to written instructions', async () => {
    mockDetail(detail())
    const user = userEvent.setup()

    renderScreen()
    await screen.findByRole('heading', { name: 'Sri Lankan Parippu Curry' })
    await user.click(screen.getByRole('tab', { name: 'Instructions' }))

    expect(
      screen.getByText('Recipe destination: instructions'),
    ).toBeInTheDocument()
  })

  it('replaces a stale mock selection with the API recipe ID', async () => {
    seedFlow('dhal-curry', 2)
    mockDetail(detail())

    renderScreen()

    await screen.findByRole('heading', { name: 'Sri Lankan Parippu Curry' })
    await waitFor(() => {
      expect(storedFlow()).toMatchObject({
        selectedRecipeId: String(DETAIL_ID),
        stepIndex: 0,
      })
    })
  })
})
