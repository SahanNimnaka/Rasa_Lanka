import { useState } from 'react'
import { Clock, Heart } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { BottomNavigation } from '../../components/navigation/BottomNavigation'
import { Button } from '../../components/ui/Button'
import { ChoiceChip } from '../../components/ui/ChoiceChip'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import { WireframeImagePlaceholder } from '../../components/ui/WireframeImagePlaceholder'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { useSavedRecipes, useUnsaveRecipe } from '../../hooks/queries'
import type { SavedRecipeViewModel } from '../../services/mappers/savedRecipeMappers'
import { parseApiId } from '../../services/queries/queryKeys'

type SavedFilter = 'all' | 'curry' | 'sambol' | 'other'

const FILTERS: Array<{ id: SavedFilter; label: string }> = [
  { id: 'all', label: 'All' },
  { id: 'curry', label: 'Curries' },
  { id: 'sambol', label: 'Sambols' },
  { id: 'other', label: 'Others' },
]

function categoryLabel(item: SavedRecipeViewModel): string {
  return (
    item.recipe.recipeCategory?.name?.trim() ||
    item.recipe.recipeType?.trim() ||
    'Recipe'
  )
}

function itemFilter(item: SavedRecipeViewModel): Exclude<SavedFilter, 'all'> {
  const label = `${categoryLabel(item)} ${item.recipe.recipeType ?? ''}`.toLowerCase()
  if (label.includes('curry')) {
    return 'curry'
  }
  if (label.includes('sambol')) {
    return 'sambol'
  }
  return 'other'
}

interface SavedRecipeRowProps {
  item: SavedRecipeViewModel
  onRemove: () => void
  onSelect: () => void
}

function SavedRecipeRow({ item, onRemove, onSelect }: SavedRecipeRowProps) {
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
            {item.recipe.name}
          </span>
          <span className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
            <span className="truncate">{categoryLabel(item)}</span>
            <span className="flex items-center gap-1">
              <Clock aria-hidden="true" size={14} />
              {item.recipe.durationMinutes === null
                ? 'Time not provided'
                : `${item.recipe.durationMinutes} min`}
            </span>
          </span>
        </span>
      </button>
      <button
        aria-label={`Remove ${item.recipe.name} from favourites`}
        className="flex shrink-0 items-center gap-1 rounded-full bg-chilli-red/10 px-2.5 py-2 text-xs font-bold text-chilli-red"
        onClick={onRemove}
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
  const { mutateAsync: unsaveRecipe } = useUnsaveRecipe()
  const [filter, setFilter] = useState<SavedFilter>('all')
  const [status, setStatus] = useState('')

  const savedQuery = useSavedRecipes()
  const items = savedQuery.data?.pages.flatMap((page) => page.data) ?? []

  const visibleItems =
    filter === 'all'
      ? items
      : items.filter((item) => itemFilter(item) === filter)

  async function handleRemove(item: SavedRecipeViewModel) {
    const recipeId = parseApiId(item.recipeId)
    if (recipeId === null) {
      return
    }

    try {
      await unsaveRecipe(recipeId)
      if (flow.favoriteRecipeIds.includes(item.recipeId)) {
        flow.toggleFavorite(item.recipeId)
      }
      setStatus(`${item.recipe.name} removed from saved recipes.`)
    } catch {
      setStatus(`Could not remove ${item.recipe.name}. Please try again.`)
    }
  }

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

      {savedQuery.isPending && items.length === 0 ? (
        <div className="mt-8">
          <LoadingIndicator label="Loading saved recipes..." />
        </div>
      ) : savedQuery.isError && items.length === 0 ? (
        <div className="glass-panel mt-8 rounded-2xl p-6 text-center">
          <h2 className="text-base font-bold">Saved recipes could not load</h2>
          <p className="mt-2 text-sm leading-6 text-muted">
            {savedQuery.error.message}
          </p>
          <Button
            className="mt-5"
            onClick={() => void savedQuery.refetch()}
            size="sm"
          >
            Try again
          </Button>
        </div>
      ) : visibleItems.length === 0 ? (
        <div className="glass-panel mt-8 rounded-2xl p-6 text-center">
          <h2 className="text-base font-bold">
            {items.length === 0
              ? 'No saved recipes yet'
              : 'No saved recipes in this category'}
          </h2>
          <p className="mt-2 text-sm leading-6 text-muted">
            {items.length === 0
              ? 'Tap the heart on a recipe to save it here.'
              : 'Choose another category to see your saved recipes.'}
          </p>
          <Button
            className="mt-5"
            onClick={() =>
              items.length === 0 ? navigate('/search') : setFilter('all')
            }
            size="sm"
          >
            {items.length === 0 ? 'Browse recipes' : 'Show all saved'}
          </Button>
        </div>
      ) : (
        <div className="mt-4 grid md:grid-cols-2 md:gap-4 lg:grid-cols-3">
          {visibleItems.map((item) => {
            const recipeId = parseApiId(item.recipeId)
            return (
              <SavedRecipeRow
                item={item}
                key={item.id}
                onRemove={() => void handleRemove(item)}
                onSelect={() => {
                  if (recipeId !== null) {
                    navigate(`/recipes/${recipeId}`)
                  }
                }}
              />
            )
          })}
        </div>
      )}

      {savedQuery.hasNextPage && (
        <div className="mt-6 flex justify-center">
          <Button
            disabled={savedQuery.isFetchingNextPage}
            onClick={() => void savedQuery.fetchNextPage()}
            size="sm"
            variant="secondary"
          >
            {savedQuery.isFetchingNextPage ? 'Loading more...' : 'Load more'}
          </Button>
        </div>
      )}

      <p aria-live="polite" className="sr-only">
        {status}
      </p>

      <BottomNavigation />
    </MobileScreen>
  )
}
