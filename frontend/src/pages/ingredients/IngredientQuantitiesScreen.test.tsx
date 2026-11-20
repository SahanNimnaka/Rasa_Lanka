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
  { id: 'demo-red-lentils', name: 'Red lentils', source: 'demo' },
  { id: 'demo-coconut-milk', name: 'Coconut milk', source: 'demo' },
  { id: 'demo-onion', name: 'Onion', source: 'demo' },
  { id: 'manual-turmeric', name: 'Turmeric', source: 'manual' },
]

function ReceivedQuantities() {
  const location = useLocation()
  const ingredients =
    (location.state as IngredientQuantitiesState | null)?.ingredients ?? []

  return (
    <div data-testid="received-quantities">
      {ingredients
        .map((ingredient) => {
          const withQuantity = ingredient as IngredientWithQuantity
          return `${withQuantity.name} ${withQuantity.quantity} ${withQuantity.unit}`
        })
        .join(' | ')}
    </div>
  )
}

function renderScreen() {
  render(
    <MemoryRouter
      initialEntries={[
        {
          pathname: '/ingredients/quantities',
          state: { ingredients: selectedIngredients },
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

  it('uses data-driven default quantities and supports any selected ingredient', () => {
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

    // Manually added ingredients are handled by the same reusable card.
    expect(screen.getByLabelText('Quantity for Turmeric')).toHaveValue('0.5')
    expect(screen.getByLabelText('Unit for Turmeric')).toHaveValue('tsp')
  })

  it('edits quantities and units, then passes them to the next screen', async () => {
    const user = userEvent.setup()
    renderScreen()

    const quantity = screen.getByLabelText('Quantity for Red lentils')
    await user.clear(quantity)
    await user.type(quantity, '250')
    await user.selectOptions(
      screen.getByLabelText('Unit for Red lentils'),
      'kg',
    )

    expect(quantity).toHaveValue('250')
    expect(screen.getByLabelText('Unit for Red lentils')).toHaveValue('kg')

    await user.click(screen.getByRole('button', { name: 'Next' }))

    expect(screen.getByTestId('received-quantities')).toHaveTextContent(
      'Red lentils 250 kg',
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
