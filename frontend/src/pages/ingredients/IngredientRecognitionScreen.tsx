import { useMemo, useState } from 'react'
import { Image as ImageIcon, Plus } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { IngredientRow } from '../../components/ingredients/IngredientRow'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Input'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  DEMO_RECOGNITION_NOTICE,
  getDemoRecognitionResults,
} from '../../services/recognitionService'
import type {
  IngredientRecognitionState,
  SelectedIngredient,
} from '../../types/ingredients'

export function IngredientRecognitionScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const goBack = useNavigateBack('/ingredients/upload')
  const flow = useCookingFlow()
  const navigationState = location.state as IngredientRecognitionState | null

  const initialIngredients = useMemo(
    () =>
      navigationState?.initialIngredients?.length
        ? navigationState.initialIngredients
        : getDemoRecognitionResults(),
    [navigationState],
  )

  const recognized = initialIngredients
  const [selectedIds, setSelectedIds] = useState<Set<string>>(
    () => new Set(initialIngredients.map((ingredient) => ingredient.id)),
  )
  const [manualIngredients, setManualIngredients] = useState<
    SelectedIngredient[]
  >([])
  const [isAddingManual, setIsAddingManual] = useState(false)
  const [manualName, setManualName] = useState('')
  const [manualError, setManualError] = useState('')

  function handleToggle(id: string, isSelected: boolean) {
    setSelectedIds((current) => {
      const next = new Set(current)
      if (isSelected) {
        next.add(id)
      } else {
        next.delete(id)
      }
      return next
    })
  }

  function handleAddManual() {
    const name = manualName.trim()
    if (!name) {
      setManualError('Enter an ingredient name')
      return
    }

    const id = `manual-${Date.now()}-${manualIngredients.length}`
    setManualIngredients((current) => [
      ...current,
      { id, name, source: 'manual' },
    ])
    setSelectedIds((current) => new Set(current).add(id))
    setManualName('')
    setManualError('')
    setIsAddingManual(false)
  }

  function handleRemoveManual(id: string) {
    setManualIngredients((current) => current.filter((item) => item.id !== id))
    setSelectedIds((current) => {
      const next = new Set(current)
      next.delete(id)
      return next
    })
  }

  const finalSelection = [...recognized, ...manualIngredients].filter(
    (ingredient) => selectedIds.has(ingredient.id),
  )

  function handleConfirm() {
    flow.setIngredients(finalSelection)
    navigate('/ingredients/quantities', {
      state: { ingredients: finalSelection },
    })
  }

  return (
    <MobileScreen aria-labelledby="recognition-title">
      <ScreenHeader onBack={goBack} />

      <section className="mt-3">
        <p className="eyebrow-label">Review your ingredients</p>
        <h1
          className="mt-1 text-[1.7rem] font-extrabold"
          id="recognition-title"
        >
          Detected Ingredients
        </h1>

        <div
          aria-label="Demonstration recognition data"
          className="glass-panel mt-4 rounded-xl border border-spice-orange/40 px-3 py-2"
        >
          <p className="text-xs font-bold text-ink">
            Demo data — not a model result
          </p>
          <p className="mt-1 text-xs leading-5 text-muted">
            {DEMO_RECOGNITION_NOTICE}
          </p>
        </div>

        <div className="mt-5 flex h-[146px] items-center justify-center overflow-hidden rounded-card border border-forest-green/15 bg-warm-cream">
          {navigationState?.image?.dataUrl ? (
            <img
              alt="Uploaded ingredients"
              className="h-full w-full object-contain"
              src={navigationState.image.dataUrl}
            />
          ) : (
            <div className="text-center text-muted">
              <ImageIcon aria-hidden="true" className="mx-auto" size={30} />
              <p className="mt-2 text-sm">Uploaded ingredient image</p>
              <p className="text-xs">No preview available</p>
            </div>
          )}
        </div>

        <div className="mt-6">
          <h2 className="text-base font-bold">
            Review the recognition results
          </h2>
          <p className="mt-1 text-sm text-muted">
            Select ingredients to use in your recipes.
          </p>

          <div className="mt-4 grid gap-3 md:grid-cols-2 md:gap-5">
            {recognized.map((ingredient) => (
              <IngredientRow
                confidenceLabel="Demo confidence"
                ingredient={ingredient}
                key={ingredient.id}
                onToggle={handleToggle}
                selected={selectedIds.has(ingredient.id)}
              />
            ))}

            {manualIngredients.map((ingredient) => (
              <IngredientRow
                ingredient={ingredient}
                key={ingredient.id}
                onRemove={handleRemoveManual}
                onToggle={handleToggle}
                selected={selectedIds.has(ingredient.id)}
              />
            ))}
          </div>

          {isAddingManual && (
            <div className="mt-4 rounded-card border border-forest-green/15 p-3">
              <Input
                autoFocus
                error={manualError}
                label="New ingredient name"
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    event.preventDefault()
                    handleAddManual()
                  }
                }}
                onChange={(event) => {
                  setManualName(event.target.value)
                  if (manualError) {
                    setManualError('')
                  }
                }}
                placeholder="e.g. Turmeric"
                value={manualName}
              />
              <div className="mt-3 flex gap-2">
                <Button className="flex-1" onClick={handleAddManual}>
                  Add ingredient
                </Button>
                <Button
                  onClick={() => {
                    setIsAddingManual(false)
                    setManualError('')
                    setManualName('')
                  }}
                  variant="secondary"
                >
                  Cancel
                </Button>
              </div>
            </div>
          )}

          <Button
            className="mt-4 h-[47px] w-full rounded-control border border-dashed text-sm"
            onClick={() => setIsAddingManual((current) => !current)}
            variant="secondary"
          >
            <Plus aria-hidden="true" size={17} />
            Add Ingredient Manually
          </Button>
        </div>

        <section aria-labelledby="final-selection-title" className="mt-7">
          <h2 className="text-base font-bold" id="final-selection-title">
            Final selection ({finalSelection.length})
          </h2>
          {finalSelection.length === 0 ? (
            <p className="mt-2 text-sm text-muted">No ingredients selected.</p>
          ) : (
            <ul className="mt-3 flex flex-wrap gap-2">
              {finalSelection.map((ingredient) => (
                <li
                  className="rounded-full bg-fresh-green/10 px-3 py-1 text-xs font-semibold text-ink"
                  key={ingredient.id}
                >
                  {ingredient.name}
                </li>
              ))}
            </ul>
          )}
        </section>

        <Button
          className="mt-7 h-[46px] w-full rounded-none text-sm"
          onClick={handleConfirm}
          size="lg"
        >
          Confirm &amp; Continue
        </Button>
      </section>
    </MobileScreen>
  )
}
