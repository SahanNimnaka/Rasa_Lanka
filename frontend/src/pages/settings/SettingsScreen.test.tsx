import { beforeEach, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { AppStateProvider } from '../../context/AppStateContext'
import { APP_STATE_STORAGE_KEY } from '../../services/appStateService'
import { SettingsScreen } from './SettingsScreen'

function renderScreen() {
  return render(
    <MemoryRouter initialEntries={['/settings']}>
      <AppStateProvider>
        <SettingsScreen />
      </AppStateProvider>
    </MemoryRouter>,
  )
}

describe('SettingsScreen', () => {
  beforeEach(() => {
    localStorage.clear()
    delete document.documentElement.dataset.theme
    document.documentElement.classList.remove('theme-dark')
  })

  it('shows settings rows, language value and the application version', () => {
    renderScreen()

    expect(
      screen.getByRole('heading', { name: 'Settings' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('switch', { name: 'Notifications' })).toBeChecked()
    expect(screen.getByRole('switch', { name: 'Dark Mode' })).not.toBeChecked()
    expect(screen.getByRole('combobox', { name: 'Language' })).toHaveValue(
      'English',
    )
    expect(
      screen.getByRole('button', { name: 'Clear History' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('heading', { name: 'Application version' }),
    ).toBeInTheDocument()
    expect(screen.getByText('Version 1.0.0')).toBeInTheDocument()
    expect(
      screen.getByText(/Full translations are not available yet/),
    ).toBeInTheDocument()
  })

  it('changes the real theme and persists it', async () => {
    const user = userEvent.setup()
    const { unmount } = renderScreen()

    expect(document.documentElement.dataset.theme).toBe('light')

    await user.click(screen.getByRole('switch', { name: 'Dark Mode' }))

    expect(screen.getByRole('switch', { name: 'Dark Mode' })).toBeChecked()
    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(document.documentElement).toHaveClass('theme-dark')

    const stored = JSON.parse(
      window.localStorage.getItem(APP_STATE_STORAGE_KEY) ?? '{}',
    )
    expect(stored.settings.darkMode).toBe(true)

    unmount()
    renderScreen()

    expect(screen.getByRole('switch', { name: 'Dark Mode' })).toBeChecked()
    expect(document.documentElement.dataset.theme).toBe('dark')
  })

  it('saves a language choice without claiming translation', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.selectOptions(
      screen.getByRole('combobox', { name: 'Language' }),
      'Sinhala',
    )

    expect(screen.getByRole('combobox', { name: 'Language' })).toHaveValue(
      'Sinhala',
    )
    const stored = JSON.parse(
      window.localStorage.getItem(APP_STATE_STORAGE_KEY) ?? '{}',
    )
    expect(stored.settings.language).toBe('Sinhala')
    expect(
      screen.getByText(/Full translations are not available yet/),
    ).toBeInTheDocument()
  })

  it('confirms clearing history', async () => {
    const user = userEvent.setup()
    renderScreen()

    await user.click(screen.getByRole('button', { name: 'Clear History' }))
    expect(screen.getByText('Clear all history?')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Clear history' }))

    const stored = JSON.parse(
      window.localStorage.getItem(APP_STATE_STORAGE_KEY) ?? '{}',
    )
    expect(stored.history).toEqual([])
    expect(screen.getByText('History cleared.')).toBeInTheDocument()
  })
})
