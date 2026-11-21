import { beforeEach, describe, expect, it } from 'vitest'
import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import {
  MemoryRouter,
  Route,
  Routes,
  useLocation,
  useParams,
} from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import {
  COOKING_FLOW_STORAGE_KEY,
  useCookingFlow,
} from '../../context/cookingFlowStore'
import {
  itemEnvelope,
  ingredientDto,
  recipeDetailDto,
} from '../../test/queryFixtures'
import {
  createTestQueryClient,
  createQueryWrapper,
  makeAxiosError,
  makeAxiosResponse,
  setApiAdapter,
} from '../../test/queryTestUtils'
import { queryKeys } from '../../services/queries/queryKeys'
import type {
  DataQualityIssueDto,
  RecipeDetailDto,
  RecipeIngredientDto,
  RecipeInstructionDto,
  RecipeSourceDto,
} from '../../types/apiRecipes'
import { RecipeDetailsScreen } from './RecipeDetailsScreen'

const DETAIL_ID = 11
const DETAIL_URL = `/recipes/${DETAIL_ID}`

function detail(overrides: Partial<RecipeDetailDto> = {}): RecipeDetailDto {
  return { ...recipeDetailDto, id: DETAIL_ID, ...overrides }
}

function ingredientRow(
  overrides: Partial<RecipeIngredientDto> = {},
): RecipeIngredientDto {
  return { ...recipeDetailDto.recipe_ingredients[0], ...overrides }
}

function instruction(
  overrides: Partial<RecipeInstructionDto> = {},
): RecipeInstructionDto {
  return { ...recipeDetailDto.instructions[0], ...overrides }
}

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

function FlowControls() {
  const flow = useCookingFlow()

  return (
    <div>
      <button onClick={() => flow.setServings(6)} type="button">
        Change servings
      </button>
      <button
        onClick={() =>
          flow.updateIngredientQuantity('demo-red-lentils', {
            quantity: '333',
          })
        }
        type="button"
      >
        Change local quantity
      </button>
    </div>
  )
}

function renderScreen(initialEntry = DETAIL_URL, withFlowControls = false) {
  const queryClient = createTestQueryClient()
  const wrapper = createQueryWrapper(queryClient)

  const result = render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <CookingFlowProvider>
        {withFlowControls && <FlowControls />}
        <Routes>
          <Route element={<RecipeDetailsScreen />} path="/recipes/:recipeId" />
          <Route element={<CookingDestination />} path="/cooking/:recipeId" />
          <Route
            element={<p>Recommendations destination</p>}
            path="/recipes/recommendations"
          />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
    { wrapper },
  )

  return { ...result, queryClient }
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
      selectedRecipeId: String(DETAIL_ID),
      favoriteRecipeIds: [],
      stepIndex: 0,
    }),
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

describe('RecipeDetailsScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
    seedFlow()
  })

  it('rejects non-numeric recipe IDs without calling the API', async () => {
    setApiAdapter(async (config) => {
      throw new Error(`Unexpected request: ${config.url}`)
    })

    renderScreen('/recipes/dhal-curry')

    expect(
      await screen.findByText('Invalid recipe ID.'),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        'This recipe link does not contain a valid numeric recipe ID.',
      ),
    ).toBeInTheDocument()
  })

  it('loads and renders the real Laravel recipe detail', async () => {
    mockDetail(detail())

    renderScreen()

    expect(
      await screen.findByRole('heading', {
        name: 'Sri Lankan Parippu Curry',
      }),
    ).toBeInTheDocument()
    expect(screen.getByText('Parippu')).toBeInTheDocument()
    expect(screen.getAllByText('Red lentils').length).toBeGreaterThan(0)
    expect(screen.getByText('Dhal curry')).toBeInTheDocument()
    expect(screen.getByText('Sri Lanka')).toBeInTheDocument()
    expect(screen.getByText('A coconut milk dhal curry.')).toBeInTheDocument()
    expect(screen.getByText('10 min')).toBeInTheDocument()
    expect(screen.getByText('25 min')).toBeInTheDocument()
    expect(screen.getByText('35 min total')).toBeInTheDocument()
    expect(screen.getByText('2.5')).toBeInTheDocument()
    expect(screen.getByText('2.50')).toBeInTheDocument()
    expect(screen.queryByText('4.8')).not.toBeInTheDocument()
    expect(
      screen.queryByRole('img', { name: 'Sri Lankan Parippu Curry' }),
    ).not.toBeInTheDocument()
  })

  it('shows a loading state while the API request is pending', async () => {
    setApiAdapter(() => new Promise<never>(() => {}))

    renderScreen()

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Loading recipe...',
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
      await screen.findByText('Recipe could not be loaded.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Backend unavailable')).toBeInTheDocument()

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
    expect(screen.getByText('Recipe not found')).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'Try again' }),
    ).not.toBeInTheDocument()
  })

  it('renders missing optional fields with explicit placeholders', async () => {
    mockDetail(
      detail({
        name: 'Minimal Recipe',
        recipe_category_id: null,
        recipe_category: null,
        main_ingredient: null,
        recipe_type: null,
        region: null,
        description: null,
        preparation_time_minutes: null,
        cooking_time_minutes: null,
        total_time_minutes: null,
        source_yield: null,
        yield_people: null,
        time_arithmetic_status: null,
        recipe_ingredients: [],
        method_summary: null,
        instructions: [],
        recipe_sources: [],
        data_quality_issues: [],
      }),
    )

    renderScreen()

    expect(
      await screen.findByRole('heading', { name: 'Minimal Recipe' }),
    ).toBeInTheDocument()
    expect(screen.getAllByText('Not provided').length).toBeGreaterThan(0)
    expect(
      screen.getByText('No ingredient rows are provided for this recipe.'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('No recipe sources are provided.'),
    ).toBeInTheDocument()
  })

  it('displays the original source quantity and unit verbatim', async () => {
    mockDetail(
      detail({
        recipe_ingredients: [
          ingredientRow({
            original_quantity_text: '½ tsp',
            original_unit: 'tsp',
            per_person_quantity_min: null,
            per_person_quantity_max: null,
            per_person_quantity_description: null,
            per_person_unit: null,
            expert_approved_quantity: null,
            expert_approved_unit: null,
            approval_status: null,
          }),
        ],
      }),
    )

    renderScreen()

    expect(await screen.findByText('Original quantity:')).toBeInTheDocument()
    expect(screen.getByText('½ tsp')).toBeInTheDocument()
    expect(screen.getByText('Original unit:')).toBeInTheDocument()
    expect(screen.getByText('tsp')).toBeInTheDocument()
  })

  it('keeps pending expert values distinct from approvals', async () => {
    mockDetail(
      detail({
        recipe_ingredients: [
          ingredientRow({
            original_quantity_text: '200 g',
            original_unit: 'g',
            expert_approved_quantity: '175',
            expert_approved_unit: 'g',
            approval_status: 'Pending expert validation',
            quantity_is_derived: true,
          }),
        ],
      }),
    )

    renderScreen()

    expect(
      await screen.findByText('Expert values, not approved:'),
    ).toBeInTheDocument()
    expect(screen.getByText('175 g')).toBeInTheDocument()
    expect(screen.getByText('Pending expert validation')).toBeInTheDocument()
    expect(screen.queryByText('Expert approved:')).not.toBeInTheDocument()
  })

  it('shows estimated and derived quantity flags', async () => {
    mockDetail(
      detail({
        recipe_ingredients: [
          ingredientRow({
            quantity_is_derived: true,
            quantity_description_is_estimated: true,
            quantity_depends_on_estimated_yield: true,
          }),
        ],
      }),
    )

    renderScreen()

    expect(await screen.findByText('Quantity derived')).toBeInTheDocument()
    expect(
      screen.getByText('Estimated quantity description'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Derived from estimated yield'),
    ).toBeInTheDocument()
  })

  it('renders the method summary and API step numbers', async () => {
    mockDetail(
      detail({
        method_summary: instruction({
          id: 99,
          step_number: 0,
          is_method_summary: true,
          cooking_method_paragraph: 'Simmer gently.',
          cooking_steps_ordered: 'Stir often.',
          instruction_text: null,
        }),
        instructions: [
          instruction({
            id: 2,
            step_number: 2,
            instruction_text: 'Add coconut milk.',
          }),
          instruction({
            id: 1,
            step_number: 1,
            instruction_text: 'Rinse the lentils.',
          }),
        ],
      }),
    )
    const user = userEvent.setup()

    renderScreen()
    await screen.findByRole('heading', {
      name: 'Sri Lankan Parippu Curry',
    })
    await user.click(screen.getByRole('tab', { name: 'Instructions' }))

    expect(
      await screen.findByText(/Simmer gently\./),
    ).toBeInTheDocument()
    expect(screen.getByText(/Stir often\./)).toBeInTheDocument()
    expect(screen.getByText('Rinse the lentils.')).toBeInTheDocument()
    expect(screen.getByText('Add coconut milk.')).toBeInTheDocument()
    const numbers = screen
      .getAllByText(/^\d{2}$/)
      .map((node) => node.textContent)
    expect(numbers).toContain('01')
    expect(numbers).toContain('02')
  })

  it('preserves repeated ingredient rows with their own source values', async () => {
    mockDetail(
      detail({
        recipe_ingredients: [
          ingredientRow({
            id: 301,
            ingredient_id: 301,
            ingredient: { ...ingredientDto, id: 301, name: 'Coconut milk' },
            original_ingredient_name: 'Coconut milk',
            original_quantity_text: '1 cup',
            original_unit: 'cup',
          }),
          ingredientRow({
            id: 302,
            ingredient_id: 302,
            ingredient: { ...ingredientDto, id: 302, name: 'Coconut milk' },
            original_ingredient_name: 'Coconut milk',
            original_quantity_text: '2 tbsp',
            original_unit: 'tbsp',
          }),
        ],
      }),
    )

    renderScreen()

    expect(
      await screen.findAllByRole('heading', { name: 'Coconut milk' }),
    ).toHaveLength(2)
    expect(screen.getByText('1 cup')).toBeInTheDocument()
    expect(screen.getByText('2 tbsp')).toBeInTheDocument()
  })

  it('displays recipe sources', async () => {
    const source: RecipeSourceDto = {
      id: 7,
      source_name: 'Rasa Lanka Book',
      source_identifier: 'BOOK',
      source_record_identifier: 'PAR-001',
      source_location: 'Colombo',
      source_uri: 'https://example.test/recipes/par-001',
      attribution: 'Rasa Lanka editorial team',
      notes: 'Checked against the printed edition.',
    }
    mockDetail(detail({ recipe_sources: [source] }))

    renderScreen()

    const link = await screen.findByRole('link', { name: 'Rasa Lanka Book' })
    expect(link).toHaveAttribute('href', source.source_uri ?? '')
    expect(screen.getByText('PAR-001')).toBeInTheDocument()
    expect(screen.getByText('Rasa Lanka editorial team')).toBeInTheDocument()
    expect(
      screen.getByText('Checked against the printed edition.'),
    ).toBeInTheDocument()
  })

  it('shows data-quality warnings without hiding the source quantity', async () => {
    const issue: DataQualityIssueDto = {
      id: 4,
      source_file: 'recipes.csv',
      source_row: 18,
      entity_type: 'recipe_ingredient',
      entity_reference: 'PAR-001',
      field_name: 'original_quantity_text',
      severity: 'warning',
      issue_type: 'ESTIMATED_QUANTITY',
      description: 'Quantity text could not be parsed.',
      recommended_action: 'Verify against the printed source.',
      resolution_status: 'Open',
      recipe_id: DETAIL_ID,
      ingredient_id: 81,
    }
    mockDetail(
      detail({
        recipe_ingredients: [
          ingredientRow({ original_quantity_text: 'a generous pinch' }),
        ],
        data_quality_issues: [issue],
      }),
    )

    renderScreen()

    expect(await screen.findByText(/a generous pinch/)).toBeInTheDocument()
    expect(screen.getByText('original_quantity_text · warning')).toBeInTheDocument()
    expect(
      screen.getByText('Quantity text could not be parsed.'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Verify against the printed source.'),
    ).toBeInTheDocument()
  })

  it('preserves selected servings and locally entered quantities', async () => {
    mockDetail(detail())

    renderScreen()

    expect(await screen.findByText('4 servings')).toBeInTheDocument()
    expect(screen.getByText('Your quantity:')).toBeInTheDocument()
    expect(screen.getByText('250 g')).toBeInTheDocument()
    expect(screen.getByText('200 g')).toBeInTheDocument()
  })

  it('keeps serving and local quantity choices when recipe data refetches', async () => {
    let requestCount = 0
    setApiAdapter(async (config) => {
      if (config.url === DETAIL_URL) {
        requestCount += 1
        return makeAxiosResponse(
          config,
          itemEnvelope(
            detail({
              name: requestCount === 1 ? 'Initial Recipe' : 'Refetched Recipe',
            }),
          ),
        )
      }
      throw new Error(`Unexpected request: ${config.url}`)
    })
    const user = userEvent.setup()
    const { queryClient } = renderScreen(DETAIL_URL, true)

    expect(
      await screen.findByRole('heading', { name: 'Initial Recipe' }),
    ).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Change servings' }))
    await user.click(
      screen.getByRole('button', { name: 'Change local quantity' }),
    )

    expect(screen.getByText('6 servings')).toBeInTheDocument()
    expect(screen.getByText('333 g')).toBeInTheDocument()

    await act(async () => {
      await queryClient.invalidateQueries({
        queryKey: queryKeys.recipes.detail(DETAIL_ID),
      })
    })

    expect(
      await screen.findByRole('heading', { name: 'Refetched Recipe' }),
    ).toBeInTheDocument()
    expect(screen.getByText('6 servings')).toBeInTheDocument()
    expect(screen.getByText('333 g')).toBeInTheDocument()
    expect(requestCount).toBe(2)
  })

  it('navigates to cooking with the backend ID and saved servings', async () => {
    mockDetail(detail())
    const user = userEvent.setup()

    renderScreen()

    await user.click(
      await screen.findByRole('button', { name: 'Start Cooking' }),
    )
    expect(screen.getByTestId('cooking-destination')).toHaveTextContent(
      '11 / 4',
    )
  })
})
