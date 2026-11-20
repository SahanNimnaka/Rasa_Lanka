import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { CookingInstructionsScreen } from './CookingInstructionsScreen'

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/cooking/dhal-curry']}>
      <CookingFlowProvider>
        <Routes>
          <Route
            element={<CookingInstructionsScreen />}
            path="/cooking/:recipeId"
          />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
  )
}

describe('CookingInstructionsScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('shows numbered data-driven steps and highlights the current step', () => {
    renderScreen()

    expect(
      screen.getByRole('heading', {
        name: 'Step-by-Step Cooking Instructions',
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('article', { name: 'Step 1: Rinse the lentils' }),
    ).toHaveAttribute('aria-current', 'step')
    expect(screen.getByRole('button', { name: 'Previous step' })).toBeDisabled()
  })

  it('navigates between steps, prevents going before the first and completes the recipe', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Next step' }))
    expect(
      screen.getByRole('article', { name: 'Step 2: Prepare the ingredients' }),
    ).toHaveAttribute('aria-current', 'step')
    expect(screen.getByRole('button', { name: 'Previous step' })).toBeEnabled()

    await user.click(screen.getByRole('button', { name: 'Previous step' }))
    expect(
      screen.getByRole('article', { name: 'Step 1: Rinse the lentils' }),
    ).toHaveAttribute('aria-current', 'step')

    await user.click(screen.getByRole('button', { name: 'Next step' }))
    await user.click(screen.getByRole('button', { name: 'Next step' }))
    expect(
      screen.getByRole('article', { name: 'Step 3: Simmer the curry' }),
    ).toHaveAttribute('aria-current', 'step')

    await user.click(screen.getByRole('button', { name: 'Finish recipe' }))
    expect(screen.getByText('Recipe complete!')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Finish recipe' })).toBeDisabled()
  })

  it('switches to ingredients and honest video tabs', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('tab', { name: 'Ingredients' }))
    expect(
      screen.getByRole('heading', { name: 'Available Ingredients' }),
    ).toBeInTheDocument()

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
})
