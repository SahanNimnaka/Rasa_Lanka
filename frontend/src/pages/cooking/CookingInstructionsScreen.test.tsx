import { beforeEach, describe, expect, it } from 'vitest'
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import {
  MemoryRouter,
  Route,
  Routes,
  useNavigate,
} from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { COOKING_FLOW_STORAGE_KEY } from '../../context/cookingFlowStore'
import { queryKeys } from '../../services/queries/queryKeys'
import { itemEnvelope, recipeDetailDto } from '../../test/queryFixtures'
import {
  createQueryWrapper,
  createTestQueryClient,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import type {
  RecipeDetailDto,
  RecipeInstructionDto,
} from '../../types/apiRecipes'
import { CookingInstructionsScreen } from './CookingInstructionsScreen'

const DETAIL_ID = 11
const DETAIL_URL = `/recipes/${DETAIL_ID}`
const COOKING_URL = `/cooking/${DETAIL_ID}`

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

function NavigateControl() {
  const navigate = useNavigate()

  return (
    <button onClick={() => navigate('/cooking/12')} type="button">
      Go to recipe 12
    </button>
  )
}

function seedFlow(flow: Record<string, unknown> = {}) {
  sessionStorage.setItem(
    COOKING_FLOW_STORAGE_KEY,
    JSON.stringify({
      ingredients: [],
      servings: 3,
      selectedRecipeId: String(DETAIL_ID),
      favoriteRecipeIds: [],
      stepIndex: 0,
      ...flow,
    }),
  )
}

function renderScreen(initialEntry = COOKING_URL) {
  const queryClient = createTestQueryClient()
  const wrapper = createQueryWrapper(queryClient)

  const result = render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <CookingFlowProvider>
        <Routes>
          <Route
            element={<CookingInstructionsScreen />}
            path="/cooking/:recipeId"
          />
        </Routes>
        <NavigateControl />
      </CookingFlowProvider>
    </MemoryRouter>,
    { wrapper },
  )

  return { ...result, queryClient }
}

function mockDetail(payload: RecipeDetailDto) {
  setApiAdapter(async (config) => {
    const url = `/recipes/${payload.id}`
    if (config.url === url) {
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

async function waitForStoredFlow(
  selectedRecipeId: string,
  stepIndex: number,
): Promise<void> {
  await waitFor(() => {
    expect(storedFlow()).toMatchObject({ selectedRecipeId, stepIndex })
  })
}

describe('CookingInstructionsScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('loads real API instructions and displays the method summary separately', async () => {
    mockDetail(detail())

    renderScreen()

    expect(
      await screen.findByRole('heading', {
        name: 'Sri Lankan Parippu Curry',
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', {
        name: 'Step-by-Step Cooking Instructions',
      }),
    ).toBeInTheDocument()
    expect(screen.getByText(/Simmer gently\./)).toBeInTheDocument()
    expect(screen.getByText(/Stir often\./)).toBeInTheDocument()
    expect(
      screen.getByRole('article', { name: 'Step 1: Rinse the lentils.' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('article', { name: 'Step 2: Simmer until tender.' }),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('article', { name: /Step 0/ }),
    ).not.toBeInTheDocument()
  })

  it('preserves API step numbers and ordering while excluding step 0', async () => {
    mockDetail(
      detail({
        instructions: [
          instruction(3, 3, 'Add coconut milk.'),
          instruction(1, 1, 'Rinse the lentils.'),
          instruction(4, 0, 'Method text that is not a cooking step.'),
          instruction(2, 2, 'Slice the onion.'),
        ],
      }),
    )

    renderScreen()

    expect(await screen.findAllByRole('article')).toHaveLength(3)
    expect(
      screen
        .getAllByRole('article')
        .map((node) => node.getAttribute('aria-label')),
    ).toEqual([
      'Step 1: Rinse the lentils.',
      'Step 2: Slice the onion.',
      'Step 3: Add coconut milk.',
    ])
    expect(
      screen.queryByText('Method text that is not a cooking step.'),
    ).not.toBeInTheDocument()
  })

  it('handles a recipe with no numbered instructions', async () => {
    mockDetail(detail({ instructions: [], method_summary: null }))

    renderScreen()

    expect(
      await screen.findByText(
        'No numbered instructions are provided for this recipe.',
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Previous step' }),
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: /Next step|Finish recipe/ }),
    ).not.toBeInTheDocument()
  })

  it('shows a loading state while the API request is pending', async () => {
    setApiAdapter(() => new Promise<never>(() => {}))

    renderScreen()

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Loading cooking instructions...',
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
      await screen.findByText('Instructions could not be loaded.'),
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

    renderScreen('/cooking/dhal-curry')

    expect(await screen.findByText('Invalid recipe ID.')).toBeInTheDocument()
  })

  it('navigates between steps and completes the recipe', async () => {
    mockDetail(detail())
    const user = userEvent.setup()

    renderScreen()

    expect(
      await screen.findByRole('article', { name: 'Step 1: Rinse the lentils.' }),
    ).toHaveAttribute('aria-current', 'step')
    expect(screen.getByRole('button', { name: 'Previous step' })).toBeDisabled()

    await user.click(screen.getByRole('button', { name: 'Next step' }))
    expect(
      screen.getByRole('article', { name: 'Step 2: Simmer until tender.' }),
    ).toHaveAttribute('aria-current', 'step')
    expect(screen.getByRole('button', { name: 'Previous step' })).toBeEnabled()

    await user.click(screen.getByRole('button', { name: 'Previous step' }))
    expect(
      screen.getByRole('article', { name: 'Step 1: Rinse the lentils.' }),
    ).toHaveAttribute('aria-current', 'step')

    await user.click(screen.getByRole('button', { name: 'Next step' }))
    await user.click(screen.getByRole('button', { name: 'Finish recipe' }))
    expect(screen.getByText('Recipe complete!')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Finish recipe' })).toBeDisabled()
  })

  it('switches to API ingredients and honest video tabs', async () => {
    mockDetail(detail())
    const user = userEvent.setup()

    renderScreen()
    await screen.findByRole('heading', { name: 'Sri Lankan Parippu Curry' })

    await user.click(screen.getByRole('tab', { name: 'Ingredients' }))
    expect(screen.getByText('Original quantity:')).toBeInTheDocument()

    await user.click(screen.getByRole('tab', { name: 'Video' }))
    expect(
      screen.getByRole('heading', { name: 'Video unavailable' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('tab', { name: 'Instructions' }))
    expect(
      screen.getByRole('heading', {
        name: 'Step-by-Step Cooking Instructions',
      }),
    ).toBeInTheDocument()
  })

  it('preserves a valid step index across load and background refetch', async () => {
    seedFlow({ stepIndex: 1 })
    mockDetail(detail())
    const { queryClient } = renderScreen()

    expect(
      await screen.findByRole('article', { name: 'Step 2: Simmer until tender.' }),
    ).toHaveAttribute('aria-current', 'step')
    expect(storedFlow()?.stepIndex).toBe(1)

    await act(async () => {
      await queryClient.refetchQueries({
        queryKey: queryKeys.recipes.detail(DETAIL_ID),
      })
    })

    expect(
      screen.getByRole('article', { name: 'Step 2: Simmer until tender.' }),
    ).toHaveAttribute('aria-current', 'step')
    expect(storedFlow()).toMatchObject({
      selectedRecipeId: String(DETAIL_ID),
      stepIndex: 1,
    })
  })

  it('clamps an out-of-range stored step index', async () => {
    seedFlow({ stepIndex: 9 })
    mockDetail(detail())

    renderScreen()

    expect(
      await screen.findByRole('article', { name: 'Step 2: Simmer until tender.' }),
    ).toHaveAttribute('aria-current', 'step')
    await waitForStoredFlow(String(DETAIL_ID), 1)
  })

  it('resets progress and selected recipe when the route recipe changes', async () => {
    seedFlow({ stepIndex: 1 })
    setApiAdapter(async (config) => {
      if (config.url === DETAIL_URL) {
        return makeAxiosResponse(config, itemEnvelope(detail()))
      }
      if (config.url === '/recipes/12') {
        return makeAxiosResponse(
          config,
          itemEnvelope(
            detail({
              id: 12,
              name: 'Recipe Twelve',
              instructions: [instruction(12, 1, 'First step for twelve.')],
            }),
          ),
        )
      }
      throw new Error(`Unexpected request: ${config.url}`)
    })
    const user = userEvent.setup()

    renderScreen()

    expect(
      await screen.findByRole('article', { name: 'Step 2: Simmer until tender.' }),
    ).toHaveAttribute('aria-current', 'step')

    await user.click(screen.getByRole('button', { name: 'Go to recipe 12' }))

    expect(
      await screen.findByRole('heading', { name: 'Recipe Twelve' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('article', { name: 'Step 1: First step for twelve.' }),
    ).toHaveAttribute('aria-current', 'step')
    await waitForStoredFlow('12', 0)
  })

  it('replaces a stale mock selection without crashing', async () => {
    seedFlow({ selectedRecipeId: 'dhal-curry', stepIndex: 2 })
    mockDetail(detail())

    renderScreen()

    expect(
      await screen.findByRole('article', { name: 'Step 1: Rinse the lentils.' }),
    ).toHaveAttribute('aria-current', 'step')
    await waitForStoredFlow(String(DETAIL_ID), 0)
  })

  it('shows locally entered quantities alongside API values', async () => {
    seedFlow({
      ingredients: [
        {
          id: '81',
          name: 'Red lentils',
          source: 'catalogue',
          quantity: '250',
          unit: 'g',
        },
      ],
    })
    mockDetail(detail())
    const user = userEvent.setup()

    renderScreen()
    await screen.findByRole('heading', { name: 'Sri Lankan Parippu Curry' })
    await user.click(screen.getByRole('tab', { name: 'Ingredients' }))

    expect(screen.getByText('Original quantity:')).toBeInTheDocument()
    expect(screen.getByText('200 g')).toBeInTheDocument()
    expect(screen.getByText('Your quantity:')).toBeInTheDocument()
    expect(screen.getByText('250 g')).toBeInTheDocument()
  })
})
