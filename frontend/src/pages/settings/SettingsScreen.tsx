import { useState } from 'react'
import { Trash2 } from 'lucide-react'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { ToggleSwitch } from '../../components/ui/ToggleSwitch'
import { useAppState } from '../../context/appStateStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { APP_VERSION } from '../../services/appStateService'
import type { AppLanguage } from '../../types/appState'

const LANGUAGE_OPTIONS: AppLanguage[] = ['English', 'Sinhala', 'Tamil']

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

      <h1 className="mt-2 text-2xl font-bold" id="settings-title">
        Settings
      </h1>

      <Card className="mt-8 overflow-hidden p-0">
        <div className="flex items-center justify-between gap-4 px-4 py-4">
          <div>
            <h2 className="text-sm font-bold text-forest-green">
              Notifications
            </h2>
            <p className="mt-1 text-xs leading-5 text-muted">
              Delivery is not connected to a backend yet.
            </p>
          </div>
          <ToggleSwitch
            checked={settings.notificationsEnabled}
            label="Notifications"
            onChange={(checked) =>
              updateSettings({ notificationsEnabled: checked })
            }
          />
        </div>

        <div className="flex items-center justify-between gap-4 border-t border-forest-green/10 px-4 py-4">
          <div>
            <h2 className="text-sm font-bold text-forest-green">Dark Mode</h2>
            <p className="mt-1 text-xs leading-5 text-muted">
              Changes the app theme on this device.
            </p>
          </div>
          <ToggleSwitch
            checked={settings.darkMode}
            label="Dark Mode"
            onChange={(checked) => updateSettings({ darkMode: checked })}
          />
        </div>

        <div className="border-t border-forest-green/10 px-4 py-4">
          <label
            className="block text-sm font-bold text-forest-green"
            htmlFor="language-select"
          >
            Language
          </label>
          <select
            className="mt-3 min-h-10 w-full rounded-control border border-forest-green/20 bg-white px-3 text-sm text-forest-green"
            id="language-select"
            onChange={(event) =>
              updateSettings({ language: event.target.value as AppLanguage })
            }
            value={settings.language}
          >
            {LANGUAGE_OPTIONS.map((language) => (
              <option key={language} value={language}>
                {language}
              </option>
            ))}
          </select>
          <p className="mt-2 text-xs leading-5 text-muted">
            Language preference is saved locally. Full translations are not
            available yet.
          </p>
        </div>
      </Card>

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
            className="border-chilli-red/40 bg-chilli-red/5"
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

      <p aria-live="polite" className="mt-3 text-center text-xs text-muted">
        {status}
      </p>

      <section className="mt-16" aria-labelledby="version-title">
        <h2 className="text-sm font-bold text-forest-green" id="version-title">
          Application version
        </h2>
        <p className="mt-2 text-sm text-muted">Version {APP_VERSION}</p>
      </section>
    </MobileScreen>
  )
}
