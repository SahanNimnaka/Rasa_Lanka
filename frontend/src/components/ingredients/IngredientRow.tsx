import type { ReactNode } from 'react'
import { Image as ImageIcon, Trash } from 'lucide-react'
import type { SelectedIngredient } from '../../types/ingredients'
import { Button } from '../ui/Button'

export interface IngredientRowProps {
  ingredient: SelectedIngredient
  selected: boolean
  onToggle: (id: string, selected: boolean) => void
  onRemove?: (id: string) => void
  confidenceLabel?: string
  sourceLabel?: string
  footer?: ReactNode
  action?: ReactNode
}

export function IngredientRow({
  action,
  confidenceLabel = 'Image confidence',
  footer,
  ingredient,
  onRemove,
  onToggle,
  selected,
  sourceLabel,
}: IngredientRowProps) {
  const defaultSourceLabel =
    ingredient.source === 'demo'
      ? 'Demo detection — not a catalogue item'
      : ingredient.source === 'catalogue'
        ? 'Laravel catalogue ingredient'
        : 'Manually added (legacy)'
  const detail =
    ingredient.source === 'demo' && ingredient.confidence !== undefined
      ? `${confidenceLabel}: ${ingredient.confidence}%`
      : (sourceLabel ?? defaultSourceLabel)

  return (
    <div className="glass-panel flex items-center gap-2 rounded-2xl p-2">
      <div className="flex min-w-0 flex-1 items-center gap-3">
        <span className="flex h-[50px] w-[50px] shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-warm-cream to-warm-cream/40 text-fresh-green">
          <ImageIcon aria-hidden="true" size={22} />
        </span>
        <span className="min-w-0">
          <span className="block truncate text-sm font-semibold text-ink">
            {ingredient.name}
          </span>
          <span className="block text-xs text-muted">{detail}</span>
          {footer && <span className="mt-1 block text-xs text-muted">{footer}</span>}
        </span>
      </div>

      <input
        aria-label={`Select ${ingredient.name}`}
        checked={selected}
        className="h-5 w-5 shrink-0 accent-fresh-green"
        onChange={(event) => onToggle(ingredient.id, event.target.checked)}
        type="checkbox"
      />

      {action}

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
