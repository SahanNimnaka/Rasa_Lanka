import { useState } from 'react'
import { Clock3, Trash2 } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { WireframeImagePlaceholder } from '../../components/ui/WireframeImagePlaceholder'
import { useAppState } from '../../context/appStateStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { formatViewedAt } from '../../services/appStateService'
import { getRecipeById } from '../../services/mockRecipeService'

export function HistoryScreen() {
  const navigate = useNavigate()
  const goBack = useNavigateBack('/home')
  const { clearHistory, history } = useAppState()
  const [showConfirmation, setShowConfirmation] = useState(false)
  const [status, setStatus] = useState('')

  const entries = history.reduce(
    (items, entry) => {
      const recipe = getRecipeById(entry.recipeId)
      if (recipe) {
        items.push({ entry, recipe })
      }
      return items
    },
    [] as Array<{
      entry: (typeof history)[number]
      recipe: NonNullable<ReturnType<typeof getRecipeById>>
    }>,
  )

  function handleClearHistory() {
    clearHistory()
    setShowConfirmation(false)
    setStatus('History cleared.')
  }

  return (
    <MobileScreen aria-labelledby="history-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <p className="eyebrow-label mt-2">Recently explored</p>
      <h1
        className="mt-1 text-[28px] leading-tight font-extrabold"
        id="history-title"
      >
        History
      </h1>

      <section aria-labelledby="recently-viewed-title" className="mt-8">
        <h2 className="text-base font-bold" id="recently-viewed-title">
          Recently Viewed
        </h2>
        <p className="mt-2 text-sm text-muted">
          Recipes you explored recently.
        </p>

        {entries.length === 0 ? (
          <div className="glass-panel mt-8 rounded-2xl p-6 text-center">
            <h3 className="text-base font-bold">Your history is empty</h3>
            <p className="mt-2 text-sm leading-6 text-muted">
              Open a recipe to add it to this list.
            </p>
            <Button
              className="mt-4"
              onClick={() => navigate('/recipes/recommendations')}
              size="sm"
            >
              Find recipes
            </Button>
          </div>
        ) : (
          <div className="mt-5 grid gap-3 md:grid-cols-2 md:gap-5">
            {entries.map(({ entry, recipe }) => (
              <button
                className="glass-panel flex w-full items-center gap-4 rounded-2xl p-2.5 text-left transition-all duration-200 hover:-translate-y-0.5 hover:shadow-elevated"
                key={entry.id}
                onClick={() => navigate(`/recipes/${recipe.id}`)}
                type="button"
              >
                <WireframeImagePlaceholder className="h-[88px] w-[88px]" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-bold text-ink">
                    {recipe.name}
                  </span>
                  <span className="mt-2 flex items-center gap-1 text-xs text-muted">
                    <Clock3 aria-hidden="true" size={13} />
                    {formatViewedAt(entry.viewedAt)}
                  </span>
                </span>
              </button>
            ))}
          </div>
        )}
      </section>

      <div className="mt-10">
        {!showConfirmation ? (
          <Button
            className="h-[46px] w-full"
            onClick={() => setShowConfirmation(true)}
            size="lg"
            variant="secondary"
          >
            <Trash2 aria-hidden="true" size={17} />
            Delete History
          </Button>
        ) : (
          <Card
            className="rounded-lg border-chilli-red/40 bg-chilli-red/5 shadow-none"
            role="alertdialog"
          >
            <h2 className="text-base font-bold">Delete all history?</h2>
            <p className="mt-2 text-sm leading-6 text-muted">
              This removes every recipe from this local history list.
            </p>
            <div className="mt-4 grid grid-cols-2 gap-3">
              <Button
                onClick={() => setShowConfirmation(false)}
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

      <p aria-live="polite" className="sr-only">
        {status}
      </p>
    </MobileScreen>
  )
}
