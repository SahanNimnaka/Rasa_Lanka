import { useState } from 'react'
import { Clock, Heart, Star } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { BottomNavigation } from '../../components/navigation/BottomNavigation'
import { Button } from '../../components/ui/Button'
import { ChoiceChip } from '../../components/ui/ChoiceChip'
import { WireframeImagePlaceholder } from '../../components/ui/WireframeImagePlaceholder'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { getRecipeById } from '../../services/mockRecipeService'
import type { Recipe, RecipeCategory } from '../../types/recipe'

type SavedFilter = 'all' | RecipeCategory

const FILTERS: Array<{ id: SavedFilter; label: string }> = [
  { id: 'all', label: 'All' },
  { id: 'curry', label: 'Curries' },
  { id: 'sambol', label: 'Sambols' },
  { id: 'other', label: 'Others' },
]

interface SavedRecipeRowProps {
  onSelect: () => void
  onToggleFavorite: () => void
  recipe: Recipe
}

function SavedRecipeRow({
  onSelect,
  onToggleFavorite,
  recipe,
}: SavedRecipeRowProps) {
  return (
    <div className="glass-panel mb-3 flex items-center gap-3 rounded-2xl p-2.5 md:mb-0">
      <button
        className="flex min-w-0 flex-1 items-center gap-4 text-left"
        onClick={onSelect}
        type="button"
      >
        <WireframeImagePlaceholder className="h-[92px] w-[92px]" />
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-bold text-ink">
            {recipe.name}
          </span>
          <span className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
            <span className="flex items-center gap-1">
              <Star
                aria-hidden="true"
                className="fill-spice-orange text-spice-orange"
                size={14}
              />
              {recipe.rating}
            </span>
            <span className="flex items-center gap-1">
              <Clock aria-hidden="true" size={14} />
              {recipe.durationMinutes} min
            </span>
          </span>
        </span>
      </button>
      <button
        aria-label={`Remove ${recipe.name} from favourites`}
        className="flex shrink-0 items-center gap-1 rounded-full bg-chilli-red/10 px-2.5 py-2 text-xs font-bold text-chilli-red"
        onClick={onToggleFavorite}
        type="button"
      >
        <Heart
          aria-hidden="true"
          className="fill-chilli-red text-chilli-red"
          size={17}
        />
        Saved
      </button>
    </div>
  )
}

export function SavedRecipesScreen() {
  const navigate = useNavigate()
  const goBack = useNavigateBack('/home')
  const flow = useCookingFlow()
  const [filter, setFilter] = useState<SavedFilter>('all')

  const savedRecipes = flow.favoriteRecipeIds.reduce<Recipe[]>(
    (recipes, recipeId) => {
      const recipe = getRecipeById(recipeId)
      if (recipe) {
        recipes.push(recipe)
      }
      return recipes
    },
    [],
  )

  const visibleRecipes =
    filter === 'all'
      ? savedRecipes
      : savedRecipes.filter((recipe) => recipe.category === filter)

  return (
    <MobileScreen aria-labelledby="saved-title" className="pb-28">
      <ScreenHeader onBack={goBack} />

      <p className="eyebrow-label mt-2">Your collection</p>
      <h1
        className="mt-1 text-[28px] leading-tight font-extrabold"
        id="saved-title"
      >
        Saved Recipes
      </h1>

      <div
        aria-label="Saved recipe categories"
        className="glass-panel mt-6 grid grid-cols-4 gap-2 rounded-2xl p-2"
        role="group"
      >
        {FILTERS.map((item) => (
          <ChoiceChip
            key={item.id}
            label={item.label}
            onClick={() => setFilter(item.id)}
            selected={filter === item.id}
          />
        ))}
      </div>

      {visibleRecipes.length === 0 ? (
        <div className="glass-panel mt-8 rounded-2xl p-6 text-center">
          <h2 className="text-base font-bold">
            {savedRecipes.length === 0
              ? 'No saved recipes yet'
              : 'No saved recipes in this category'}
          </h2>
          <p className="mt-2 text-sm leading-6 text-muted">
            {savedRecipes.length === 0
              ? 'Tap the heart on a recipe to save it here.'
              : 'Choose another category to see your saved recipes.'}
          </p>
          <Button
            className="mt-5"
            onClick={() =>
              savedRecipes.length === 0 ? navigate('/search') : setFilter('all')
            }
            size="sm"
          >
            {savedRecipes.length === 0 ? 'Browse recipes' : 'Show all saved'}
          </Button>
        </div>
      ) : (
        <div className="mt-4 grid md:grid-cols-2 md:gap-4 lg:grid-cols-3">
          {visibleRecipes.map((recipe) => (
            <SavedRecipeRow
              key={recipe.id}
              onSelect={() => navigate(`/recipes/${recipe.id}`)}
              onToggleFavorite={() => flow.toggleFavorite(recipe.id)}
              recipe={recipe}
            />
          ))}
        </div>
      )}

      <BottomNavigation />
    </MobileScreen>
  )
}
