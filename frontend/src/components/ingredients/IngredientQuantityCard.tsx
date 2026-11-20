import { ImageIcon } from 'lucide-react'
import type {
  IngredientWithQuantity,
  MeasurementUnit,
} from '../../types/ingredients'
import { Card } from '../ui/Card'
import { QuantityInput } from './QuantityInput'

export interface IngredientQuantityCardProps {
  ingredient: IngredientWithQuantity
  error?: string
  onQuantityChange: (value: string) => void
  onUnitChange: (unit: MeasurementUnit) => void
}

export function IngredientQuantityCard({
  error,
  ingredient,
  onQuantityChange,
  onUnitChange,
}: IngredientQuantityCardProps) {
  return (
    <Card className="flex min-w-0 items-stretch gap-3 p-3">
      <div className="flex h-[76px] w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-warm-cream">
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
        <h3 className="text-sm font-bold text-forest-green">
          {ingredient.name}
        </h3>
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
