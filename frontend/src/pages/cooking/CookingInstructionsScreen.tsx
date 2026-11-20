import { useState } from 'react'
import { CheckCircle2 } from 'lucide-react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { CookingStepCard } from '../../components/cooking/CookingStepCard'
import { CookingStepNavigation } from '../../components/cooking/CookingStepNavigation'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { RecipeIngredientList } from '../../components/recipes/RecipeIngredientList'
import { RecipeTabs } from '../../components/recipes/RecipeTabs'
import { VideoUnavailable } from '../../components/recipes/VideoUnavailable'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { useCookingFlow } from '../../context/cookingFlowStore'
import {
  applyUserQuantities,
  getDefaultRecipe,
  getRecipeById,
} from '../../services/mockRecipeService'
import type { RecipeTab } from '../../types/recipe'

interface CookingRouteState {
  servings?: number
}

export function CookingInstructionsScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const { recipeId } = useParams()
  const flow = useCookingFlow()

  const baseRecipe =
    getRecipeById(recipeId ?? flow.selectedRecipeId ?? '') ?? getDefaultRecipe()
  const recipe = applyUserQuantities(baseRecipe, flow.ingredients)

  const routeState = location.state as CookingRouteState | null
  const servings = flow.ingredients.length
    ? flow.servings
    : (routeState?.servings ?? recipe.servings)

  const [activeTab, setActiveTab] = useState<RecipeTab>('instructions')
  const [isComplete, setIsComplete] = useState(false)

  const steps = recipe.instructions
  const lastIndex = Math.max(0, steps.length - 1)
  const currentIndex = Math.min(Math.max(0, flow.stepIndex), lastIndex)

  function handlePrevious() {
    if (currentIndex === 0) {
      return
    }
    flow.setStepIndex(currentIndex - 1)
    setIsComplete(false)
  }

  function handleNext() {
    if (currentIndex < lastIndex) {
      flow.setStepIndex(currentIndex + 1)
      return
    }
    setIsComplete(true)
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
            <RecipeIngredientList
              availableIngredients={recipe.availableIngredients}
              missingIngredients={recipe.missingIngredients}
            />
          )}

          {activeTab === 'instructions' && (
            <>
              <div className="grid gap-3 md:grid-cols-2 md:gap-5">
                {steps.map((step, index) => (
                  <CookingStepCard
                    isCurrent={index === currentIndex}
                    key={step.id}
                    onNext={
                      index === currentIndex && !isComplete
                        ? handleNext
                        : undefined
                    }
                    step={step}
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

          {activeTab === 'video' && (
            <VideoUnavailable recipeName={recipe.name} />
          )}
        </div>
      </section>
    </MobileScreen>
  )
}
