import { useState } from 'react'
import { Plus, Search, X } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { RecipeCard } from '../../components/recipes/RecipeCard'
import { Button } from '../../components/ui/Button'
import { ChoiceChip } from '../../components/ui/ChoiceChip'
import { Input } from '../../components/ui/Input'
import { useAppState } from '../../context/appStateStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { EMPTY_SEARCH_FILTERS } from '../../services/appStateService'
import {
  getRecipeCatalog,
  MOCK_RECOMMENDATION_NOTICE,
} from '../../services/mockRecipeService'
import {
  filterRecipesBySearch,
  getSearchIngredientOptions,
} from '../../services/searchService'
import type { CuisineType, SearchFilterState } from '../../types/appState'

const CUISINE_OPTIONS: CuisineType[] = ['Sri Lankan', 'Other Asian']

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

export function SearchScreen() {
  const navigate = useNavigate()
  const goBack = useNavigateBack('/home')
  const { searchFilters, setSearchFilters } = useAppState()
  const [draft, setDraft] = useState(() => cloneFilters(searchFilters))
  const [showIngredientOptions, setShowIngredientOptions] = useState(false)
  const [status, setStatus] = useState('')

  const results = filterRecipesBySearch(getRecipeCatalog(), searchFilters)
  const ingredientOptions = getSearchIngredientOptions().filter(
    (ingredient) =>
      !draft.ingredients.some(
        (selected) => selected.toLowerCase() === ingredient.toLowerCase(),
      ),
  )

  function applyFilters() {
    setSearchFilters(draft)
    setShowIngredientOptions(false)
    setStatus('Filters applied.')
  }

  function resetFilters() {
    setDraft(cloneFilters(EMPTY_SEARCH_FILTERS))
    setSearchFilters(EMPTY_SEARCH_FILTERS)
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
    <MobileScreen aria-labelledby="search-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <h1 className="mt-2 text-2xl font-bold" id="search-title">
        Search and Filters
      </h1>

      <div className="mt-6">
        <Input
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

      <div className="mt-8 flex items-center justify-between">
        <h2 className="text-lg font-bold">Filters</h2>
        <Button onClick={resetFilters} size="sm" variant="ghost">
          Reset
        </Button>
      </div>

      <section aria-labelledby="cuisine-title" className="mt-6">
        <h3 className="text-sm font-bold text-forest-green" id="cuisine-title">
          Cuisine Type
        </h3>
        <div
          aria-label="Cuisine type"
          className="mt-3 grid grid-cols-2 gap-2"
          role="group"
        >
          {CUISINE_OPTIONS.map((cuisine) => (
            <ChoiceChip
              key={cuisine}
              label={cuisine}
              onClick={() =>
                setDraft((current) => ({
                  ...current,
                  cuisine: current.cuisine === cuisine ? 'all' : cuisine,
                }))
              }
              selected={draft.cuisine === cuisine}
            />
          ))}
        </div>
      </section>

      <section aria-labelledby="cooking-time-title" className="mt-7">
        <h3
          className="text-sm font-bold text-forest-green"
          id="cooking-time-title"
        >
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
                    current.cookingTime === option.value ? 'all' : option.value,
                }))
              }
              selected={draft.cookingTime === option.value}
            />
          ))}
        </div>
      </section>

      <section aria-labelledby="ingredients-title" className="mt-7">
        <h3
          className="text-sm font-bold text-forest-green"
          id="ingredients-title"
        >
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
              className="flex min-h-10 items-center justify-between gap-1 rounded-control border border-fresh-green bg-fresh-green px-3 text-left text-sm font-semibold text-white"
              key={ingredient}
              onClick={() => removeIngredient(ingredient)}
              type="button"
            >
              <span className="truncate">{ingredient}</span>
              <X aria-hidden="true" className="shrink-0" size={15} />
            </button>
          ))}
        </div>

        <button
          aria-expanded={showIngredientOptions}
          className="mx-auto mt-4 flex min-h-9 items-center gap-1 text-sm font-semibold text-fresh-green"
          onClick={() => setShowIngredientOptions((visible) => !visible)}
          type="button"
        >
          <Plus aria-hidden="true" size={16} />
          Add Ingredient
        </button>

        {showIngredientOptions && (
          <div
            aria-label="Available ingredients"
            className="mt-3 grid grid-cols-2 gap-2 rounded-card border border-forest-green/15 bg-warm-cream/50 p-3"
            role="group"
          >
            {ingredientOptions.length === 0 ? (
              <p className="col-span-2 text-xs text-muted">
                All local ingredients are already selected.
              </p>
            ) : (
              ingredientOptions.map((ingredient) => (
                <button
                  className="min-h-9 rounded-control border border-forest-green/20 bg-white px-2 text-xs font-semibold text-forest-green hover:bg-warm-cream"
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
      </section>

      <Button
        className="mt-8 h-[46px] w-full"
        onClick={applyFilters}
        size="lg"
        variant="ink"
      >
        Apply Filters
      </Button>

      <p aria-live="polite" className="mt-3 text-center text-xs text-muted">
        {status}
      </p>

      <section aria-labelledby="search-results-title" className="mt-10">
        <h2 className="text-lg font-bold" id="search-results-title">
          Recipes
        </h2>
        <p className="mt-2 text-xs leading-5 text-muted">
          {MOCK_RECOMMENDATION_NOTICE}
        </p>

        {results.length === 0 ? (
          <div className="mt-5 rounded-card border border-dashed border-forest-green/20 p-6 text-center">
            <h3 className="text-base font-bold">
              No recipes match these filters.
            </h3>
            <p className="mt-2 text-sm leading-6 text-muted">
              Try a different cuisine, cooking time or ingredient.
            </p>
            <Button className="mt-4" onClick={resetFilters} size="sm">
              Reset filters
            </Button>
          </div>
        ) : (
          <p className="mt-1 text-xs text-muted">
            {results.length} {results.length === 1 ? 'recipe' : 'recipes'} found
          </p>
        )}

        <div className="mt-4 grid gap-4">
          {results.map((recipe) => (
            <RecipeCard
              duration={`${recipe.durationMinutes} min`}
              image={recipe.image}
              key={recipe.id}
              name={recipe.name}
              onSelect={() => navigate(`/recipes/${recipe.id}`)}
              rating={recipe.rating}
            />
          ))}
        </div>
      </section>
    </MobileScreen>
  )
}
