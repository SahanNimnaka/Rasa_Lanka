import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { AboutScreen } from './AboutScreen'

function renderScreen() {
  render(
    <MemoryRouter initialEntries={['/about']}>
      <Routes>
        <Route element={<AboutScreen />} path="/about" />
        <Route element={<p>Profile destination</p>} path="/profile" />
      </Routes>
    </MemoryRouter>,
  )
}

describe('AboutScreen', () => {
  it('shows the approved project and developer details', () => {
    renderScreen()

    expect(screen.getByRole('heading', { name: 'About' })).toBeInTheDocument()
    expect(screen.getByAltText('RasaLanka logo')).toBeInTheDocument()
    expect(
      screen.getByText(
        'AI-powered recipe recommendation for Sri Lankan cuisine.',
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Research Project' }),
    ).toBeInTheDocument()
    expect(
      screen.getByText(
        'Multi-Ingredient Image Recognition and Deep Learning for Sri Lankan Cuisine.',
      ),
    ).toBeInTheDocument()
    expect(screen.getByText('RasaLanka Deep Learning')).toBeInTheDocument()
    expect(screen.getByText('Developed by')).toBeInTheDocument()
    expect(screen.getByText('Sahan Nimnaka')).toBeInTheDocument()
    expect(screen.getByText('APIIT University')).toBeInTheDocument()
  })

  it('navigates back to the profile', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Go back' }))

    expect(screen.getByText('Profile destination')).toBeInTheDocument()
  })
})
