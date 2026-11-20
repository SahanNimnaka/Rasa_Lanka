import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { HomeScreen } from './HomeScreen'

function renderHome(initialEntry = '/home') {
  render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route element={<HomeScreen />} path="/home" />
        <Route element={<p>Camera destination</p>} path="/ingredients/camera" />
        <Route element={<p>Upload destination</p>} path="/ingredients/upload" />
        <Route element={<p>Search destination</p>} path="/search" />
        <Route element={<p>Saved destination</p>} path="/saved" />
        <Route element={<p>Profile destination</p>} path="/profile" />
        <Route element={<p>Recipe destination</p>} path="/recipes/:recipeId" />
      </Routes>
    </MemoryRouter>,
  )
}

describe('HomeScreen', () => {
  it('shows the wireframe content and Sri Lankan recipe images', () => {
    renderHome()

    expect(
      screen.getByRole('heading', { name: 'Welcome, Sahan' }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        'Discover Sri Lankan recipes from the ingredients you have.',
      ),
    ).toBeInTheDocument()
    expect(screen.getByAltText('Dhal Curry')).toHaveAttribute(
      'src',
      '/images/recipes/dhal-curry.jpg',
    )
    expect(screen.getByAltText('Pol Sambol')).toHaveAttribute(
      'src',
      '/images/recipes/pol-sambol.jpg',
    )
    expect(
      screen.getByRole('navigation', { name: 'Primary navigation' }),
    ).toBeInTheDocument()
  })

  it('navigates from the primary ingredient actions', async () => {
    const user = userEvent.setup()
    renderHome()

    await user.click(screen.getByRole('button', { name: /Scan Ingredients/ }))
    expect(screen.getByText('Camera destination')).toBeInTheDocument()
  })

  it('navigates to upload from the home action card', async () => {
    const user = userEvent.setup()
    renderHome()

    await user.click(screen.getByRole('button', { name: /Upload Image/ }))
    expect(screen.getByText('Upload destination')).toBeInTheDocument()
  })

  it('supports bottom navigation and recipe cards', async () => {
    const user = userEvent.setup()
    renderHome()

    await user.click(screen.getByRole('link', { name: 'Search' }))
    expect(screen.getByText('Search destination')).toBeInTheDocument()
  })

  it('uses temporary destinations for saved, profile and recipe cards', async () => {
    const user = userEvent.setup()
    renderHome()

    await user.click(screen.getByRole('link', { name: 'Saved' }))
    expect(screen.getByText('Saved destination')).toBeInTheDocument()
  })
})
