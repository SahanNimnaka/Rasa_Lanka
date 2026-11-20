import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useNavigate } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
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

describe('screen navigation', () => {
  it('navigates between login and registration without authenticating', async () => {
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/login']}>
        <AppRoutes />
      </MemoryRouter>,
    )

    await user.click(screen.getByRole('button', { name: 'Sign up' }))
    expect(
      await screen.findByRole('heading', { name: 'Create Account' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Login' }))
    expect(
      await screen.findByRole('heading', { name: 'Welcome Back' }),
    ).toBeInTheDocument()
  })

  it('supports browser back and forward between screens', async () => {
    const user = userEvent.setup()
    render(
      <MemoryRouter
        initialEntries={['/home', '/ingredients/upload']}
        initialIndex={1}
      >
        <AppRoutes />
        <HistoryControls />
      </MemoryRouter>,
    )

    expect(
      screen.getByRole('heading', { name: 'Upload Image' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'History back' }))
    expect(
      await screen.findByRole('heading', { name: 'Welcome, Sahan' }),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'History forward' }))
    expect(
      await screen.findByRole('heading', { name: 'Upload Image' }),
    ).toBeInTheDocument()
  })

  it('opens the Home frontend preview from the authentication placeholder', async () => {
    const user = userEvent.setup()
    render(
      <MemoryRouter initialEntries={['/login']}>
        <AppRoutes />
      </MemoryRouter>,
    )

    await user.click(screen.getByRole('button', { name: 'Open Home preview' }))

    expect(
      await screen.findByRole('heading', { name: 'Welcome, Sahan' }),
    ).toBeInTheDocument()
  })
})
