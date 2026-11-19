import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import App from './App'

describe('Screen 01: Splash Screen', () => {
  it('shows the approved brand, tagline, and loading status', () => {
    render(<App />)

    expect(
      screen.getByRole('img', { name: 'RasaLanka cooking logo' }),
    ).toHaveAttribute('src', '/rasalanka-logo.png')
    expect(
      screen.getByRole('heading', { name: 'RasaLanka' }),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Discover Sri Lankan Recipes From Your Ingredients.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Loading...')
  })
})
