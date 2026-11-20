import type { CuisineType } from './recipe'

export type { CuisineType } from './recipe'

export type DietaryPreference = 'none' | 'vegetarian' | 'vegan'

export type SpiceLevel = 'mild' | 'medium' | 'spicy'

export type AppLanguage = 'English' | 'Sinhala' | 'Tamil'

export interface CookingPreferences {
  dietary: DietaryPreference
  spice: SpiceLevel
  cuisine: CuisineType
}

export interface AppSettings {
  notificationsEnabled: boolean
  darkMode: boolean
  language: AppLanguage
}

/** Demonstration profile only. Authentication is not connected. */
export interface DemoProfile {
  fullName: string
}

export interface HistoryEntry {
  id: string
  recipeId: string
  viewedAt: string
}

export interface SearchFilterState {
  query: string
  cuisine: 'all' | CuisineType
  cookingTime: 'all' | 'under-15' | '15-30' | '30-plus'
  ingredients: string[]
}

export interface AppState {
  profile: DemoProfile
  preferences: CookingPreferences
  settings: AppSettings
  history: HistoryEntry[]
  searchFilters: SearchFilterState
}
