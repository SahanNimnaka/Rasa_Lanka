import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useNavigate } from 'react-router-dom'
import { AppRoutes } from './App'
import { COOKING_FLOW_STORAGE_KEY } from './context/cookingFlowStore'
import { mockApiAdapter } from './test/apiMock'
import {
  ingredientCategoryDto,
  ingredientDetailDto,
  ingredientDto,
  itemEnvelope,
  paginatedEnvelope,
  recipeDetailDto,
  recipeSummaryDto,
} from './test/queryFixtures'
import { makeAxiosResponse, setApiAdapter } from './test/queryTestUtils'

function HistoryControls() {
  const navigate = useNavigate()

  return (
    <div>
      <button onClick={() => navigate(-1)} type="button">
        History back
      </button>
      <button onClick={() => navigate(1)} type="button">
        History forward
      </button>
    </div>
  )
}

function renderFlow() {
  render(
    <MemoryRouter
      initialEntries={[
        {
          pathname: '/ingredients/recognition',
          state: {
            image: {
              dataUrl: 'data:image/png;base64,AAAA',
              name: 'ingredients.png',
              source: 'upload',
            },
          },
        },
      ]}
    >
      <AppRoutes />
      <HistoryControls />
    </MemoryRouter>,
  )
}

describe('Screens 11–15 cooking flow', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('preserves catalogued quantities and servings across the flow and browser history', async () => {
    setApiAdapter(async (config) => {
      const url = config.url ?? ''
      if (url === '/ingredients') {
        return makeAxiosResponse(config, paginatedEnvelope([ingredientDto]))
      }
      if (url === '/ingredient-categories') {
        return makeAxiosResponse(
          config,
          paginatedEnvelope([ingredientCategoryDto]),
        )
      }
      if (url === '/ingredients/81') {
        return makeAxiosResponse(config, itemEnvelope(ingredientDetailDto))
      }
      if (url === '/recipes') {
        return makeAxiosResponse(
          config,
          paginatedEnvelope([
            {
              ...recipeSummaryDto,
              id: 11,
              name: 'Dhal Curry',
              total_time_minutes: 25,
            },
          ]),
        )
      }
      if (url === '/recipes/11') {
        return makeAxiosResponse(
          config,
          itemEnvelope({
            ...recipeDetailDto,
            id: 11,
            name: 'Dhal Curry',
            total_time_minutes: 25,
          }),
        )
      }
      return mockApiAdapter(config)
    })

    const user = userEvent.setup()
    renderFlow()

    // Demo detections are no longer auto-selected. A real catalogue ID is
    // required before the flow can continue.
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )
    const catalogueCheckbox = await screen.findByRole('checkbox', {
      name: 'Select Red lentils (masoor dhal)',
    })
    await user.click(catalogueCheckbox)
    expect(catalogueCheckbox).toBeChecked()
    expect(
      screen.getByRole('heading', { name: 'Final selection (1)' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Confirm & Continue' }))
    expect(
      await screen.findByRole('heading', {
        name: 'Enter Ingredient Quantities',
      }),
    ).toBeInTheDocument()
    expect(screen.getByText('Catalogue ingredient #81')).toBeInTheDocument()

    const quantity = screen.getByLabelText(
      'Quantity for Red lentils (masoor dhal)',
    )
    await user.clear(quantity)
    await user.type(quantity, '250')
    const unit = screen.getByLabelText('Unit for Red lentils (masoor dhal)')
    await user.clear(unit)
    await user.type(unit, 'g')

    await user.click(screen.getByRole('button', { name: 'History back' }))
    expect(
      await screen.findByRole('heading', { name: 'Detected Ingredients' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'History forward' }))
    expect(
      await screen.findByLabelText('Quantity for Red lentils (masoor dhal)'),
    ).toHaveValue('250')
    expect(
      screen.getByLabelText('Unit for Red lentils (masoor dhal)'),
    ).toHaveValue('g')

    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(
      await screen.findByRole('heading', { name: 'Serving Size' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Increase servings' }))
    expect(screen.getByTestId('serving-count')).toHaveTextContent('3')

    await user.click(screen.getByRole('button', { name: 'Find Recipes' }))
    expect(
      await screen.findByRole('heading', { name: 'Recommended Recipes' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Recipes for 3 people')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /^Dhal Curry/ }))
    expect(
      await screen.findByRole('button', { name: 'Start Cooking' }),
    ).toBeInTheDocument()
    expect(screen.getByText('250 g')).toBeInTheDocument()
    expect(screen.getByText('3 servings')).toBeInTheDocument()

    const storedFlow = JSON.parse(
      sessionStorage.getItem(COOKING_FLOW_STORAGE_KEY) ?? '{}',
    ) as {
      ingredients: Array<{ id: string; quantity: string; unit: string }>
      servings: number
    }
    expect(storedFlow.ingredients).toHaveLength(1)
    expect(storedFlow.ingredients[0]).toMatchObject({
      id: '81',
      quantity: '250',
      unit: 'g',
    })
    expect(storedFlow.servings).toBe(3)

    await user.click(screen.getByRole('button', { name: 'Start Cooking' }))
    expect(
      await screen.findByRole('heading', {
        name: 'Step-by-Step Cooking Instructions',
      }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'History back' }))
    expect(
      await screen.findByRole('button', { name: 'Start Cooking' }),
    ).toBeInTheDocument()
    expect(screen.getByText('3 servings')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'History forward' }))
    expect(
      await screen.findByRole('heading', {
        name: 'Step-by-Step Cooking Instructions',
      }),
    ).toBeInTheDocument()
  })
})
