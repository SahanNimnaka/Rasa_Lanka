import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { BottomNavigation } from '../../components/navigation/BottomNavigation'
import { RecipeCard } from '../../components/recipes/RecipeCard'
import { Button } from '../../components/ui/Button'
import { ChoiceChip } from '../../components/ui/ChoiceChip'
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

      <h1 className="mt-2 text-2xl font-bold" id="saved-title">
        Saved Recipes
      </h1>

      <div
        aria-label="Saved recipe categories"
        className="mt-6 grid grid-cols-4 gap-2"
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

      <p className="mt-4 text-xs text-muted">
        Favourites are stored for this browser session.
      </p>

      {visibleRecipes.length === 0 ? (
        <div className="mt-10 rounded-card border border-dashed border-forest-green/20 p-6 text-center">
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
        <div className="mt-5 grid gap-4">
          {visibleRecipes.map((recipe) => (
            <RecipeCard
              duration={`${recipe.durationMinutes} min`}
              image={recipe.image}
              isFavorite
              key={recipe.id}
              name={recipe.name}
              onSelect={() => navigate(`/recipes/${recipe.id}`)}
              onToggleFavorite={() => flow.toggleFavorite(recipe.id)}
              rating={recipe.rating}
              showSavedIndicator
            />
          ))}
        </div>
      )}

      <BottomNavigation />
    </MobileScreen>
  )
}
