import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { CookingFlowProvider } from '../../context/CookingFlowContext'
import { CookingGuidanceScreen } from './CookingGuidanceScreen'

function RecipeDestination() {
  const location = useLocation()
  const state = location.state as { tab?: string } | null

  return <p>Recipe destination: {state?.tab}</p>
}

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/cooking/dhal-curry/guidance']}>
      <CookingFlowProvider>
        <Routes>
          <Route
            element={<CookingGuidanceScreen />}
            path="/cooking/:recipeId/guidance"
          />
          <Route element={<RecipeDestination />} path="/recipes/:recipeId" />
        </Routes>
      </CookingFlowProvider>
    </MemoryRouter>,
  )
}

describe('CookingGuidanceScreen', () => {
  beforeEach(() => {
    sessionStorage.clear()
  })

  it('shows the wireframe guidance content with an honest video state', () => {
    renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Dhal Curry' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Cooking Guidance' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Video' })).toHaveAttribute(
      'aria-selected',
      'true',
    )
    expect(screen.getByText('Video player')).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Video unavailable' }),
    ).toBeInTheDocument()
    expect(screen.getByText('01:20')).toBeInTheDocument()
    expect(screen.getByText('08:30')).toBeInTheDocument()

    expect(
      screen.getByRole('heading', { name: 'Cooking Tips' }),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Rinse lentils until the water runs clear.'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Keep the heat low after adding coconut milk.'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Stir occasionally to prevent sticking.'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Adjust salt and spice to your preference.'),
    ).toBeInTheDocument()
  })

  it('navigates from the ingredients tab to recipe ingredients', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('tab', { name: 'Ingredients' }))

    expect(
      screen.getByText('Recipe destination: ingredients'),
    ).toBeInTheDocument()
  })

  it('navigates from the instructions tab to written instructions', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('tab', { name: 'Instructions' }))

    expect(
      screen.getByText('Recipe destination: instructions'),
    ).toBeInTheDocument()
  })
})
