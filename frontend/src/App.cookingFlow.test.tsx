import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useNavigate } from 'react-router-dom'
import { AppRoutes } from './App'

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

  it('preserves quantities and servings across the flow and browser history', async () => {
    const user = userEvent.setup()
    renderFlow()

    await user.click(screen.getByRole('button', { name: 'Confirm & Continue' }))
    expect(
      await screen.findByRole('heading', {
        name: 'Enter Ingredient Quantities',
      }),
    ).toBeInTheDocument()

    const quantity = screen.getByLabelText('Quantity for Red lentils')
    await user.clear(quantity)
    await user.type(quantity, '250')

    await user.click(screen.getByRole('button', { name: 'History back' }))
    expect(
      await screen.findByRole('heading', { name: 'Detected Ingredients' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'History forward' }))
    expect(
      await screen.findByLabelText('Quantity for Red lentils'),
    ).toHaveValue('250')

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
