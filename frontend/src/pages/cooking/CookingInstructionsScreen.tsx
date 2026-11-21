import { useEffect, useState } from 'react'
import { CheckCircle2 } from 'lucide-react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { CookingRecipeStateCard } from '../../components/cooking/CookingRecipeStateCard'
import { CookingStepCard } from '../../components/cooking/CookingStepCard'
import { CookingStepNavigation } from '../../components/cooking/CookingStepNavigation'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ApiRecipeIngredientList } from '../../components/recipes/ApiRecipeIngredientList'
import { RecipeTabs } from '../../components/recipes/RecipeTabs'
import { VideoUnavailable } from '../../components/recipes/VideoUnavailable'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import { useRecipe } from '../../hooks/queries'
import { useCookingRecipeId } from '../../hooks/useCookingRecipeId'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import type { RecipeTab } from '../../types/recipe'
import {
  collectUserQuantities,
  instructionText,
  methodSummaryParts,
  numberedCookingSteps,
  stepDescription,
} from '../../utils/cookingRecipe'

interface CookingRouteState {
  servings?: number
}

export function CookingInstructionsScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const { recipeId } = useParams()
  const { canonicalId, flow, hasValidId, numericId } = useCookingRecipeId(recipeId)
  const goBack = useNavigateBack(
    canonicalId ? `/recipes/${canonicalId}` : '/recipes/recommendations',
  )
  const recipeQuery = useRecipe(numericId)
  const retry = () => void recipeQuery.refetch()

  const [activeTab, setActiveTab] = useState<RecipeTab>('instructions')
  const [completedRecipeId, setCompletedRecipeId] = useState<string | null>(null)
  const { setStepIndex, stepIndex } = flow

  const steps = recipeQuery.data
    ? numberedCookingSteps(recipeQuery.data.instructions)
    : []

  useEffect(() => {
    if (steps.length === 0) {
      return
    }
    if (stepIndex > steps.length - 1) {
      setStepIndex(steps.length - 1)
    }
  }, [setStepIndex, stepIndex, steps.length])

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
            <LoadingIndicator label="Loading cooking instructions..." />
          </div>
        }
        message="Fetching the recipe from the Laravel API."
        onBack={goBack}
        title="Loading cooking instructions..."
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
        title={
          isNotFound ? 'Recipe not found.' : 'Instructions could not be loaded.'
        }
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
        title="Instructions could not be loaded."
      />
    )
  }

  const routeState = location.state as CookingRouteState | null
  const servings = routeState?.servings ?? flow.servings
  const lastIndex = Math.max(0, steps.length - 1)
  const currentIndex = Math.min(Math.max(0, stepIndex), lastIndex)
  const userQuantities = collectUserQuantities(flow.ingredients)
  const summaryParts = methodSummaryParts(recipe.methodSummary)
  const isComplete = canonicalId !== null && completedRecipeId === canonicalId

  function handlePrevious() {
    if (currentIndex === 0) {
      return
    }
    setStepIndex(currentIndex - 1)
    setCompletedRecipeId(null)
  }

  function handleNext() {
    if (currentIndex < lastIndex) {
      setStepIndex(currentIndex + 1)
      return
    }
    setCompletedRecipeId(canonicalId)
  }

  return (
    <MobileScreen aria-labelledby="cooking-title" className="pb-12">
      <header className="flex min-h-11 items-center" />

      <section>
        <p className="eyebrow-label">Let&apos;s cook</p>
        <h1 className="mt-1 text-xl font-extrabold" id="cooking-title">
          {recipe.name}
        </h1>
        <h2 className="mt-2 text-[1.25rem] font-bold leading-7 text-ink">
          Step-by-Step Cooking Instructions
        </h2>
        <p className="mt-2 text-xs text-muted">
          Cooking for {servings} {servings === 1 ? 'person' : 'people'}
        </p>

        <RecipeTabs
          activeTab={activeTab}
          idPrefix="cooking"
          onChange={setActiveTab}
        />

        <div
          aria-labelledby={`cooking-tab-${activeTab}`}
          className="mt-5"
          id="cooking-panel"
          role="tabpanel"
        >
          {activeTab === 'ingredients' && (
            <ApiRecipeIngredientList
              ingredients={recipe.recipeIngredients}
              userQuantities={userQuantities}
            />
          )}

          {activeTab === 'instructions' && (
            <>
              <section aria-labelledby="cooking-method-title">
                <h3
                  className="section-title text-base font-extrabold"
                  id="cooking-method-title"
                >
                  Cooking method summary
                </h3>
                {summaryParts.length > 0 ? (
                  <Card className="mt-3 grid gap-3 rounded-2xl p-4 md:grid-cols-2 md:gap-5">
                    {summaryParts.map((part) => (
                      <p
                        className="text-sm leading-6 text-muted"
                        key={part.label}
                      >
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

              {steps.length === 0 ? (
                <Card className="mt-5 text-sm text-muted">
                  No numbered instructions are provided for this recipe.
                </Card>
              ) : (
                <>
                  <div className="mt-5 grid gap-3 md:grid-cols-2 md:gap-5">
                    {steps.map((step, index) => (
                      <CookingStepCard
                        isCurrent={index === currentIndex}
                        key={step.id}
                        onNext={
                          index === currentIndex && !isComplete
                            ? handleNext
                            : undefined
                        }
                        step={{
                          id: step.id,
                          number: step.stepNumber,
                          title: instructionText(step),
                          description: stepDescription(step) ?? '',
                        }}
                      />
                    ))}
                  </div>
                  <CookingStepNavigation
                    currentIndex={currentIndex}
                    isComplete={isComplete}
                    onNext={handleNext}
                    onPrevious={handlePrevious}
                    totalSteps={steps.length}
                  />

                  {isComplete && (
                    <Card
                      aria-live="polite"
                      className="border-fresh-green/40 bg-gradient-to-br from-warm-cream to-white text-center"
                    >
                      <CheckCircle2
                        aria-hidden="true"
                        className="mx-auto text-fresh-green"
                        size={34}
                      />
                      <h3 className="mt-3 text-lg font-bold">Recipe complete!</h3>
                      <p className="mt-2 text-sm leading-6 text-muted">
                        You finished cooking {recipe.name}. Enjoy your meal!
                      </p>
                      <Button
                        className="mt-4"
                        onClick={() => navigate('/recipes/recommendations')}
                        size="sm"
                      >
                        Back to recipes
                      </Button>
                    </Card>
                  )}
                </>
              )}
            </>
          )}

          {activeTab === 'video' && (
            <VideoUnavailable recipeName={recipe.name} />
          )}
        </div>
      </section>
    </MobileScreen>
  )
}
