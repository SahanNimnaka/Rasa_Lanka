import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createElement } from 'react'
import { CookingFlowProvider } from './CookingFlowContext'
import {
  COOKING_FLOW_STORAGE_KEY,
  useCookingFlow,
} from './cookingFlowStore'

function FlowProbe() {
  const flow = useCookingFlow()

  return createElement(
    'div',
    null,
    createElement(
      'div',
      { 'data-testid': 'flow-ingredients' },
      flow.ingredients
        .map((ingredient) =>
          [
            ingredient.id,
            ingredient.name,
            ingredient.quantity,
            ingredient.unit,
            ingredient.preparation ?? '-',
            ingredient.isRequired ?? '-',
            ingredient.mappedFromDemoId ?? '-',
          ].join('|'),
        )
        .join(' | '),
    ),
    createElement(
      'div',
      { 'data-testid': 'flow-servings' },
      String(flow.servings),
    ),
    createElement('div', { 'data-testid': 'flow-step' }, String(flow.stepIndex)),
    createElement(
      'button',
      {
        onClick: () =>
          flow.setIngredients([
            { id: 'demo-red-lentils', name: 'Demo lentils', source: 'demo' },
            {
              id: 'manual-turmeric',
              name: 'Manual turmeric',
              source: 'manual',
            },
            {
              id: '82',
              name: 'Chilli flakes',
              source: 'catalogue',
              quantity: '3',
              unit: 'handful',
              preparation: 'crushed',
              isRequired: false,
            },
            {
              id: '82',
              name: 'Duplicate chilli flakes',
              source: 'catalogue',
              quantity: '9',
              unit: 'kg',
            },
          ]),
        type: 'button',
      },
      'Set mixed ingredients',
    ),
  )
}

function renderProbe() {
  render(createElement(CookingFlowProvider, null, createElement(FlowProbe)))
}

function ingredientLines(): string[] {
  return (screen.getByTestId('flow-ingredients').textContent ?? '')
    .split(' | ')
    .filter(Boolean)
}

describe('cookingFlowStore loading and setIngredients', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('ignores legacy IDs, dedupes numeric IDs and keeps free-text and optional metadata on load', () => {
    sessionStorage.setItem(
      COOKING_FLOW_STORAGE_KEY,
      JSON.stringify({
        ingredients: [
          {
            id: 'demo-red-lentils',
            name: 'Demo lentils',
            source: 'demo',
            quantity: '1',
            unit: 'g',
          },
          {
            id: 'manual-turmeric',
            name: 'Manual turmeric',
            source: 'manual',
            quantity: '1',
            unit: 'tsp',
          },
          {
            id: '81',
            name: '  Red lentils  ',
            source: 'catalogue',
            quantity: '250',
            unit: '  handful  ',
            preparation: 'soaked overnight',
            isRequired: false,
            mappedFromDemoId: 'demo-red-lentils',
          },
          {
            id: '081',
            name: 'Duplicate lentils',
            source: 'catalogue',
            quantity: '999',
            unit: 'kg',
          },
        ],
        servings: 4,
        favoriteRecipeIds: ['11'],
        stepIndex: 2,
      }),
    )

    renderProbe()

    expect(ingredientLines()).toEqual([
      '81|Red lentils|250|handful|soaked overnight|false|demo-red-lentils',
    ])
    expect(screen.getByTestId('flow-servings')).toHaveTextContent('4')
    expect(screen.getByTestId('flow-step')).toHaveTextContent('2')
  })

  it('rejects nonnumeric IDs in setIngredients and dedupes the rest', async () => {
    const user = userEvent.setup()
    sessionStorage.setItem(
      COOKING_FLOW_STORAGE_KEY,
      JSON.stringify({
        ingredients: [
          {
            id: '99',
            name: 'Old ingredient',
            source: 'catalogue',
            quantity: '1',
            unit: 'g',
          },
        ],
        stepIndex: 3,
      }),
    )

    renderProbe()
    await user.click(screen.getByRole('button', { name: 'Set mixed ingredients' }))

    expect(ingredientLines()).toEqual([
      '82|Chilli flakes|3|handful|crushed|false|-',
    ])
    expect(screen.getByTestId('flow-step')).toHaveTextContent('0')
  })
})
