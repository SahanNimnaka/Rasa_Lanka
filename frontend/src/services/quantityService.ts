import {
  MEASUREMENT_UNITS,
  type IngredientWithQuantity,
  type MeasurementUnit,
  type SelectedIngredient,
} from '../types/ingredients'

const DEFAULT_QUANTITIES: Record<string, { quantity: string; unit: string }> = {
  'red lentils': { quantity: '200', unit: 'g' },
  'coconut milk': { quantity: '1', unit: 'cup' },
  onion: { quantity: '1', unit: 'piece' },
  turmeric: { quantity: '0.5', unit: 'tsp' },
  'curry leaves': { quantity: '1', unit: 'sprig' },
}

export function isMeasurementUnit(value: unknown): value is MeasurementUnit {
  return MEASUREMENT_UNITS.includes(value as MeasurementUnit)
}

export function normalizeUnit(value: unknown): string {
  if (typeof value !== 'string') {
    return 'piece'
  }

  const trimmed = value.trim()
  return trimmed || 'piece'
}

export function getDefaultQuantity(name: string): {
  quantity: string
  unit: string
} {
  return (
    DEFAULT_QUANTITIES[name.trim().toLowerCase()] ?? {
      quantity: '1',
      unit: 'piece',
    }
  )
}

export function withDefaultQuantity(
  ingredient: SelectedIngredient | IngredientWithQuantity,
): IngredientWithQuantity {
  if ('quantity' in ingredient && 'unit' in ingredient) {
    return {
      ...ingredient,
      quantity: String(ingredient.quantity),
      unit: normalizeUnit(ingredient.unit),
    }
  }

  return {
    ...ingredient,
    ...getDefaultQuantity(ingredient.name),
  }
}

export function validateQuantity(value: string): string | undefined {
  const trimmed = value.trim()

  if (!trimmed) {
    return 'Enter a quantity'
  }
  if (trimmed.startsWith('-')) {
    return 'Quantity cannot be negative'
  }
  if (!/^\d+(\.\d+)?$/.test(trimmed)) {
    return 'Enter a valid number'
  }
  if (Number(trimmed) <= 0) {
    return 'Quantity must be greater than zero'
  }

  return undefined
}

export function formatQuantity(quantity: string, unit: string): string {
  return `${quantity} ${unit}`.trim()
}
