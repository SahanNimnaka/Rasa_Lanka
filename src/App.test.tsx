import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useNavigate } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import { AppRoutes } from './App'

function BrowserBackControl() {
  const navigate = useNavigate()

  return (
    <button onClick={() => navigate(-1)} type="button">
      Browser back
    </button>
  )
}

function renderRoutes(initialEntry: string, withBackControl = false) {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <AppRoutes />
      {withBackControl && <BrowserBackControl />}
    </MemoryRouter>,
  )
}

function expectActiveStep(step: number) {
  expect(
    screen.getByRole('listitem', {
      name: `Step ${step} of 3, current step`,
    }),
  ).toHaveAttribute('aria-current', 'step')
  expect(screen.getAllByRole('listitem')).toHaveLength(3)
}

describe('RasaLanka onboarding flow', () => {
  it('transitions from the splash screen to the first onboarding step', async () => {
    renderRoutes('/')

    expect(
      screen.getByRole('heading', { name: 'RasaLanka' }),
    ).toBeInTheDocument()

    expect(
      await screen.findByRole(
        'heading',
        { name: 'Identify Ingredients' },
        { timeout: 2000 },
      ),
    ).toBeInTheDocument()
    expectActiveStep(1)
  })

  it('renders all three steps, updates indicators, and supports browser back', async () => {
    const user = userEvent.setup()
    renderRoutes('/onboarding', true)

    expect(
      screen.getByRole('img', {
        name: 'A phone scanning a fresh ingredient with its camera',
      }),
    ).toBeInTheDocument()
    expectActiveStep(1)

    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(
      await screen.findByRole('heading', {
        name: 'Get Recipe Recommendations',
      }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('img', {
        name: 'A Sri Lankan meal served with rice, curry, and fresh greens',
      }),
    ).toBeInTheDocument()
    expectActiveStep(2)

    await user.click(screen.getByRole('button', { name: 'Next' }))
    expect(
      await screen.findByRole('heading', { name: 'Cook for Your Needs' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('img', {
        name: 'Ingredient quantities and servings arranged around a cooking pot',
      }),
    ).toBeInTheDocument()
    expectActiveStep(3)
    expect(
      screen.queryByRole('button', { name: 'Skip' }),
    ).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Browser back' }))
    expect(
      await screen.findByRole('heading', {
        name: 'Get Recipe Recommendations',
      }),
    ).toBeInTheDocument()
    expectActiveStep(2)
  })

  it('completes onboarding when Skip is selected', async () => {
    const user = userEvent.setup()
    renderRoutes('/onboarding')

    await user.click(screen.getByRole('button', { name: 'Skip' }))

    expect(
      screen.getByRole('heading', { name: 'Login screen coming soon' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Onboarding complete')).toBeInTheDocument()
  })

  it('routes Get Started to the temporary login destination', async () => {
    const user = userEvent.setup()
    renderRoutes('/onboarding')

    await user.click(screen.getByRole('button', { name: 'Next' }))
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await user.click(screen.getByRole('button', { name: 'Get Started' }))

    expect(
      await screen.findByRole('heading', {
        name: 'Login screen coming soon',
      }),
    ).toBeInTheDocument()
  })
})
