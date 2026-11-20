import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { ServingSizeScreen } from './ServingSizeScreen'

function ReceivedServings() {
  const location = useLocation()
  const state = location.state as { servings?: number } | null

  return <div data-testid="received-servings">{state?.servings}</div>
}

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/ingredients/serving-size']}>
      <CookingFlowProvider>
        <Routes>
          <Route
            element={<ServingSizeScreen />}
            path="/ingredients/serving-size"
          />
          <Route
            element={<ReceivedServings />}
            path="/recipes/recommendations"
          />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
  )
}

describe('ServingSizeScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('defaults to two servings and supports plus/minus controls with a lower bound', async () => {
    const user = userEvent.setup()
    renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Serving Size' }),
    ).toBeInTheDocument()
    expect(screen.getByTestId('serving-count')).toHaveTextContent('2')

    await user.click(screen.getByRole('button', { name: 'Increase servings' }))
    expect(screen.getByTestId('serving-count')).toHaveTextContent('3')

    await user.click(screen.getByRole('button', { name: 'Decrease servings' }))
    expect(screen.getByTestId('serving-count')).toHaveTextContent('2')

    await user.click(screen.getByRole('button', { name: 'Decrease servings' }))
    expect(screen.getByTestId('serving-count')).toHaveTextContent('1')
    expect(
      screen.getByRole('button', { name: 'Decrease servings' }),
    ).toBeDisabled()
  })

  it('supports quick selection including 6+ and preserves the value on navigation', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(
      screen.getByRole('button', { name: 'Select 6 or more servings' }),
    )

    expect(screen.getByTestId('serving-count')).toHaveTextContent('6')
    expect(
      screen.getByRole('button', { name: 'Select 6 or more servings' }),
    ).toHaveAttribute('aria-pressed', 'true')

    await user.click(screen.getByRole('button', { name: 'Find Recipes' }))
    expect(screen.getByTestId('received-servings')).toHaveTextContent('6')
  })
})
