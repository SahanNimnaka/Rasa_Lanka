import { useState } from 'react'
import { Clock3, Trash2 } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import { WireframeImagePlaceholder } from '../../components/ui/WireframeImagePlaceholder'
import { useAppState } from '../../context/appStateStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  useClearRecipeHistory,
  useRecipeHistory,
} from '../../hooks/queries'
import { formatViewedAt } from '../../services/appStateService'
import { parseApiId } from '../../services/queries/queryKeys'

export function HistoryScreen() {
  const navigate = useNavigate()
  const goBack = useNavigateBack('/home')
  const { clearHistory } = useAppState()
  const historyQuery = useRecipeHistory()
  const { mutateAsync: clearHistoryApi, isPending: isClearing } =
    useClearRecipeHistory()
  const [showConfirmation, setShowConfirmation] = useState(false)
  const [status, setStatus] = useState('')

  const items = historyQuery.data?.pages.flatMap((page) => page.data) ?? []

  async function handleClearHistory() {
    try {
      await clearHistoryApi()
      clearHistory()
      setShowConfirmation(false)
      setStatus('History cleared.')
    } catch {
      setStatus('Could not clear history. Please try again.')
    }
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

        {historyQuery.isPending && items.length === 0 ? (
          <div className="mt-8">
            <LoadingIndicator label="Loading recipe history..." />
          </div>
        ) : historyQuery.isError && items.length === 0 ? (
          <div className="glass-panel mt-8 rounded-2xl p-6 text-center">
            <h3 className="text-base font-bold">History could not load</h3>
            <p className="mt-2 text-sm leading-6 text-muted">
              {historyQuery.error.message}
            </p>
            <Button
              className="mt-4"
              onClick={() => void historyQuery.refetch()}
              size="sm"
            >
              Try again
            </Button>
          </div>
        ) : items.length === 0 ? (
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
            {items.map((item) => {
              const recipeId = parseApiId(item.recipeId)
              return (
                <button
                  className="glass-panel flex w-full items-center gap-4 rounded-2xl p-2.5 text-left transition-all duration-200 hover:-translate-y-0.5 hover:shadow-elevated"
                  key={item.id}
                  onClick={() => {
                    if (recipeId !== null) {
                      navigate(`/recipes/${recipeId}`)
                    }
                  }}
                  type="button"
                >
                  <WireframeImagePlaceholder className="h-[88px] w-[88px]" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-bold text-ink">
                      {item.recipe.name}
                    </span>
                    <span className="mt-2 flex items-center gap-1 text-xs text-muted">
                      <Clock3 aria-hidden="true" size={13} />
                      {formatViewedAt(item.viewedAt)}
                    </span>
                  </span>
                </button>
              )
            })}
          </div>
        )}

        {historyQuery.hasNextPage && (
          <div className="mt-6 flex justify-center">
            <Button
              disabled={historyQuery.isFetchingNextPage}
              onClick={() => void historyQuery.fetchNextPage()}
              size="sm"
              variant="secondary"
            >
              {historyQuery.isFetchingNextPage ? 'Loading more...' : 'Load more'}
            </Button>
          </div>
        )}
      </section>

      <div className="mt-10">
        {!showConfirmation ? (
          <Button
            className="h-[46px] w-full"
            disabled={isClearing}
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
              This removes every recipe from your saved history.
            </p>
            <div className="mt-4 grid grid-cols-2 gap-3">
              <Button
                onClick={() => setShowConfirmation(false)}
                variant="secondary"
              >
                Cancel
              </Button>
              <Button
                disabled={isClearing}
                onClick={() => void handleClearHistory()}
                variant="danger"
              >
                {isClearing ? 'Clearing...' : 'Clear history'}
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
