import { useMemo, useState } from 'react'
import { SlidersHorizontal } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { RecipeCard } from '../../components/recipes/RecipeCard'
import { RecipeFilters } from '../../components/recipes/RecipeFilters'
import { Button } from '../../components/ui/Button'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  useRecipeDetails,
  useRecipes,
  useToggleSavedRecipe,
} from '../../hooks/queries'
import {
  createApiRecommendationCandidates,
  type ApiRecommendationCandidate,
} from '../../services/apiRecommendationAdapter'
import {
  DEFAULT_RECIPE_FILTERS,
  DEFAULT_SORT_OPTION,
  getRecommendedRecipes,
  type RecipeFilterOptions,
} from '../../services/recommendationService'
import type { RecipeSortOption } from '../../types/recipe'
import {
  recipeDurationLabel,
  recipeMetaLabel,
} from '../../utils/recipePresentation'

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

function candidateMeta(candidate: ApiRecommendationCandidate): string | null {
  const parts = [recipeMetaLabel(candidate.summary)]
  if (candidate.matchedIngredientNames.length > 0) {
    const count = candidate.matchedIngredientNames.length
    parts.push(`${count} matching ingredient${count === 1 ? '' : 's'}`)
  }
  return parts.filter(Boolean).join(' · ') || null
}

export function RecipeRecommendationsScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const goBack = useNavigateBack('/ingredients/serving-size')
  const flow = useCookingFlow()
  const toggleSavedRecipe = useToggleSavedRecipe()

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

  // IDs and quantities stay in the cooking flow for details/cooking screens.
  // The existing deterministic overlap formula intentionally uses names only.
  const selectedIngredients = flow.ingredients
  const selectedIngredientNames = useMemo(
    () => selectedIngredients.map((ingredient) => ingredient.name),
    [selectedIngredients],
  )

  const recipesQuery = useRecipes({ page: 1, per_page: 50, sort: 'name' })
  const summaries = useMemo(
    () => recipesQuery.data?.data ?? [],
    [recipesQuery.data],
  )
  const detailsQuery = useRecipeDetails(summaries.map((recipe) => recipe.id))

  const candidates = useMemo(
    () =>
      createApiRecommendationCandidates(
        summaries,
        detailsQuery.data,
        selectedIngredientNames,
      ),
    [detailsQuery.data, selectedIngredientNames, summaries],
  )

  const recipes = useMemo(
    () =>
      getRecommendedRecipes(candidates, {
        servings,
        sort,
        filters,
      }),
    [candidates, filters, servings, sort],
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
        <p className="eyebrow-label">Picked for your ingredients</p>
        <h1
          className="mt-1 text-[1.8rem] font-extrabold"
          id="recommendations-title"
        >
          Recommended Recipes
        </h1>
        <p className="mt-2 text-sm text-muted">
          Recipes for {servings} {servings === 1 ? 'person' : 'people'}
        </p>
        <p className="glass-panel mt-3 rounded-xl px-3 py-2 text-xs leading-5 text-muted">
          Ingredient overlap is calculated locally from Laravel recipe data.
          Ratings and images are not provided. These are not AI-generated
          recommendations.
          {detailsQuery.isError
            ? ' Ingredient overlap could not be loaded for some recipes.'
            : ''}
        </p>

        <div className="mt-5 grid gap-6 lg:grid-cols-[minmax(220px,280px)_minmax(0,1fr)]">
          <aside>
            <Button
              aria-controls="recipe-filter-panel"
              aria-expanded={showFilters}
              className="lg:hidden"
              onClick={() => setShowFilters((current) => !current)}
              size="sm"
              variant="secondary"
            >
              <SlidersHorizontal aria-hidden="true" size={16} />
              Filters
            </Button>
            <div
              className={showFilters ? 'block' : 'hidden lg:block'}
              id="recipe-filter-panel"
            >
              <RecipeFilters
                filters={filters}
                onChange={setFilters}
                onClear={() => setFilters(DEFAULT_RECIPE_FILTERS)}
              />
            </div>
          </aside>

          <div className="min-w-0">
            <div className="flex items-center justify-end gap-3">
              {detailsQuery.isFetching && (
                <span className="text-[11px] text-muted">
                  Checking ingredient overlap...
                </span>
              )}
              <label className="flex items-center gap-2 text-xs text-muted">
                <span>Sort:</span>
                <select
                  aria-label="Sort recipes"
                  className="rounded-xl border border-forest-green/15 bg-white/90 px-2 py-2 text-xs font-bold text-ink shadow-sm focus:border-spice-orange focus:outline-none focus:ring-4 focus:ring-spice-orange/15"
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

            {recipesQuery.isPending ? (
              <div className="mt-8">
                <LoadingIndicator label="Loading recipe catalogue..." />
              </div>
            ) : recipesQuery.isError ? (
              <div className="mt-8 rounded-card border border-chilli-red/20 bg-white/70 p-6 text-center">
                <h2 className="text-base font-bold">
                  Recipe catalogue unavailable.
                </h2>
                <p className="mt-2 text-sm text-muted">
                  {recipesQuery.error?.message ??
                    'The recipe service is temporarily unavailable.'}
                </p>
                <Button
                  className="mt-4"
                  onClick={() => void recipesQuery.refetch()}
                  size="sm"
                >
                  Try again
                </Button>
              </div>
            ) : recipes.length === 0 ? (
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
              <div className="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                {recipes.map((recipe) => (
                  <RecipeCard
                    duration={recipeDurationLabel(
                      recipe.summary.durationMinutes,
                    )}
                    isFavorite={flow.favoriteRecipeIds.includes(recipe.id)}
                    key={recipe.id}
                    meta={candidateMeta(recipe)}
                    name={recipe.summary.name}
                    onSelect={() => handleSelectRecipe(recipe.id)}
                    onToggleFavorite={() => void toggleSavedRecipe(recipe.id)}
                  />
                ))}
              </div>
            )}
          </div>
        </div>
      </section>
    </MobileScreen>
  )
}
