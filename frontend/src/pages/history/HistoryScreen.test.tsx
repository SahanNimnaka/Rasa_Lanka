import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AppStateProvider } from '../../context/AppStateContext'
import { HistoryScreen } from './HistoryScreen'

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/history']}>
      <AppStateProvider>
        <Routes>
          <Route element={<HistoryScreen />} path="/history" />
          <Route
            element={<p>Recipe destination</p>}
            path="/recipes/:recipeId"
          />
        </Routes>
      </AppStateProvider>
    </MemoryRouter>,
  )
}

describe('HistoryScreen', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows recently viewed recipes with wireframe timestamps', () => {
    renderScreen()

    expect(screen.getByRole('heading', { name: 'History' })).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Recently Viewed' }),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Recipes you explored recently.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Dhal Curry')).toBeInTheDocument()
    expect(screen.getByText('Pol Sambol')).toBeInTheDocument()
    expect(screen.getByText('Coconut Roti')).toBeInTheDocument()
    expect(screen.getByText('Today, 10:30 AM')).toBeInTheDocument()
    expect(screen.getByText('Yesterday, 6:15 PM')).toBeInTheDocument()
    expect(screen.getByText('Yesterday, 9:00 AM')).toBeInTheDocument()
  })

  it('confirms deletion and shows the empty state', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Delete History' }))
    expect(screen.getByText('Delete all history?')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Clear history' }))

    expect(screen.getByText('Your history is empty')).toBeInTheDocument()
    expect(screen.getByText('History cleared.')).toBeInTheDocument()
    expect(screen.queryByText('Dhal Curry')).not.toBeInTheDocument()
  })

  it('can cancel the deletion confirmation', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Delete History' }))
    await user.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(screen.queryByText('Delete all history?')).not.toBeInTheDocument()
    expect(screen.getByText('Dhal Curry')).toBeInTheDocument()
  })
})
