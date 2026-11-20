import { useEffect, useMemo, useState } from 'react'
import { ChevronLeft, ChevronRight, Plus, Search, X } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { BottomNavigation } from '../../components/navigation/BottomNavigation'
import { RecipeCard } from '../../components/recipes/RecipeCard'
import { Button } from '../../components/ui/Button'
import { ChoiceChip } from '../../components/ui/ChoiceChip'
import { Input } from '../../components/ui/Input'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import { useAppState } from '../../context/appStateStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { useDebouncedValue } from '../../hooks/useDebouncedValue'
import {
  useIngredients,
  useRecipeCategories,
  useRecipes,
} from '../../hooks/queries'
import { EMPTY_SEARCH_FILTERS } from '../../services/appStateService'
import type { SearchFilterState } from '../../types/appState'
import type { RecipeListParams } from '../../types/apiRecipes'
import {
  recipeDurationLabel,
  recipeMetaLabel,
} from '../../utils/recipePresentation'

const PAGE_SIZE = 6
const SEARCH_DELAY_MS = 300

const TIME_OPTIONS: Array<{
  value: SearchFilterState['cookingTime']
  label: string
}> = [
  { value: 'under-15', label: 'Under 15 min' },
  { value: '15-30', label: '15-30 min' },
  { value: '30-plus', label: '30+ min' },
]

function cloneFilters(filters: SearchFilterState): SearchFilterState {
  return { ...filters, ingredients: [...filters.ingredients] }
}

function normalizeIngredientName(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/\([^)]*\)/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

function matchesCookingTime(
  minutes: number | null | undefined,
  filter: SearchFilterState['cookingTime'],
): boolean {
  if (filter === 'all') {
    return true
  }
  if (typeof minutes !== 'number' || !Number.isFinite(minutes)) {
    return false
  }
  if (filter === 'under-15') {
    return minutes < 15
  }
  if (filter === '15-30') {
    return minutes >= 15 && minutes < 30
  }
  return minutes >= 30
}

export function SearchScreen() {
  const navigate = useNavigate()
  const goBack = useNavigateBack('/home')
  const { searchFilters, setSearchFilters } = useAppState()
  const [draft, setDraft] = useState(() => cloneFilters(searchFilters))
  const [showIngredientOptions, setShowIngredientOptions] = useState(false)
  const [status, setStatus] = useState('')
  const [categoryId, setCategoryId] = useState('')

  const [debouncedQuery, setDebouncedQuery] = useDebouncedValue(
    draft.query,
    SEARCH_DELAY_MS,
  )

  useEffect(() => {
    const query = debouncedQuery.trim()
    if (query !== searchFilters.query) {
      setSearchFilters({ ...searchFilters, query })
    }
  }, [debouncedQuery, searchFilters, setSearchFilters])

  // Reset pagination when any API filter changes without a state update inside
  // an effect. Keeping the filter key with the page value makes the reset
  // deterministic on the render where the filter changes.
  const appliedIngredientKey = searchFilters.ingredients.join('\u0000')
  const filterKey = `${searchFilters.query}\u0000${categoryId}\u0000${appliedIngredientKey}`
  const [pageState, setPageState] = useState({ key: filterKey, page: 1 })
  const page = pageState.key === filterKey ? pageState.page : 1

  function goToPage(next: number | ((current: number) => number)) {
    const value = typeof next === 'function' ? next(page) : next
    setPageState({ key: filterKey, page: Math.max(1, value) })
  }

  const categoriesQuery = useRecipeCategories({ page: 1, per_page: 100 })
  const ingredientsQuery = useIngredients({ page: 1, per_page: 100 })

  // The ingredient catalogue provides the numeric IDs accepted by the recipe
  // API. Only one ingredient ID is supported per request, so the first matching
  // selection is applied server-side and the rest remain in the local filter.
  const ingredientId = useMemo(() => {
    const ids = new Map(
      (ingredientsQuery.data?.data ?? []).map((ingredient) => [
        normalizeIngredientName(ingredient.name),
        Number(ingredient.id),
      ]),
    )

    for (const name of searchFilters.ingredients) {
      const id = ids.get(normalizeIngredientName(name))
      if (id !== undefined && Number.isSafeInteger(id) && id > 0) {
        return id
      }
    }
    return undefined
  }, [ingredientsQuery.data, searchFilters.ingredients])

  const recipeParams: RecipeListParams = {
    page,
    per_page: PAGE_SIZE,
    sort: 'name',
    ...(searchFilters.query ? { search: searchFilters.query } : {}),
    ...(categoryId ? { recipe_category_id: Number(categoryId) } : {}),
    ...(ingredientId === undefined ? {} : { ingredient_id: ingredientId }),
  }
  const recipesQuery = useRecipes(recipeParams)

  const categories = categoriesQuery.data?.data ?? []
  const ingredientOptions = (ingredientsQuery.data?.data ?? [])
    .map((ingredient) => ingredient.name)
    .filter(
      (name) =>
        !draft.ingredients.some(
          (selected) =>
            normalizeIngredientName(selected) === normalizeIngredientName(name),
        ),
    )

  const recipes = (recipesQuery.data?.data ?? []).filter((recipe) =>
    matchesCookingTime(recipe.durationMinutes, searchFilters.cookingTime),
  )
  const pagination = recipesQuery.data?.pagination
  const hasNextPage = Boolean(recipesQuery.data?.links?.next)

  function applyFilters() {
    setDebouncedQuery(draft.query)
    setSearchFilters(draft)
    goToPage(1)
    setShowIngredientOptions(false)
    setStatus('Filters applied.')
  }

  function resetFilters() {
    setDraft(cloneFilters(EMPTY_SEARCH_FILTERS))
    setDebouncedQuery('')
    setSearchFilters(EMPTY_SEARCH_FILTERS)
    setCategoryId('')
    goToPage(1)
    setShowIngredientOptions(false)
    setStatus('Filters reset.')
  }

  function addIngredient(ingredient: string) {
    setDraft((current) => ({
      ...current,
      ingredients: [...current.ingredients, ingredient],
    }))
    setShowIngredientOptions(false)
  }

  function removeIngredient(ingredient: string) {
    setDraft((current) => ({
      ...current,
      ingredients: current.ingredients.filter((item) => item !== ingredient),
    }))
  }

  return (
    <MobileScreen aria-labelledby="search-title" className="pb-28">
      <ScreenHeader onBack={goBack} />

      <p className="eyebrow-label mt-2">Find your next meal</p>
      <h1
        className="mt-1 text-[28px] leading-tight font-extrabold"
        id="search-title"
      >
        Search and Filters
      </h1>

      <div className="mt-5 grid gap-8 lg:grid-cols-[minmax(280px,360px)_minmax(0,1fr)] lg:items-start">
        <div>
          <div>
            <Input
              className="rounded-lg border-progress-track/70 text-ink placeholder:text-muted"
              endAdornment={
                <Search aria-hidden="true" className="text-muted" size={18} />
              }
              hideLabel
              label="Search recipes or ingredients"
              onChange={(event) =>
                setDraft((current) => ({
                  ...current,
                  query: event.target.value,
                }))
              }
              placeholder="Search recipes or ingredients"
              value={draft.query}
            />
          </div>

          <div className="mt-6 flex items-center justify-between">
            <h2 className="text-base font-bold">Filters</h2>
            <Button onClick={resetFilters} size="sm" variant="ghost">
              Reset
            </Button>
          </div>

          <section
            aria-labelledby="category-title"
            className="glass-panel mt-5 rounded-2xl p-4"
          >
            <h3 className="text-sm font-bold text-ink" id="category-title">
              Recipe category
            </h3>
            <div
              aria-label="Recipe category"
              className="mt-3 flex flex-wrap gap-2"
              role="group"
            >
              <ChoiceChip
                label="All categories"
                onClick={() => setCategoryId('')}
                selected={categoryId === ''}
              />
              {categoriesQuery.isPending ? (
                <p className="text-xs text-muted">Loading categories...</p>
              ) : categoriesQuery.isError ? (
                <p className="text-xs text-chilli-red">
                  Categories are unavailable.
                </p>
              ) : (
                categories.map((category) => (
                  <ChoiceChip
                    key={category.id}
                    label={category.name}
                    onClick={() => setCategoryId(category.id)}
                    selected={categoryId === category.id}
                  />
                ))
              )}
            </div>
          </section>

          <section
            aria-labelledby="cooking-time-title"
            className="glass-panel mt-5 rounded-2xl p-4"
          >
            <h3 className="text-sm font-bold text-ink" id="cooking-time-title">
              Cooking Time
            </h3>
            <div
              aria-label="Cooking time"
              className="mt-3 grid grid-cols-3 gap-2"
              role="group"
            >
              {TIME_OPTIONS.map((option) => (
                <ChoiceChip
                  key={option.value}
                  label={option.label}
                  onClick={() =>
                    setDraft((current) => ({
                      ...current,
                      cookingTime:
                        current.cookingTime === option.value
                          ? 'all'
                          : option.value,
                    }))
                  }
                  selected={draft.cookingTime === option.value}
                />
              ))}
            </div>
            <p className="mt-2 text-[11px] leading-4 text-muted">
              Cooking time is filtered on the loaded API page.
            </p>
          </section>

          <section
            aria-labelledby="ingredients-title"
            className="glass-panel mt-5 rounded-2xl p-4"
          >
            <h3 className="text-sm font-bold text-ink" id="ingredients-title">
              Ingredients
            </h3>
            <div
              aria-label="Selected ingredients"
              className="mt-3 grid grid-cols-2 gap-2"
              role="group"
            >
              {draft.ingredients.map((ingredient) => (
                <button
                  aria-label={`Remove ${ingredient}`}
                  className="flex min-h-10 items-center justify-between gap-1 rounded-xl border border-forest-green/15 bg-gradient-to-r from-[#2E7D32] to-[#0B3D2E] px-3 text-left text-sm font-bold text-white shadow-md"
                  key={ingredient}
                  onClick={() => removeIngredient(ingredient)}
                  type="button"
                >
                  <span className="truncate">{ingredient}</span>
                  <X aria-hidden="true" className="shrink-0" size={15} />
                </button>
              ))}
            </div>

            <Button
              className="mt-4 h-[44px] w-full"
              onClick={() => setShowIngredientOptions((visible) => !visible)}
              size="md"
              variant="secondary"
            >
              <Plus aria-hidden="true" size={16} />
              Add Ingredient
            </Button>

            {showIngredientOptions && (
              <div
                aria-label="Available ingredients"
                className="mt-3 grid grid-cols-2 gap-2 rounded-lg border border-progress-track/50 bg-white p-3"
                role="group"
              >
                {ingredientsQuery.isPending ? (
                  <p className="col-span-2 text-xs text-muted">
                    Loading ingredients...
                  </p>
                ) : ingredientOptions.length === 0 ? (
                  <p className="col-span-2 text-xs text-muted">
                    All API ingredients are already selected.
                  </p>
                ) : (
                  ingredientOptions.map((ingredient) => (
                    <button
                      className="min-h-9 rounded-xl border border-forest-green/12 bg-white/80 px-2 text-xs font-bold text-ink transition-colors hover:border-spice-orange/50 hover:bg-warm-cream"
                      key={ingredient}
                      onClick={() => addIngredient(ingredient)}
                      type="button"
                    >
                      {ingredient}
                    </button>
                  ))
                )}
              </div>
            )}
            <p className="mt-2 text-[11px] leading-4 text-muted">
              The API filters by the first matching ingredient; extra
              selections remain in the local filter.
            </p>
          </section>

          <Button
            className="mt-6 h-[50px] w-full"
            onClick={applyFilters}
            size="lg"
            variant="ink"
          >
            Apply Filters
          </Button>

          <p aria-live="polite" className="sr-only">
            {status}
          </p>
        </div>

        <section
          aria-labelledby="search-results-title"
          className="mt-10 lg:mt-0"
        >
          <h2 className="text-lg font-bold" id="search-results-title">
            Recipes
          </h2>
          <p className="mt-2 text-xs leading-5 text-muted">
            Results come from the Laravel recipe API. Ratings and images are not
            provided.
          </p>

          {recipesQuery.isPending ? (
            <div className="mt-6">
              <LoadingIndicator label="Searching recipes..." />
            </div>
          ) : recipesQuery.isError ? (
            <div className="mt-5 rounded-lg border border-chilli-red/20 bg-white/70 p-6 text-center">
              <h3 className="text-base font-bold">Recipe search failed.</h3>
              <p className="mt-2 text-sm leading-6 text-muted">
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
            <div className="mt-5 rounded-lg border border-progress-track/50 p-6 text-center">
              <h3 className="text-base font-bold">
                No recipes match these filters.
              </h3>
              <p className="mt-2 text-sm leading-6 text-muted">
                Try a different search, category or ingredient.
              </p>
              <Button className="mt-4" onClick={resetFilters} size="sm">
                Reset filters
              </Button>
            </div>
          ) : (
            <p className="mt-1 text-xs text-muted">
              {recipes.length} {recipes.length === 1 ? 'recipe' : 'recipes'} on
              this page
              {pagination
                ? ` · page ${pagination.current_page} of ${pagination.last_page}`
                : ''}
            </p>
          )}

          <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {recipes.map((recipe) => (
              <RecipeCard
                duration={recipeDurationLabel(recipe.durationMinutes)}
                key={recipe.id}
                meta={recipeMetaLabel(recipe)}
                name={recipe.name}
                onSelect={() => navigate(`/recipes/${recipe.id}`)}
              />
            ))}
          </div>

          {pagination && pagination.last_page > 1 && (
            <nav
              aria-label="Recipe result pages"
              className="mt-6 flex items-center justify-center gap-3"
            >
              <Button
                disabled={page <= 1 || recipesQuery.isFetching}
                onClick={() => goToPage((current) => current - 1)}
                size="sm"
                variant="secondary"
              >
                <ChevronLeft aria-hidden="true" size={16} />
                Previous
              </Button>
              <span className="text-xs font-bold text-muted">
                Page {pagination.current_page} of {pagination.last_page}
              </span>
              <Button
                disabled={!hasNextPage || recipesQuery.isFetching}
                onClick={() => goToPage((current) => current + 1)}
                size="sm"
                variant="secondary"
              >
                Next
                <ChevronRight aria-hidden="true" size={16} />
              </Button>
            </nav>
          )}
        </section>
      </div>

      <BottomNavigation />
    </MobileScreen>
  )
}
