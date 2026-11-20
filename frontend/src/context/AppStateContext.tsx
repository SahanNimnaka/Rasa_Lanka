import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { loadAppState, saveAppState } from '../services/appStateService'
import {
  AppStateContext,
  type AppStateActions,
  type AppStateContextValue,
} from './appStateStore'

export interface AppStateProviderProps {
  children: ReactNode
}

function createHistoryId(recipeId: string): string {
  const random =
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : Math.random().toString(36).slice(2)
  return `history-${recipeId}-${random}`
}

export function AppStateProvider({ children }: AppStateProviderProps) {
  const [state, setState] = useState(loadAppState)

  useEffect(() => {
    saveAppState(state)
  }, [state])

  useEffect(() => {
    const root = document.documentElement
    root.dataset.theme = state.settings.darkMode ? 'dark' : 'light'
    root.classList.toggle('theme-dark', state.settings.darkMode)

    return () => {
      delete root.dataset.theme
      root.classList.remove('theme-dark')
    }
  }, [state.settings.darkMode])

  const updateProfile: AppStateActions['updateProfile'] = useCallback(
    (profile) => {
      setState((current) => ({
        ...current,
        profile: { fullName: profile.fullName.trim() },
      }))
    },
    [],
  )

  const updatePreferences: AppStateActions['updatePreferences'] = useCallback(
    (preferences) => {
      setState((current) => ({ ...current, preferences: { ...preferences } }))
    },
    [],
  )

  const updateSettings: AppStateActions['updateSettings'] = useCallback(
    (update) => {
      setState((current) => ({
        ...current,
        settings: { ...current.settings, ...update },
      }))
    },
    [],
  )

  const recordRecipeView: AppStateActions['recordRecipeView'] = useCallback(
    (recipeId) => {
      setState((current) => ({
        ...current,
        history: [
          {
            id: createHistoryId(recipeId),
            recipeId,
            viewedAt: new Date().toISOString(),
          },
          ...current.history.filter((entry) => entry.recipeId !== recipeId),
        ].slice(0, 20),
      }))
    },
    [],
  )

  const clearHistory: AppStateActions['clearHistory'] = useCallback(() => {
    setState((current) => ({ ...current, history: [] }))
  }, [])

  const setSearchFilters: AppStateActions['setSearchFilters'] = useCallback(
    (filters) => {
      setState((current) => ({
        ...current,
        searchFilters: {
          ...filters,
          ingredients: [...filters.ingredients],
        },
      }))
    },
    [],
  )

  const value = useMemo<AppStateContextValue>(
    () => ({
      ...state,
      clearHistory,
      recordRecipeView,
      setSearchFilters,
      updatePreferences,
      updateProfile,
      updateSettings,
    }),
    [
      clearHistory,
      recordRecipeView,
      setSearchFilters,
      state,
      updatePreferences,
      updateProfile,
      updateSettings,
    ],
  )

  return <AppStateContext value={value}>{children}</AppStateContext>
}
