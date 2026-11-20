import { useState } from 'react'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { ChoiceChip } from '../../components/ui/ChoiceChip'
import { useAppState } from '../../context/appStateStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import type {
  CookingPreferences,
  CuisineType,
  DietaryPreference,
  SpiceLevel,
} from '../../types/appState'

const DIETARY_OPTIONS: DietaryPreference[] = ['none', 'vegetarian', 'vegan']
const SPICE_OPTIONS: SpiceLevel[] = ['mild', 'medium', 'spicy']
const CUISINE_OPTIONS: CuisineType[] = ['Sri Lankan', 'Other Asian']

const DIETARY_LABELS: Record<DietaryPreference, string> = {
  none: 'None',
  vegetarian: 'Vegetarian',
  vegan: 'Vegan',
}

const SPICE_LABELS: Record<SpiceLevel, string> = {
  mild: 'Mild',
  medium: 'Medium',
  spicy: 'Spicy',
}

function validatePreferences(preferences: CookingPreferences): string | null {
  if (!DIETARY_OPTIONS.includes(preferences.dietary)) {
    return 'Choose a dietary preference.'
  }
  if (!SPICE_OPTIONS.includes(preferences.spice)) {
    return 'Choose a spice level.'
  }
  if (!CUISINE_OPTIONS.includes(preferences.cuisine)) {
    return 'Choose a preferred cuisine.'
  }
  return null
}

export function CookingPreferencesScreen() {
  const goBack = useNavigateBack('/profile')
  const { preferences, updatePreferences } = useAppState()
  const [draft, setDraft] = useState<CookingPreferences>(() => ({
    ...preferences,
  }))
  const [error, setError] = useState('')
  const [status, setStatus] = useState('')

  function savePreferences() {
    const validationError = validatePreferences(draft)
    if (validationError) {
      setError(validationError)
      setStatus('')
      return
    }
    updatePreferences(draft)
    setError('')
    setStatus('Preferences saved locally.')
  }

  return (
    <MobileScreen aria-labelledby="preferences-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <h1 className="mt-2 text-2xl font-bold" id="preferences-title">
        Cooking Preferences
      </h1>
      <p className="mt-2 text-sm text-muted">Choose how you like to cook.</p>

      <section aria-labelledby="dietary-title" className="mt-8">
        <h2 className="text-sm font-bold text-forest-green" id="dietary-title">
          Dietary Preference
        </h2>
        <div
          aria-label="Dietary preference"
          className="mt-3 grid grid-cols-3 gap-2"
          role="group"
        >
          {DIETARY_OPTIONS.map((option) => (
            <ChoiceChip
              key={option}
              label={DIETARY_LABELS[option]}
              onClick={() => {
                setDraft((current) => ({ ...current, dietary: option }))
                setError('')
              }}
              selected={draft.dietary === option}
            />
          ))}
        </div>
      </section>

      <section aria-labelledby="spice-title" className="mt-8">
        <h2 className="text-sm font-bold text-forest-green" id="spice-title">
          Spice Level
        </h2>
        <div
          aria-label="Spice level"
          className="mt-3 grid grid-cols-3 gap-2"
          role="group"
        >
          {SPICE_OPTIONS.map((option) => (
            <ChoiceChip
              key={option}
              label={SPICE_LABELS[option]}
              onClick={() => {
                setDraft((current) => ({ ...current, spice: option }))
                setError('')
              }}
              selected={draft.spice === option}
            />
          ))}
        </div>
      </section>

      <section aria-labelledby="cuisine-title" className="mt-8">
        <h2 className="text-sm font-bold text-forest-green" id="cuisine-title">
          Preferred Cuisine
        </h2>
        <div
          aria-label="Preferred cuisine"
          className="mt-3 grid grid-cols-2 gap-2"
          role="group"
        >
          {CUISINE_OPTIONS.map((option) => (
            <ChoiceChip
              key={option}
              label={option}
              onClick={() => {
                setDraft((current) => ({ ...current, cuisine: option }))
                setError('')
              }}
              selected={draft.cuisine === option}
            />
          ))}
        </div>
      </section>

      {error && (
        <p className="mt-6 text-sm font-medium text-chilli-red" role="alert">
          {error}
        </p>
      )}
      <p aria-live="polite" className="mt-3 text-center text-xs text-muted">
        {status}
      </p>

      <Button
        className="mt-8 h-[46px] w-full"
        onClick={savePreferences}
        size="lg"
        variant="ink"
      >
        Save Preferences
      </Button>
    </MobileScreen>
  )
}
