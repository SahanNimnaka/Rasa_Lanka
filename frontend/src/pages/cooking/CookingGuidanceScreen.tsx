import { useState } from 'react'
import { Play } from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'
import { CookingRecipeStateCard } from '../../components/cooking/CookingRecipeStateCard'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import { useRecipe } from '../../hooks/queries'
import { useCookingRecipeId } from '../../hooks/useCookingRecipeId'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import type { RecipeTab } from '../../types/recipe'
import {
  displayValue,
  methodSummaryParts,
  minuteLabel,
  numberedCookingSteps,
} from '../../utils/cookingRecipe'
import { cn } from '../../utils/cn'

const TABS: Array<{ id: RecipeTab; label: string }> = [
  { id: 'ingredients', label: 'Ingredients' },
  { id: 'instructions', label: 'Instructions' },
  { id: 'video', label: 'Video' },
]

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="font-bold text-ink">{label}</dt>
      <dd className="mt-0.5 text-muted">{value}</dd>
    </div>
  )
}

export function CookingGuidanceScreen() {
  const navigate = useNavigate()
  const { recipeId } = useParams()
  const { canonicalId, hasValidId, numericId } = useCookingRecipeId(recipeId)
  const goBack = useNavigateBack(
    canonicalId ? `/cooking/${canonicalId}` : '/recipes/recommendations',
  )
  const recipeQuery = useRecipe(numericId)
  const retry = () => void recipeQuery.refetch()

  const [activeTab, setActiveTab] = useState<RecipeTab>('video')

  if (!hasValidId) {
    return (
      <CookingRecipeStateCard
        message="This cooking link does not contain a valid numeric recipe ID."
        onBack={goBack}
        title="Invalid recipe ID."
      />
    )
  }

  if (recipeQuery.isPending) {
    return (
      <CookingRecipeStateCard
        action={
          <div className="mt-5">
            <LoadingIndicator label="Loading cooking guidance..." />
          </div>
        }
        message="Fetching the recipe from the Laravel API."
        onBack={goBack}
        title="Loading cooking guidance..."
      />
    )
  }

  if (recipeQuery.isError) {
    const isNotFound =
      recipeQuery.error.status === 404 || recipeQuery.error.code === 'NOT_FOUND'

    return (
      <CookingRecipeStateCard
        action={
          isNotFound ? undefined : (
            <Button className="mt-4" onClick={retry} size="sm">
              Try again
            </Button>
          )
        }
        message={
          recipeQuery.error.message ||
          (isNotFound
            ? 'The requested recipe does not exist.'
            : 'The recipe service is temporarily unavailable.')
        }
        onBack={goBack}
        title={isNotFound ? 'Recipe not found.' : 'Guidance could not be loaded.'}
      />
    )
  }

  const recipe = recipeQuery.data
  if (!recipe) {
    return (
      <CookingRecipeStateCard
        action={
          <Button className="mt-4" onClick={retry} size="sm">
            Try again
          </Button>
        }
        message="The recipe service returned no recipe data."
        onBack={goBack}
        title="Guidance could not be loaded."
      />
    )
  }

  const instructionCount = numberedCookingSteps(recipe.instructions).length
  const summaryParts = methodSummaryParts(recipe.methodSummary)

  function handleTabChange(tab: RecipeTab) {
    if (tab === 'video') {
      setActiveTab('video')
      return
    }
    navigate(`/recipes/${recipe.id}`, { state: { tab } })
  }

  return (
    <MobileScreen aria-labelledby="guidance-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <p className="eyebrow-label mt-2">Learn before you cook</p>
      <h1
        className="mt-1 text-[28px] leading-tight font-extrabold"
        id="guidance-title"
      >
        {recipe.name}
      </h1>
      <h2 className="mt-1 text-base font-bold text-fresh-green">
        Cooking Guidance
      </h2>

      <Card className="mt-5 rounded-2xl p-4" id="guidance-overview">
        <h2
          className="section-title text-sm font-extrabold"
          id="guidance-overview-title"
        >
          Recipe overview
        </h2>
        <p className="mt-2 text-sm leading-6 text-muted">
          {displayValue(recipe.description) ?? 'No description is provided.'}
        </p>
        <dl className="mt-4 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
          <Fact
            label="Preparation"
            value={minuteLabel(recipe.preparationTimeMinutes)}
          />
          <Fact label="Cooking" value={minuteLabel(recipe.cookingTimeMinutes)} />
          <Fact label="Total" value={minuteLabel(recipe.totalTimeMinutes)} />
          <Fact
            label="Instructions"
            value={`${instructionCount} numbered ${
              instructionCount === 1 ? 'instruction' : 'instructions'
            }`}
          />
        </dl>
      </Card>

      <div
        aria-label="Recipe sections"
        className="glass-panel mt-6 grid grid-cols-3 gap-1 rounded-2xl p-1"
        role="tablist"
      >
        {TABS.map((tab) => (
          <button
            aria-controls="guidance-panel"
            aria-selected={activeTab === tab.id}
            className={cn(
              'min-h-10 rounded-xl px-1 text-sm font-bold transition-all duration-200',
              activeTab === tab.id
                ? 'bg-gradient-to-r from-[#2E7D32] to-[#0B3D2E] text-white shadow-md'
                : 'text-muted hover:bg-white/70 hover:text-ink',
            )}
            id={`guidance-${tab.id}-tab`}
            key={tab.id}
            onClick={() => handleTabChange(tab.id)}
            role="tab"
            type="button"
          >
            {tab.label}
          </button>
        ))}
      </div>

      <div
        aria-labelledby="guidance-video-tab"
        className="mt-5"
        id="guidance-panel"
        role="tabpanel"
      >
        <div
          aria-label="Video player placeholder. No video asset is connected."
          className="glass-panel relative mx-auto flex h-[232px] w-full flex-col items-center justify-center overflow-hidden rounded-2xl text-center shadow-elevated md:h-[360px] lg:max-w-5xl"
          role="group"
        >
          <div
            aria-hidden="true"
            className="absolute inset-0 opacity-20"
            style={{
              backgroundImage:
                'radial-gradient(rgba(31,35,40,0.12) 1px, transparent 1px)',
              backgroundSize: '18px 18px',
            }}
          />
          <div className="logo-glow relative">
            <span
              aria-hidden="true"
              className="flex h-16 w-16 items-center justify-center rounded-full border border-forest-green/15 bg-white text-fresh-green shadow-lg backdrop-blur-sm"
            >
              <Play className="ml-1 fill-current" size={26} />
            </span>
          </div>
          <p className="relative mt-4 text-xs font-bold tracking-wide text-ink">
            Video unavailable
          </p>
          <h3 className="sr-only">Video unavailable</h3>
          <p className="sr-only">
            No video asset is connected. Use the Instructions tab for written
            steps.
          </p>
        </div>

        <div
          aria-label="No video timeline is available for this recipe"
          className="glass-panel mt-4 rounded-2xl px-4 py-3"
          role="img"
        >
          <div className="h-1.5 w-full rounded-full bg-forest-green/10" />
          <p className="mt-2 text-center text-[11px] font-bold text-muted">
            No video timeline is available.
          </p>
        </div>

        <section aria-labelledby="guidance-method-title" className="mt-8">
          <h3
            className="section-title text-base font-extrabold"
            id="guidance-method-title"
          >
            Cooking method summary
          </h3>
          {summaryParts.length > 0 ? (
            <Card className="mt-4 grid gap-3 rounded-2xl p-4 md:grid-cols-2 md:gap-5">
              {summaryParts.map((part) => (
                <p className="text-sm leading-6 text-muted" key={part.label}>
                  <span className="font-bold text-ink">{part.label}:</span>{' '}
                  {part.value}
                </p>
              ))}
            </Card>
          ) : (
            <p className="mt-2 text-sm text-muted">
              No cooking method summary is provided.
            </p>
          )}
        </section>
      </div>
    </MobileScreen>
  )
}
