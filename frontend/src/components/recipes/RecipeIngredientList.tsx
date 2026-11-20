import { AlertCircle, Check } from 'lucide-react'
import { formatQuantity } from '../../services/quantityService'
import type { RecipeIngredient } from '../../types/recipe'

export interface RecipeIngredientListProps {
  availableIngredients: RecipeIngredient[]
  missingIngredients: RecipeIngredient[]
}

export function RecipeIngredientList({
  availableIngredients,
  missingIngredients,
}: RecipeIngredientListProps) {
  return (
    <div className="grid gap-6">
      <section aria-labelledby="available-ingredients-title">
        <h3 className="text-base font-bold" id="available-ingredients-title">
          Available Ingredients
        </h3>
        <ul className="mt-3 grid gap-3">
          {availableIngredients.map((ingredient) => (
            <li className="flex items-center gap-3" key={ingredient.id}>
              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-fresh-green/15 text-fresh-green">
                <Check aria-hidden="true" size={14} />
              </span>
              <span className="min-w-0 flex-1 text-sm text-ink">
                {ingredient.name}
              </span>
              <span className="shrink-0 text-sm font-semibold text-ink">
                {formatQuantity(ingredient.quantity, ingredient.unit)}
              </span>
            </li>
          ))}
        </ul>
      </section>

      <section aria-labelledby="missing-ingredients-title">
        <h3 className="text-base font-bold" id="missing-ingredients-title">
          Missing Ingredients
        </h3>
        <ul className="mt-3 grid gap-3">
          {missingIngredients.map((ingredient) => (
            <li className="flex items-center gap-3" key={ingredient.id}>
              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-chilli-red/10 text-chilli-red">
                <AlertCircle aria-hidden="true" size={14} />
              </span>
              <span className="min-w-0 flex-1 text-sm text-ink">
                {ingredient.name}
              </span>
              <span className="shrink-0 text-sm font-semibold text-ink">
                {formatQuantity(ingredient.quantity, ingredient.unit)}
              </span>
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}
