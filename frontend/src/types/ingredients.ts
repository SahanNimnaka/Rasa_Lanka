export type IngredientSource = 'demo' | 'manual'

export interface SelectedIngredient {
  id: string
  name: string
  confidence?: number
  source: IngredientSource
}

export const MEASUREMENT_UNITS = [
  'g',
  'kg',
  'ml',
  'l',
  'cup',
  'tsp',
  'tbsp',
  'piece',
  'sprig',
  'pinch',
] as const

export type MeasurementUnit = (typeof MEASUREMENT_UNITS)[number]

export interface IngredientQuantity {
  quantity: string
  unit: MeasurementUnit
}

export interface IngredientWithQuantity
  extends SelectedIngredient, IngredientQuantity {
  image?: string
}

export interface ImagePreviewData {
  dataUrl: string
  name?: string
  size?: number
  type?: string
  source: 'camera' | 'gallery' | 'upload' | 'recent'
}

export interface IngredientRecognitionState {
  image?: ImagePreviewData
  initialIngredients?: SelectedIngredient[]
}

export interface IngredientQuantitiesState {
  ingredients: Array<SelectedIngredient | IngredientWithQuantity>
}
