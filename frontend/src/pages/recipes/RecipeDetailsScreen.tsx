import { useState } from 'react'
import { Clock, Heart, Star, Users } from 'lucide-react'
import { useLocation, useNavigate, useParams } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { RecipeIngredientList } from '../../components/recipes/RecipeIngredientList'
import { RecipeTabs } from '../../components/recipes/RecipeTabs'
import { VideoUnavailable } from '../../components/recipes/VideoUnavailable'
import { Button } from '../../components/ui/Button'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  applyUserQuantities,
  getDefaultRecipe,
  getRecipeById,
} from '../../services/mockRecipeService'
import type { RecipeTab } from '../../types/recipe'
import { cn } from '../../utils/cn'

interface RecipeDetailsRouteState {
  servings?: number
  tab?: RecipeTab
}

export function RecipeDetailsScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const { recipeId } = useParams()
  const goBack = useNavigateBack('/recipes/recommendations')
  const flow = useCookingFlow()

  const baseRecipe =
    getRecipeById(recipeId ?? flow.selectedRecipeId ?? '') ?? getDefaultRecipe()
  const recipe = applyUserQuantities(baseRecipe, flow.ingredients)

  const routeState = location.state as RecipeDetailsRouteState | null
  const servings = flow.ingredients.length
    ? flow.servings
    : (routeState?.servings ?? recipe.servings)

  const [activeTab, setActiveTab] = useState<RecipeTab>(
    routeState?.tab ?? 'ingredients',
  )
  const isFavorite = flow.favoriteRecipeIds.includes(recipe.id)

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

      <div className="relative mt-2 overflow-hidden rounded-card">
        <img
          alt={recipe.imageAlt}
          className="block h-[164px] w-full bg-warm-cream object-cover"
          height={164}
          src={recipe.image}
          width={342}
        />
        <button
          aria-label={
            isFavorite
              ? `Remove ${recipe.name} from favourites`
              : `Add ${recipe.name} to favourites`
          }
          aria-pressed={isFavorite}
          className="absolute right-3 top-3 flex h-10 w-10 items-center justify-center rounded-full bg-white/95 text-chilli-red shadow-card"
          onClick={() => flow.toggleFavorite(recipe.id)}
          type="button"
        >
          <Heart
            aria-hidden="true"
            className={cn(isFavorite && 'fill-chilli-red')}
            size={20}
          />
        </button>
      </div>

      <section className="mt-5">
        <h1 className="text-2xl font-bold" id="recipe-details-title">
          {recipe.name}
        </h1>
        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-muted">
          <span className="flex items-center gap-1">
            <Star
              aria-hidden="true"
              className="fill-spice-orange text-spice-orange"
              size={15}
            />
            <span>{recipe.rating}</span>
          </span>
          <span className="flex items-center gap-1">
            <Clock aria-hidden="true" size={15} />
            <span>{recipe.durationMinutes} min</span>
          </span>
          <span className="ml-auto flex items-center gap-1 font-semibold text-forest-green">
            <Users aria-hidden="true" size={15} />
            <span>
              {servings} {servings === 1 ? 'serving' : 'servings'}
            </span>
          </span>
        </div>

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
            <RecipeIngredientList
              availableIngredients={recipe.availableIngredients}
              missingIngredients={recipe.missingIngredients}
            />
          )}

          {activeTab === 'instructions' && (
            <ol className="grid gap-4">
              {recipe.instructions.map((step) => (
                <li className="flex gap-3" key={step.id}>
                  <span className="text-sm font-bold text-spice-orange">
                    {String(step.number).padStart(2, '0')}
                  </span>
                  <div>
                    <h2 className="text-sm font-bold text-forest-green">
                      {step.title}
                    </h2>
                    <p className="mt-1 text-sm leading-6 text-muted">
                      {step.description}
                    </p>
                  </div>
                </li>
              ))}
            </ol>
          )}

          {activeTab === 'video' && (
            <VideoUnavailable recipeName={recipe.name} />
          )}
        </div>

        <Button
          className="mt-8 h-[46px] w-full"
          onClick={handleStartCooking}
          size="lg"
        >
          Start Cooking
        </Button>
      </section>
    </MobileScreen>
  )
}
