import { useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { IngredientQuantityCard } from '../../components/ingredients/IngredientQuantityCard'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  validateQuantity,
  withDefaultQuantity,
} from '../../services/quantityService'
import type {
  IngredientQuantitiesState,
  IngredientWithQuantity,
  MeasurementUnit,
} from '../../types/ingredients'

export function IngredientQuantitiesScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const goBack = useNavigateBack('/ingredients/recognition')
  const flow = useCookingFlow()

  const routeIngredients =
    (location.state as IngredientQuantitiesState | null)?.ingredients ?? []

  const [draftIngredients, setDraftIngredients] = useState<
    IngredientWithQuantity[]
  >(() =>
    flow.ingredients.length
      ? flow.ingredients
      : routeIngredients.map(withDefaultQuantity),
  )

  const errors = draftIngredients.reduce<Record<string, string>>(
    (result, ingredient) => {
      const error = validateQuantity(ingredient.quantity)
      if (error) {
        result[ingredient.id] = error
      }
      return result
    },
    {},
  )
  const hasErrors = Object.keys(errors).length > 0

  function handleUpdate(
    id: string,
    update: { quantity?: string; unit?: MeasurementUnit },
  ) {
    setDraftIngredients((current) =>
      current.map((ingredient) =>
        ingredient.id === id ? { ...ingredient, ...update } : ingredient,
      ),
    )
    flow.updateIngredientQuantity(id, update)
  }

  function handleNext() {
    if (hasErrors || draftIngredients.length === 0) {
      return
    }

    flow.setIngredients(draftIngredients)
    navigate('/ingredients/serving-size', {
      state: { ingredients: draftIngredients },
    })
  }

  return (
    <MobileScreen aria-labelledby="quantities-title">
      <ScreenHeader onBack={goBack} />

      <section className="mt-3">
        <p className="eyebrow-label">Fine-tune your ingredients</p>
        <h1 className="mt-1 text-[1.7rem] font-extrabold" id="quantities-title">
          Enter Ingredient Quantities
        </h1>
        <h2 className="mt-8 text-lg font-bold text-ink">
          How much do you have?
        </h2>
        <p className="mt-1 text-sm text-muted">
          Enter the amount for each ingredient.
        </p>

        {draftIngredients.length === 0 ? (
          <p className="mt-8 rounded-card border border-dashed border-forest-green/20 p-4 text-sm text-muted">
            No ingredients were selected. Go back to choose ingredients first.
          </p>
        ) : (
          <div className="mt-5 grid gap-3 md:grid-cols-2 md:gap-5">
            {draftIngredients.map((ingredient) => (
              <IngredientQuantityCard
                error={errors[ingredient.id]}
                ingredient={ingredient}
                key={ingredient.id}
                onQuantityChange={(quantity) =>
                  handleUpdate(ingredient.id, { quantity })
                }
                onUnitChange={(unit) => handleUpdate(ingredient.id, { unit })}
              />
            ))}
          </div>
        )}

        <Button
          className="mt-6 h-[46px] w-full"
          disabled={hasErrors || draftIngredients.length === 0}
          onClick={handleNext}
          size="lg"
        >
          Next
        </Button>
      </section>
    </MobileScreen>
  )
}
