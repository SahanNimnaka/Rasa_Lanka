export type IngredientSource = 'demo' | 'catalogue' | 'manual'

export interface SelectedIngredient {
  id: string
  name: string
  confidence?: number
  source: IngredientSource
  /** Set when a demo detection has been corrected to a catalogue ingredient. */
  mappedFromDemoId?: string
  preparation?: string | null
  isRequired?: boolean | null
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
  unit: string
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
