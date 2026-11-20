import { createContext, use } from 'react'
import type {
  AppSettings,
  AppState,
  CookingPreferences,
  DemoProfile,
  SearchFilterState,
} from '../types/appState'

export interface AppStateActions {
  updateProfile: (profile: DemoProfile) => void
  updatePreferences: (preferences: CookingPreferences) => void
  updateSettings: (update: Partial<AppSettings>) => void
  recordRecipeView: (recipeId: string) => void
  clearHistory: () => void
  setSearchFilters: (filters: SearchFilterState) => void
}

export type AppStateContextValue = AppState & AppStateActions

export const AppStateContext = createContext<AppStateContextValue | null>(null)

export function useAppState(): AppStateContextValue {
  const context = use(AppStateContext)
  if (!context) {
    throw new Error('useAppState must be used inside AppStateProvider')
  }
  return context
}
