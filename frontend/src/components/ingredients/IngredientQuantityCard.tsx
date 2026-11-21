import { ImageIcon } from 'lucide-react'
import type { IngredientWithQuantity } from '../../types/ingredients'
import { Card } from '../ui/Card'
import { QuantityInput } from './QuantityInput'

export interface IngredientQuantityCardProps {
  ingredient: IngredientWithQuantity
  error?: string
  onQuantityChange: (value: string) => void
  onUnitChange: (unit: string) => void
}

export function IngredientQuantityCard({
  error,
  ingredient,
  onQuantityChange,
  onUnitChange,
}: IngredientQuantityCardProps) {
  return (
    <Card className="flex min-w-0 items-stretch gap-3 p-3">
      <div className="flex h-[76px] w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-warm-cream to-warm-cream/40">
        {ingredient.image ? (
          <img
            alt={ingredient.name}
            className="h-full w-full object-cover"
            height={76}
            loading="lazy"
            src={ingredient.image}
            width={64}
          />
        ) : (
          <ImageIcon
            aria-hidden="true"
            className="text-fresh-green/70"
            size={26}
          />
        )}
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="text-sm font-bold text-ink">{ingredient.name}</h3>
          {typeof ingredient.isRequired === 'boolean' && (
            <span className="rounded-full bg-forest-green/10 px-2 py-0.5 text-[10px] font-bold text-ink">
              {ingredient.isRequired ? 'Required' : 'Optional'}
            </span>
          )}
        </div>
        <p className="mt-1 text-[11px] text-muted">
          Catalogue ingredient #{ingredient.id}
        </p>
        {ingredient.preparation && (
          <p className="mt-1 text-xs text-muted">
            Preparation: {ingredient.preparation}
          </p>
        )}
        <div className="mt-2">
          <QuantityInput
            error={error}
            label={ingredient.name}
            onUnitChange={onUnitChange}
            onValueChange={onQuantityChange}
            unit={ingredient.unit}
            value={ingredient.quantity}
          />
        </div>
      </div>
    </Card>
  )
}
