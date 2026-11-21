import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import type {
  IngredientQuantitiesState,
  IngredientWithQuantity,
  SelectedIngredient,
} from '../../types/ingredients'
import { IngredientQuantitiesScreen } from './IngredientQuantitiesScreen'

const selectedIngredients: SelectedIngredient[] = [
  { id: '81', name: 'Red lentils', source: 'catalogue' },
  { id: '82', name: 'Coconut milk', source: 'catalogue' },
  { id: '83', name: 'Onion', source: 'catalogue' },
  { id: '84', name: 'Turmeric', source: 'catalogue' },
]

function ReceivedQuantities() {
  const location = useLocation()
  const ingredients =
    (location.state as IngredientQuantitiesState | null)?.ingredients ?? []

  return (
    <>
      <div data-testid="received-quantities">
        {ingredients
          .map((ingredient) => {
            const withQuantity = ingredient as IngredientWithQuantity
            return `${withQuantity.name} ${withQuantity.quantity} ${withQuantity.unit}`
          })
          .join(' | ')}
      </div>
      <div data-testid="received-metadata">
        {ingredients
          .map((ingredient) => {
            const withQuantity = ingredient as IngredientWithQuantity
            return `${withQuantity.id}:${withQuantity.preparation ?? '-'}:${withQuantity.isRequired ?? '-'}`
          })
          .join(' | ')}
      </div>
    </>
  )
}

function renderScreen(ingredients: SelectedIngredient[] = selectedIngredients) {
  render(
    <MemoryRouter
      initialEntries={[
        {
          pathname: '/ingredients/quantities',
          state: { ingredients },
        },
      ]}
    >
      <CookingFlowProvider>
        <Routes>
          <Route
            element={<IngredientQuantitiesScreen />}
            path="/ingredients/quantities"
          />
          <Route
            element={<ReceivedQuantities />}
            path="/ingredients/serving-size"
          />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
  )
}

describe('IngredientQuantitiesScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('uses data-driven default quantities and supports any catalogue ingredient', () => {
    renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Enter Ingredient Quantities' }),
    ).toBeInTheDocument()
    expect(screen.getByLabelText('Quantity for Red lentils')).toHaveValue('200')
    expect(screen.getByLabelText('Unit for Red lentils')).toHaveValue('g')
    expect(screen.getByLabelText('Quantity for Coconut milk')).toHaveValue('1')
    expect(screen.getByLabelText('Unit for Coconut milk')).toHaveValue('cup')
    expect(screen.getByLabelText('Quantity for Onion')).toHaveValue('1')
    expect(screen.getByLabelText('Unit for Onion')).toHaveValue('piece')

    // A manually added catalogue ingredient uses the same reusable card.
    expect(screen.getByLabelText('Quantity for Turmeric')).toHaveValue('0.5')
    expect(screen.getByLabelText('Unit for Turmeric')).toHaveValue('tsp')
    expect(screen.getByText('Catalogue ingredient #81')).toBeInTheDocument()
    expect(screen.getByText('Catalogue ingredient #84')).toBeInTheDocument()
  })

  it('edits quantities and free-text units, then passes them to the next screen', async () => {
    const user = userEvent.setup()
    renderScreen()

    const quantity = screen.getByLabelText('Quantity for Red lentils')
    await user.clear(quantity)
    await user.type(quantity, '250')
    const unit = screen.getByLabelText('Unit for Red lentils')
    await user.clear(unit)
    await user.type(unit, 'kg')

    expect(quantity).toHaveValue('250')
    expect(unit).toHaveValue('kg')

    await user.click(screen.getByRole('button', { name: 'Next' }))

    expect(screen.getByTestId('received-quantities')).toHaveTextContent(
      'Red lentils 250 kg',
    )
  })

  it('accepts a custom free-text unit such as handful', async () => {
    const user = userEvent.setup()
    renderScreen()

    const quantity = screen.getByLabelText('Quantity for Red lentils')
    await user.clear(quantity)
    await user.type(quantity, '3')
    const unit = screen.getByLabelText('Unit for Red lentils')
    await user.clear(unit)
    await user.type(unit, 'handful')

    expect(unit).toHaveValue('handful')

    await user.click(screen.getByRole('button', { name: 'Next' }))

    expect(screen.getByTestId('received-quantities')).toHaveTextContent(
      'Red lentils 3 handful',
    )
  })

  it('preserves preparation and optional metadata in the route state', async () => {
    const user = userEvent.setup()
    renderScreen([
      {
        id: '85',
        name: 'Chilli flakes',
        source: 'catalogue',
        preparation: 'crushed finely',
        isRequired: false,
      },
    ])

    expect(screen.getByText('Catalogue ingredient #85')).toBeInTheDocument()
    expect(screen.getByText('Preparation: crushed finely')).toBeInTheDocument()
    expect(screen.getByText('Optional')).toBeInTheDocument()
    expect(screen.queryByText('Required')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Next' }))

    expect(screen.getByTestId('received-quantities')).toHaveTextContent(
      'Chilli flakes 1 piece',
    )
    expect(screen.getByTestId('received-metadata')).toHaveTextContent(
      '85:crushed finely:false',
    )
  })

  it('rejects empty, negative, non-numeric and zero quantities', async () => {
    const user = userEvent.setup()
    renderScreen()

    const quantity = screen.getByLabelText('Quantity for Red lentils')
    const nextButton = screen.getByRole('button', { name: 'Next' })

    await user.clear(quantity)
    expect(screen.getByText('Enter a quantity')).toBeInTheDocument()
    expect(nextButton).toBeDisabled()

    await user.type(quantity, '-5')
    expect(screen.getByText('Quantity cannot be negative')).toBeInTheDocument()
    expect(nextButton).toBeDisabled()

    await user.clear(quantity)
    await user.type(quantity, 'abc')
    expect(screen.getByText('Enter a valid number')).toBeInTheDocument()
    expect(nextButton).toBeDisabled()

    await user.clear(quantity)
    await user.type(quantity, '0')
    expect(
      screen.getByText('Quantity must be greater than zero'),
    ).toBeInTheDocument()
    expect(nextButton).toBeDisabled()
  })
})
