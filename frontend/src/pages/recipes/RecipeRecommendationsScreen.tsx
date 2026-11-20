import { useMemo, useState } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { RecipeCard } from '../../components/recipes/RecipeCard'
import { RecipeFilters } from '../../components/recipes/RecipeFilters'
import { Button } from '../../components/ui/Button'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  getMockRecipes,
  MOCK_RECOMMENDATION_NOTICE,
} from '../../services/mockRecipeService'
import {
  DEFAULT_RECIPE_FILTERS,
  DEFAULT_SORT_OPTION,
  getRecommendedRecipes,
  type RecipeFilterOptions,
} from '../../services/recommendationService'
import type { RecipeSortOption } from '../../types/recipe'

interface RecommendationsRouteState {
  servings?: number
}

const SORT_OPTIONS: Array<{
  value: RecipeSortOption
  label: string
}> = [
  { value: 'best-match', label: 'Best match' },
  { value: 'rating', label: 'Rating' },
  { value: 'prep-time', label: 'Prep time' },
  { value: 'name', label: 'Name (A–Z)' },
]

export function RecipeRecommendationsScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const goBack = useNavigateBack('/ingredients/serving-size')
  const flow = useCookingFlow()

  const routeServings = (location.state as RecommendationsRouteState | null)
    ?.servings
  const servings = flow.ingredients.length
    ? flow.servings
    : (routeServings ?? flow.servings)

  const [sort, setSort] = useState<RecipeSortOption>(DEFAULT_SORT_OPTION)
  const [filters, setFilters] = useState<RecipeFilterOptions>(
    DEFAULT_RECIPE_FILTERS,
  )
  const [showFilters, setShowFilters] = useState(false)

  const recipes = useMemo(
    () =>
      getRecommendedRecipes(getMockRecipes(), {
        servings,
        sort,
        filters,
      }),
    [filters, servings, sort],
  )

  function handleSelectRecipe(recipeId: string) {
    flow.setServings(servings)
    flow.selectRecipe(recipeId)
    navigate(`/recipes/${recipeId}`, {
      state: { recipeId, servings },
    })
  }

  return (
    <MobileScreen aria-labelledby="recommendations-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <section className="mt-3">
        <h1 className="text-[1.75rem] font-bold" id="recommendations-title">
          Recommended Recipes
        </h1>
        <p className="mt-2 text-sm text-muted">
          Recipes for {servings} {servings === 1 ? 'person' : 'people'}
        </p>
        <p className="mt-2 rounded-lg bg-warm-cream/70 px-3 py-2 text-xs leading-5 text-muted">
          {MOCK_RECOMMENDATION_NOTICE}
        </p>

        <div className="mt-5 flex items-center gap-3">
          <Button
            aria-controls="recipe-filter-panel"
            aria-expanded={showFilters}
            onClick={() => setShowFilters((current) => !current)}
            size="sm"
            variant="secondary"
          >
            <SlidersHorizontal aria-hidden="true" size={16} />
            Filters
          </Button>
          <label className="ml-auto flex items-center gap-1 text-xs text-muted">
            <span>Sort:</span>
            <select
              aria-label="Sort recipes"
              className="rounded-control border border-forest-green/20 bg-white px-2 py-2 text-xs font-semibold text-forest-green focus:border-fresh-green focus:outline-none focus:ring-2 focus:ring-fresh-green/25"
              onChange={(event) =>
                setSort(event.target.value as RecipeSortOption)
              }
              value={sort}
            >
              {SORT_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
        </div>

        {showFilters && (
          <div id="recipe-filter-panel">
            <RecipeFilters
              filters={filters}
              onChange={setFilters}
              onClear={() => setFilters(DEFAULT_RECIPE_FILTERS)}
            />
          </div>
        )}

        {recipes.length === 0 ? (
          <div className="mt-8 rounded-card border border-dashed border-forest-green/20 p-6 text-center">
            <h2 className="text-base font-bold">No matching recipes</h2>
            <p className="mt-2 text-sm text-muted">
              Try clearing a filter to see more recipes.
            </p>
            <Button
              className="mt-4"
              onClick={() => setFilters(DEFAULT_RECIPE_FILTERS)}
              size="sm"
            >
              Clear filters
            </Button>
          </div>
        ) : (
          <div className="mt-5 grid gap-5">
            {recipes.map((recipe) => (
              <RecipeCard
                duration={`${recipe.durationMinutes} min`}
                image={recipe.image}
                isFavorite={flow.favoriteRecipeIds.includes(recipe.id)}
                key={recipe.id}
                matchPercent={recipe.matchPercent}
                name={recipe.name}
                onSelect={() => handleSelectRecipe(recipe.id)}
                onToggleFavorite={() => flow.toggleFavorite(recipe.id)}
                rating={recipe.rating}
              />
            ))}
          </div>
        )}

        <p className="mt-6 text-center text-xs text-muted">Scroll to recipes</p>
      </section>
    </MobileScreen>
  )
}
