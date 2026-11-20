import { Check, Info } from 'lucide-react'
import { Card } from '../ui/Card'
import type { RecipeIngredientViewModel } from '../../services/mappers/recipeMappers'

export interface UserQuantity {
  quantity: string
  unit: string
}

export interface ApiRecipeIngredientListProps {
  ingredients: RecipeIngredientViewModel[]
  userQuantities: Map<string, UserQuantity>
}

function normalizeName(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/\([^)]*\)/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

function displayValue(value: string | null): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

function joinValues(values: Array<string | null>): string | null {
  const present = values.filter((value): value is string => value !== null)
  return present.length > 0 ? present.join(' ') : null
}

function sourceQuantity(ingredient: RecipeIngredientViewModel): string | null {
  const quantity = displayValue(ingredient.originalQuantityText)
  const unit = displayValue(ingredient.originalUnit)
  if (quantity && unit && !quantity.toLowerCase().includes(unit.toLowerCase())) {
    return `${quantity} ${unit}`
  }
  return quantity ?? unit
}

function perPersonQuantity(
  ingredient: RecipeIngredientViewModel,
): string | null {
  const min = displayValue(ingredient.perPersonQuantityMin)
  const max = displayValue(ingredient.perPersonQuantityMax)
  const unit = displayValue(ingredient.perPersonUnit)

  const value =
    min && max ? (min === max ? min : `${min}–${max}`) : (min ?? max)

  return joinValues([value, unit])
}

function expertQuantity(
  ingredient: RecipeIngredientViewModel,
): string | null {
  const quantity = displayValue(ingredient.expertApprovedQuantity)
  const unit = displayValue(ingredient.expertApprovedUnit)
  return joinValues([quantity, unit])
}

function requirement(ingredient: RecipeIngredientViewModel): string {
  if (ingredient.isRequired === true) {
    return 'Required'
  }
  if (ingredient.isRequired === false) {
    return 'Optional'
  }
  return 'Requirement not stated'
}

function DetailRow({ label, value }: { label: string; value: string | null }) {
  if (!value) {
    return null
  }

  return (
    <div className="flex flex-wrap items-baseline gap-x-2 text-xs leading-5">
      <span className="font-bold text-ink">{label}:</span>
      <span className="text-muted">{value}</span>
    </div>
  )
}

export function ApiRecipeIngredientList({
  ingredients,
  userQuantities,
}: ApiRecipeIngredientListProps) {
  if (ingredients.length === 0) {
    return (
      <Card className="text-sm text-muted">
        No ingredient rows are provided for this recipe.
      </Card>
    )
  }

  return (
    <div className="grid gap-3">
      {ingredients.map((ingredient) => {
        const name =
          displayValue(ingredient.ingredient?.name ?? null) ??
          displayValue(ingredient.originalIngredientName) ??
          'Unnamed ingredient'
        const originalName = displayValue(ingredient.originalIngredientName)
        const matchedQuantity = userQuantities.get(normalizeName(name))
        const expertValue = expertQuantity(ingredient)
        const approvalStatus =
          displayValue(ingredient.approvalStatus) ??
          'Approval status not provided'
        const estimatedFlags = [
          ingredient.quantityIsDerived ? 'Quantity derived' : null,
          ingredient.quantityDescriptionIsEstimated
            ? 'Estimated quantity description'
            : null,
          ingredient.quantityDependsOnEstimatedYield
            ? 'Derived from estimated yield'
            : null,
        ].filter((flag): flag is string => flag !== null)

        return (
          <Card
            className="rounded-2xl p-4"
            key={`${ingredient.id}-${name}-${sourceQuantity(ingredient) ?? ''}`}
          >
            <div className="flex items-start gap-3">
              <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-fresh-green/15 text-fresh-green">
                {ingredient.isRequired === false ? (
                  <Info aria-hidden="true" size={14} />
                ) : (
                  <Check aria-hidden="true" size={14} />
                )}
              </span>
              <div className="min-w-0 flex-1">
                <h3 className="text-sm font-bold text-ink">{name}</h3>
                <div className="mt-2 grid gap-1">
                  <DetailRow
                    label="Source name"
                    value={
                      originalName && originalName !== name ? originalName : null
                    }
                  />
                  <DetailRow
                    label="Original quantity"
                    value={sourceQuantity(ingredient)}
                  />
                  <DetailRow
                    label="Original unit"
                    value={displayValue(ingredient.originalUnit)}
                  />
                  <DetailRow
                    label="Calculated per person"
                    value={perPersonQuantity(ingredient)}
                  />
                  <DetailRow
                    label="Calculation"
                    value={displayValue(
                      ingredient.perPersonQuantityDescription,
                    )}
                  />
                  {expertValue && (
                    <DetailRow
                      label={
                        ingredient.isExpertApproved
                          ? 'Expert approved'
                          : 'Expert values, not approved'
                      }
                      value={expertValue}
                    />
                  )}
                  <DetailRow label="Approval" value={approvalStatus} />
                  <DetailRow
                    label="Requirement label"
                    value={displayValue(ingredient.originalRequirementLabel)}
                  />
                  <DetailRow label="Status" value={requirement(ingredient)} />
                  <DetailRow
                    label="Preparation"
                    value={displayValue(ingredient.ingredientPreparation)}
                  />
                  <DetailRow
                    label="Notes"
                    value={displayValue(ingredient.ingredientNotes)}
                  />
                  <DetailRow
                    label="Data quality"
                    value={displayValue(ingredient.dataQualityStatus)}
                  />
                  <DetailRow
                    label="Your quantity"
                    value={
                      matchedQuantity
                        ? `${matchedQuantity.quantity} ${matchedQuantity.unit}`.trim()
                        : null
                    }
                  />
                </div>
                {estimatedFlags.length > 0 && (
                  <div
                    aria-label="Quantity uncertainty flags"
                    className="mt-3 flex flex-wrap gap-2"
                  >
                    {estimatedFlags.map((flag) => (
                      <span
                        className="rounded-full bg-spice-orange/12 px-2.5 py-1 text-[10px] font-bold text-spice-orange"
                        key={flag}
                      >
                        {flag}
                      </span>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </Card>
        )
      })}
    </div>
  )
}
