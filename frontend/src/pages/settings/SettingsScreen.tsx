import { useState } from 'react'
import { Bell, ChevronRight, Languages, Moon, Trash2 } from 'lucide-react'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { ToggleSwitch } from '../../components/ui/ToggleSwitch'
import { useAppState } from '../../context/appStateStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { APP_VERSION } from '../../services/appStateService'
import type { AppLanguage } from '../../types/appState'

export function SettingsScreen() {
  const goBack = useNavigateBack('/profile')
  const { clearHistory, settings, updateSettings } = useAppState()
  const [showClearConfirmation, setShowClearConfirmation] = useState(false)
  const [status, setStatus] = useState('')

  function handleClearHistory() {
    clearHistory()
    setShowClearConfirmation(false)
    setStatus('History cleared.')
  }

  return (
    <MobileScreen aria-labelledby="settings-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <p className="eyebrow-label mt-2">Preferences & privacy</p>
      <h1
        className="mt-1 text-[28px] leading-tight font-extrabold"
        id="settings-title"
      >
        Settings
      </h1>

      <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.7fr)] lg:items-start">
        <div className="glass-panel divide-y divide-forest-green/10 overflow-hidden rounded-2xl">
          <div className="flex items-center justify-between gap-4 px-4 py-4">
            <span className="flex items-center gap-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-spice-orange/15 text-spice-orange">
                <Bell aria-hidden="true" size={17} />
              </span>
              <h2 className="text-sm font-bold text-ink">Notifications</h2>
            </span>
            <ToggleSwitch
              checked={settings.notificationsEnabled}
              label="Notifications"
              onChange={(checked) =>
                updateSettings({ notificationsEnabled: checked })
              }
            />
          </div>

          <div className="flex items-center justify-between gap-4 px-4 py-4">
            <span className="flex items-center gap-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-fresh-green/15 text-fresh-green">
                <Moon aria-hidden="true" size={17} />
              </span>
              <h2 className="text-sm font-bold text-ink">Dark Mode</h2>
            </span>
            <ToggleSwitch
              checked={settings.darkMode}
              label="Dark Mode"
              onChange={(checked) => updateSettings({ darkMode: checked })}
            />
          </div>

          <div className="flex items-center justify-between gap-4 px-4 py-4">
            <div className="flex min-w-0 items-center gap-3">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-forest-green/10 text-ink">
                <Languages aria-hidden="true" size={17} />
              </span>
              <div className="min-w-0">
                <label
                  className="block text-sm font-bold text-ink"
                  htmlFor="language-select"
                >
                  Language
                </label>
                <select
                  className="mt-1 max-w-full appearance-none bg-transparent pr-2 text-sm font-semibold text-muted focus:outline-none"
                  id="language-select"
                  onChange={(event) =>
                    updateSettings({
                      language: event.target.value as AppLanguage,
                    })
                  }
                  value={settings.language}
                >
                  <option value="English">English</option>
                  <option value="Sinhala">Sinhala</option>
                  <option value="Tamil">Tamil</option>
                </select>
              </div>
            </div>
            <ChevronRight
              aria-hidden="true"
              className="shrink-0 text-spice-orange"
              size={18}
            />
          </div>
        </div>

        <p className="sr-only">Full translations are not available yet.</p>

        <div className="mt-8">
          {!showClearConfirmation ? (
            <Button
              className="h-[46px] w-full"
              onClick={() => setShowClearConfirmation(true)}
              size="lg"
              variant="secondary"
            >
              <Trash2 aria-hidden="true" size={17} />
              Clear History
            </Button>
          ) : (
            <Card
              className="rounded-lg border-chilli-red/40 bg-chilli-red/5 shadow-none"
              role="alertdialog"
            >
              <h2 className="text-base font-bold">Clear all history?</h2>
              <p className="mt-2 text-sm leading-6 text-muted">
                This removes every recipe from this local history list.
              </p>
              <div className="mt-4 grid grid-cols-2 gap-3">
                <Button
                  onClick={() => setShowClearConfirmation(false)}
                  variant="secondary"
                >
                  Cancel
                </Button>
                <Button onClick={handleClearHistory} variant="danger">
                  Clear history
                </Button>
              </div>
            </Card>
          )}
        </div>
      </div>

      <p aria-live="polite" className="sr-only">
        {status}
      </p>

      <section className="mt-16" aria-labelledby="version-title">
        <h2 className="text-sm font-bold text-ink" id="version-title">
          Application version
        </h2>
        <p className="mt-2 text-sm text-muted">Version {APP_VERSION}</p>
      </section>
    </MobileScreen>
  )
}
