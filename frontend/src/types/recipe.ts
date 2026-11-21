export type RecipeTab = 'ingredients' | 'instructions' | 'video'

export type RecipeSortOption = 'best-match' | 'rating' | 'prep-time' | 'name'

export type RecipeCategory = 'curry' | 'sambol' | 'other'

export type CuisineType = 'Sri Lankan' | 'Other Asian'

export interface RecipeIngredient {
  id: string
  name: string
  quantity: string
  unit: string
  available: boolean
  image?: string
}

export interface RecipeStep {
  id: string
  number: number
  title: string
  description: string
  image: string
  imageAlt: string
}

export interface Recipe {
  id: string
  name: string
  image: string
  imageAlt: string
  category: RecipeCategory
  cuisine: CuisineType
  rating: number
  durationMinutes: number
  matchPercent: number
  servings: number
  description: string
  availableIngredients: RecipeIngredient[]
  missingIngredients: RecipeIngredient[]
  instructions: RecipeStep[]
}
