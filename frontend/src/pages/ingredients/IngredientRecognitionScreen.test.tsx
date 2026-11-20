import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { IngredientRecognitionScreen } from './IngredientRecognitionScreen'
import type { IngredientQuantitiesState } from '../../types/ingredients'

function ReceivedIngredients() {
  const location = useLocation()
  const ingredients =
    (location.state as IngredientQuantitiesState | null)?.ingredients ?? []

  return (
    <div data-testid="received-ingredients">
      {ingredients.map((ingredient) => ingredient.name).join(', ')}
    </div>
  )
}

function renderRecognition() {
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
  )
}

describe('IngredientRecognitionScreen', () => {
  it('labels the wireframe results as demonstration data', () => {
    renderRecognition()

    expect(
      screen.getByText('Demo data — not a model result'),
    ).toBeInTheDocument()
    expect(
      screen.getByText(/no AI recognition model is connected/),
    ).toBeInTheDocument()
    expect(screen.getByText('Demo confidence: 98%')).toBeInTheDocument()
    expect(screen.getByText('Demo confidence: 95%')).toBeInTheDocument()
    expect(screen.getByText('Demo confidence: 92%')).toBeInTheDocument()
    expect(screen.getByAltText('Uploaded ingredients')).toBeInTheDocument()
  })

  it('selects and deselects recognized ingredients', async () => {
    const user = userEvent.setup()
    renderRecognition()

    expect(
      screen.getByRole('heading', { name: 'Final selection (3)' }),
    ).toBeInTheDocument()

    await user.click(
      screen.getByRole('checkbox', { name: 'Select Red lentils' }),
    )

    expect(
      screen.getByRole('checkbox', { name: 'Select Red lentils' }),
    ).not.toBeChecked()
    expect(
      screen.getByRole('heading', { name: 'Final selection (2)' }),
    ).toBeInTheDocument()

    await user.click(
      screen.getByRole('checkbox', { name: 'Select Red lentils' }),
    )
    expect(
      screen.getByRole('checkbox', { name: 'Select Red lentils' }),
    ).toBeChecked()
  })

  it('adds and removes manually entered ingredients', async () => {
    const user = userEvent.setup()
    renderRecognition()

    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )
    await user.type(screen.getByLabelText('New ingredient name'), 'Turmeric')
    await user.click(screen.getByRole('button', { name: 'Add ingredient' }))

    expect(
      screen.getByRole('checkbox', { name: 'Select Turmeric' }),
    ).toBeChecked()
    expect(
      screen.getByRole('heading', { name: 'Final selection (4)' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Remove Turmeric' }))

    expect(
      screen.queryByRole('checkbox', { name: 'Select Turmeric' }),
    ).not.toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Final selection (3)' }),
    ).toBeInTheDocument()
  })

  it('passes the final selection to the Screen 11 placeholder', async () => {
    const user = userEvent.setup()
    renderRecognition()

    await user.click(
      screen.getByRole('checkbox', { name: 'Select Coconut milk' }),
    )
    await user.click(
      screen.getByRole('button', { name: 'Add Ingredient Manually' }),
    )
    await user.type(screen.getByLabelText('New ingredient name'), 'Turmeric')
    await user.click(screen.getByRole('button', { name: 'Add ingredient' }))
    await user.click(screen.getByRole('button', { name: 'Confirm & Continue' }))

    expect(screen.getByTestId('received-ingredients')).toHaveTextContent(
      'Red lentils, Onion, Turmeric',
    )
    expect(screen.getByTestId('received-ingredients')).not.toHaveTextContent(
      'Coconut milk',
    )
  })
})
