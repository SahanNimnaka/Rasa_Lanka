import { useState, type ReactNode } from 'react'
import { Clock, Heart, Users } from 'lucide-react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { ApiRecipeIngredientList } from '../../components/recipes/ApiRecipeIngredientList'
import { RecipeDataQualityPanel } from '../../components/recipes/RecipeDataQualityPanel'
import { RecipeSourceList } from '../../components/recipes/RecipeSourceList'
import { RecipeTabs } from '../../components/recipes/RecipeTabs'
import { VideoUnavailable } from '../../components/recipes/VideoUnavailable'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import { WireframeImagePlaceholder } from '../../components/ui/WireframeImagePlaceholder'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { useRecipe, useToggleSavedRecipe } from '../../hooks/queries'
import type {
  RecipeDetailViewModel,
  RecipeInstructionViewModel,
  RecipeMethodSummaryViewModel,
} from '../../services/mappers/recipeMappers'
import { parseApiId } from '../../services/queries/queryKeys'
import type { RecipeTab } from '../../types/recipe'
import { cn } from '../../utils/cn'
import { collectUserQuantities } from '../../utils/cookingRecipe'

interface RecipeDetailsRouteState {
  servings?: number
  tab?: RecipeTab
}

type InstructionViewModel =
  | RecipeInstructionViewModel
  | RecipeMethodSummaryViewModel

function displayValue(value: string | null): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

function minuteLabel(minutes: number | null): string {
  return typeof minutes === 'number' && Number.isFinite(minutes)
    ? `${minutes} min`
    : 'Not provided'
}

function summaryParts(instruction: InstructionViewModel) {
  return [
    {
      label: 'Method paragraph',
      value: displayValue(instruction.cookingMethodParagraph),
    },
    {
      label: 'Ordered steps',
      value: displayValue(instruction.cookingStepsOrdered),
    },
    {
      label: 'Summary',
      value: displayValue(instruction.instructionText),
    },
  ].filter((part): part is { label: string; value: string } => part.value !== null)
}

function instructionText(instruction: RecipeInstructionViewModel): string {
  return (
    displayValue(instruction.instructionText) ??
    displayValue(instruction.cookingStepsOrdered) ??
    displayValue(instruction.cookingMethodParagraph) ??
    'No instruction text is provided.'
  )
}

function Fact({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="min-w-0">
      <dt className="font-bold text-ink">{label}</dt>
      <dd className="mt-0.5 text-muted">{value ?? 'Not provided'}</dd>
    </div>
  )
}

function RecipeInstructions({ recipe }: { recipe: RecipeDetailViewModel }) {
  const summary = recipe.methodSummary

  return (
    <div className="grid gap-5">
      <section aria-labelledby="method-summary-title">
        <h2
          className="section-title text-base font-extrabold"
          id="method-summary-title"
        >
          Cooking method summary
        </h2>
        {summary && summaryParts(summary).length > 0 ? (
          <Card className="mt-3 rounded-2xl p-4">
            {summaryParts(summary).map((part) => (
              <p className="mt-1 text-xs leading-5 text-muted" key={part.label}>
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

      <section aria-labelledby="numbered-instructions-title">
        <h2
          className="section-title text-base font-extrabold"
          id="numbered-instructions-title"
        >
          Instructions
        </h2>
        {recipe.instructions.length === 0 ? (
          <p className="mt-2 text-sm text-muted">
            No numbered instructions are provided.
          </p>
        ) : (
          <ol className="mt-3 grid gap-4">
            {recipe.instructions.map((step) => (
              <li
                className="flex gap-3"
                key={`${step.id}-${step.stepNumber}-${instructionText(step)}`}
              >
                <span className="text-sm font-bold text-spice-orange">
                  {String(step.stepNumber).padStart(2, '0')}
                </span>
                <div>
                  <h3 className="text-sm font-bold text-ink">
                    Step {step.stepNumber}
                  </h3>
                  <p className="mt-1 text-sm leading-6 text-muted">
                    {instructionText(step)}
                  </p>
                </div>
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  )
}

interface StateCardProps {
  message: string
  onBack: () => void
  title: string
  action?: ReactNode
}

function StateCard({ action, message, onBack, title }: StateCardProps) {
  return (
    <MobileScreen aria-labelledby="recipe-details-title">
      <ScreenHeader onBack={onBack} />
      <Card className="mt-8 text-center">
        <h1 className="text-xl font-extrabold" id="recipe-details-title">
          {title}
        </h1>
        <p className="mt-2 text-sm leading-6 text-muted">{message}</p>
        {action}
      </Card>
    </MobileScreen>
  )
}

export function RecipeDetailsScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const { recipeId } = useParams()
  const goBack = useNavigateBack('/recipes/recommendations')
  const flow = useCookingFlow()
  const toggleSavedRecipe = useToggleSavedRecipe()

  const routeState = location.state as RecipeDetailsRouteState | null
  const [activeTab, setActiveTab] = useState<RecipeTab>(
    routeState?.tab ?? 'ingredients',
  )

  const hasValidId = parseApiId(recipeId) !== null
  const recipeQuery = useRecipe(recipeId)
  const retry = () => void recipeQuery.refetch()

  if (!hasValidId) {
    return (
      <StateCard
        message="This recipe link does not contain a valid numeric recipe ID."
        onBack={goBack}
        title="Invalid recipe ID."
      />
    )
  }

  if (recipeQuery.isPending) {
    return (
      <StateCard
        action={
          <div className="mt-5">
            <LoadingIndicator label="Loading recipe..." />
          </div>
        }
        message="Fetching the recipe from the Laravel API."
        onBack={goBack}
        title="Loading recipe..."
      />
    )
  }

  if (recipeQuery.isError) {
    const isNotFound =
      recipeQuery.error.status === 404 ||
      recipeQuery.error.code === 'NOT_FOUND'

    return (
      <StateCard
        action={
          isNotFound ? undefined : (
            <Button
              className="mt-4"
              onClick={retry}
              size="sm"
            >
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
        title={isNotFound ? 'Recipe not found.' : 'Recipe could not be loaded.'}
      />
    )
  }

  const recipe = recipeQuery.data
  if (!recipe) {
    return (
      <StateCard
        action={
          <Button
            className="mt-4"
            onClick={retry}
            size="sm"
          >
            Try again
          </Button>
        }
        message="The recipe service returned no recipe data."
        onBack={goBack}
        title="Recipe could not be loaded."
      />
    )
  }

  const servings = flow.ingredients.length
    ? flow.servings
    : (routeState?.servings ?? flow.servings)
  const isFavorite = flow.favoriteRecipeIds.includes(recipe.id)
  const userQuantities = collectUserQuantities(flow.ingredients)

  function handleStartCooking() {
    flow.setServings(servings)
    flow.selectRecipe(recipe.id)
    navigate(`/cooking/${recipe.id}`, {
      state: { recipeId: recipe.id, servings },
    })
  }

  return (
    <MobileScreen aria-labelledby="recipe-details-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <div className="mt-2 grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] lg:items-start">
        <div className="relative overflow-hidden rounded-3xl shadow-elevated">
          <WireframeImagePlaceholder
            accessibleLabel={`No image is provided for ${recipe.name}`}
            className="h-[164px] w-full rounded-3xl border-0 md:h-[320px] lg:h-[520px]"
            label="Image not provided"
          />
          <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 bg-gradient-to-t from-forest-green/35 via-transparent to-transparent"
          />
          <button
            aria-label={
              isFavorite
                ? `Remove ${recipe.name} from favourites`
                : `Add ${recipe.name} to favourites`
            }
            aria-pressed={isFavorite}
            className="absolute right-3 top-3 flex h-10 w-10 items-center justify-center rounded-full bg-white/95 text-chilli-red shadow-card"
            onClick={() => void toggleSavedRecipe(recipe.id)}
            type="button"
          >
            <Heart
              aria-hidden="true"
              className={cn(isFavorite && 'fill-chilli-red')}
              size={20}
            />
          </button>
        </div>

        <section className="mt-5 lg:mt-0">
          <p className="eyebrow-label">Recipe details</p>
          <h1
            className="mt-1 text-[1.75rem] font-extrabold"
            id="recipe-details-title"
          >
            {recipe.name}
          </h1>

          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-muted">
            <span className="flex items-center gap-1">
              <Clock aria-hidden="true" size={15} />
              <span>{minuteLabel(recipe.totalTimeMinutes)} total</span>
            </span>
            <span className="ml-auto flex items-center gap-1 font-semibold text-ink">
              <Users aria-hidden="true" size={15} />
              <span>
                {servings} {servings === 1 ? 'serving' : 'servings'}
              </span>
            </span>
          </div>

          {displayValue(recipe.description) && (
            <p className="mt-4 text-sm leading-6 text-muted">
              {recipe.description}
            </p>
          )}

          <dl className="glass-panel mt-5 grid grid-cols-2 gap-4 rounded-2xl p-4 text-xs">
            <Fact label="Category" value={recipe.recipeCategory?.name ?? null} />
            <Fact label="Main ingredient" value={recipe.mainIngredient} />
            <Fact label="Recipe type" value={recipe.recipeType} />
            <Fact label="Region" value={recipe.region} />
            <Fact
              label="Preparation time"
              value={minuteLabel(recipe.preparationTimeMinutes)}
            />
            <Fact
              label="Cooking time"
              value={minuteLabel(recipe.cookingTimeMinutes)}
            />
            <Fact
              label="Total time"
              value={minuteLabel(recipe.totalTimeMinutes)}
            />
            <Fact label="Time arithmetic" value={recipe.timeArithmeticStatus} />
            <Fact label="Source yield" value={recipe.sourceYield} />
            <Fact label="Yield people" value={recipe.yieldPeople} />
            <Fact
              label="Time estimated"
              value={recipe.timeIsEstimated ? 'Yes' : 'No'}
            />
            <Fact
              label="Yield estimated"
              value={recipe.yieldIsEstimated ? 'Yes' : 'No'}
            />
          </dl>

          <RecipeTabs
            activeTab={activeTab}
            idPrefix="recipe-details"
            onChange={setActiveTab}
          />

          <div
            aria-labelledby={`recipe-details-tab-${activeTab}`}
            className="mt-5"
            id="recipe-details-panel"
            role="tabpanel"
          >
            {activeTab === 'ingredients' && (
              <ApiRecipeIngredientList
                ingredients={recipe.recipeIngredients}
                userQuantities={userQuantities}
              />
            )}

            {activeTab === 'instructions' && (
              <RecipeInstructions recipe={recipe} />
            )}

            {activeTab === 'video' && (
              <VideoUnavailable recipeName={recipe.name} />
            )}
          </div>

          <section aria-labelledby="recipe-sources-title" className="mt-8">
            <h2
              className="section-title text-base font-extrabold"
              id="recipe-sources-title"
            >
              Recipe sources
            </h2>
            <div className="mt-3">
              <RecipeSourceList sources={recipe.recipeSources} />
            </div>
          </section>

          <RecipeDataQualityPanel issues={recipe.dataQualityIssues} />

          <Button
            className="mt-8 h-[50px] w-full"
            onClick={handleStartCooking}
            size="lg"
          >
            Start Cooking
          </Button>
        </section>
      </div>
    </MobileScreen>
  )
}
