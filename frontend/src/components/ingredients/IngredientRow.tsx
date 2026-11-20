import { Image as ImageIcon, Trash } from 'lucide-react'
import type { SelectedIngredient } from '../../types/ingredients'
import { Button } from '../ui/Button'

export interface IngredientRowProps {
  ingredient: SelectedIngredient
  selected: boolean
  onToggle: (id: string, selected: boolean) => void
  onRemove?: (id: string) => void
  confidenceLabel?: string
}

export function IngredientRow({
  confidenceLabel = 'Image confidence',
  ingredient,
  onRemove,
  onToggle,
  selected,
}: IngredientRowProps) {
  const isManual = ingredient.source === 'manual'

  return (
    <div className="flex items-center gap-2 rounded-card border border-forest-green/15 bg-white p-2">
      <div className="flex min-w-0 flex-1 items-center gap-3">
        <span className="flex h-[50px] w-[50px] shrink-0 items-center justify-center rounded-lg bg-warm-cream text-forest-green/60">
          <ImageIcon aria-hidden="true" size={22} />
        </span>
        <span className="min-w-0">
          <span className="block truncate text-sm font-semibold text-forest-green">
            {ingredient.name}
          </span>
          <span className="block text-xs text-muted">
            {isManual
              ? 'Manually added'
              : `${confidenceLabel}: ${ingredient.confidence ?? 0}%`}
          </span>
        </span>
      </div>

      <input
        aria-label={`Select ${ingredient.name}`}
        checked={selected}
        className="h-5 w-5 shrink-0 accent-fresh-green"
        onChange={(event) => onToggle(ingredient.id, event.target.checked)}
        type="checkbox"
      />

      {onRemove && (
        <Button
          aria-label={`Remove ${ingredient.name}`}
          className="h-8 w-8 shrink-0 rounded-full p-0 text-chilli-red"
          onClick={() => onRemove(ingredient.id)}
          size="sm"
          variant="ghost"
        >
          <Trash aria-hidden="true" size={16} />
        </Button>
      )}
    </div>
  )
}
