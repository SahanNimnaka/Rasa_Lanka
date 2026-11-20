import type {
  AppLanguage,
  AppSettings,
  AppState,
  CookingPreferences,
  CuisineType,
  DemoProfile,
  DietaryPreference,
  HistoryEntry,
  SearchFilterState,
  SpiceLevel,
} from '../types/appState'

export const APP_STATE_STORAGE_KEY = 'rasalanka.app-state.v1'
export const APP_VERSION = '1.0.0'
export const DEMO_PROFILE_NAME = 'Sahan Nimnaka'

export const DEFAULT_PREFERENCES: CookingPreferences = {
  dietary: 'none',
  spice: 'medium',
  cuisine: 'Sri Lankan',
}

export const DEFAULT_SETTINGS: AppSettings = {
  notificationsEnabled: true,
  darkMode: false,
  language: 'English',
}

export const DEFAULT_SEARCH_FILTERS: SearchFilterState = {
  query: '',
  cuisine: 'Sri Lankan',
  cookingTime: '15-30',
  ingredients: ['Red lentils', 'Coconut', 'Onion'],
}

export const EMPTY_SEARCH_FILTERS: SearchFilterState = {
  query: '',
  cuisine: 'all',
  cookingTime: 'all',
  ingredients: [],
}

const DIETARY_VALUES: DietaryPreference[] = ['none', 'vegetarian', 'vegan']
const SPICE_VALUES: SpiceLevel[] = ['mild', 'medium', 'spicy']
const CUISINE_VALUES: CuisineType[] = ['Sri Lankan', 'Other Asian']
const LANGUAGE_VALUES: AppLanguage[] = ['English', 'Sinhala', 'Tamil']

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isOneOf<T extends string>(value: unknown, values: T[]): value is T {
  return typeof value === 'string' && values.includes(value as T)
}

function atLocalTime(daysAgo: number, hour: number, minute: number): string {
  const date = new Date()
  date.setDate(date.getDate() - daysAgo)
  date.setHours(hour, minute, 0, 0)
  return date.toISOString()
}

/** Local demo history matching the approved wireframe entries. */
export function createDemoHistory(): HistoryEntry[] {
  return [
    {
      id: 'demo-history-dhal',
      recipeId: 'dhal-curry',
      viewedAt: atLocalTime(0, 10, 30),
    },
    {
      id: 'demo-history-sambol',
      recipeId: 'pol-sambol',
      viewedAt: atLocalTime(1, 18, 15),
    },
    {
      id: 'demo-history-roti',
      recipeId: 'coconut-roti',
      viewedAt: atLocalTime(1, 9, 0),
    },
  ]
}

export function createDefaultAppState(): AppState {
  return {
    profile: { fullName: DEMO_PROFILE_NAME },
    preferences: { ...DEFAULT_PREFERENCES },
    settings: { ...DEFAULT_SETTINGS },
    history: createDemoHistory(),
    searchFilters: {
      ...DEFAULT_SEARCH_FILTERS,
      ingredients: [...DEFAULT_SEARCH_FILTERS.ingredients],
    },
  }
}

function parseProfile(value: unknown): DemoProfile | null {
  if (!isRecord(value) || typeof value.fullName !== 'string') {
    return null
  }
  const fullName = value.fullName.trim()
  if (fullName.length < 2 || fullName.length > 80) {
    return null
  }
  return { fullName }
}

function parsePreferences(value: unknown): CookingPreferences | null {
  if (!isRecord(value)) {
    return null
  }
  if (
    !isOneOf(value.dietary, DIETARY_VALUES) ||
    !isOneOf(value.spice, SPICE_VALUES) ||
    !isOneOf(value.cuisine, CUISINE_VALUES)
  ) {
    return null
  }
  return {
    dietary: value.dietary,
    spice: value.spice,
    cuisine: value.cuisine,
  }
}

function parseSettings(value: unknown): AppSettings | null {
  if (!isRecord(value)) {
    return null
  }
  if (
    typeof value.notificationsEnabled !== 'boolean' ||
    typeof value.darkMode !== 'boolean' ||
    !isOneOf(value.language, LANGUAGE_VALUES)
  ) {
    return null
  }
  return {
    notificationsEnabled: value.notificationsEnabled,
    darkMode: value.darkMode,
    language: value.language,
  }
}

function parseHistory(value: unknown): HistoryEntry[] | null {
  if (!Array.isArray(value)) {
    return null
  }

  return value.reduce<HistoryEntry[]>((entries, item) => {
    if (
      isRecord(item) &&
      typeof item.id === 'string' &&
      typeof item.recipeId === 'string' &&
      typeof item.viewedAt === 'string' &&
      !Number.isNaN(Date.parse(item.viewedAt))
    ) {
      entries.push({
        id: item.id,
        recipeId: item.recipeId,
        viewedAt: item.viewedAt,
      })
    }
    return entries
  }, [])
}

function parseSearchFilters(value: unknown): SearchFilterState | null {
  if (!isRecord(value)) {
    return null
  }
  if (
    typeof value.query !== 'string' ||
    (value.cuisine !== 'all' && !isOneOf(value.cuisine, CUISINE_VALUES)) ||
    !isOneOf(value.cookingTime, ['all', 'under-15', '15-30', '30-plus']) ||
    !Array.isArray(value.ingredients) ||
    !value.ingredients.every((item) => typeof item === 'string')
  ) {
    return null
  }

  return {
    query: value.query,
    cuisine: value.cuisine as 'all' | CuisineType,
    cookingTime: value.cookingTime as SearchFilterState['cookingTime'],
    ingredients: value.ingredients as string[],
  }
}

export function loadAppState(): AppState {
  const defaults = createDefaultAppState()
  if (typeof window === 'undefined') {
    return defaults
  }

  try {
    const raw = window.localStorage.getItem(APP_STATE_STORAGE_KEY)
    if (!raw) {
      return defaults
    }

    const parsed: unknown = JSON.parse(raw)
    if (!isRecord(parsed)) {
      return defaults
    }

    return {
      profile: parseProfile(parsed.profile) ?? defaults.profile,
      preferences: parsePreferences(parsed.preferences) ?? defaults.preferences,
      settings: parseSettings(parsed.settings) ?? defaults.settings,
      history: parseHistory(parsed.history) ?? defaults.history,
      searchFilters:
        parseSearchFilters(parsed.searchFilters) ?? defaults.searchFilters,
    }
  } catch {
    return defaults
  }
}

export function saveAppState(state: AppState): void {
  if (typeof window === 'undefined') {
    return
  }
  try {
    window.localStorage.setItem(APP_STATE_STORAGE_KEY, JSON.stringify(state))
  } catch {
    // Private browsing or storage quota errors must never break the UI.
  }
}

function localDateKey(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/** Formats local history timestamps as the approved wireframe labels. */
export function formatViewedAt(isoDate: string): string {
  const date = new Date(isoDate)
  if (Number.isNaN(date.getTime())) {
    return 'Recently viewed'
  }

  const time = new Intl.DateTimeFormat('en-US', {
    hour: 'numeric',
    minute: '2-digit',
  }).format(date)
  const today = localDateKey(new Date())
  const yesterdayDate = new Date()
  yesterdayDate.setDate(yesterdayDate.getDate() - 1)

  if (localDateKey(date) === today) {
    return `Today, ${time}`
  }
  if (localDateKey(date) === localDateKey(yesterdayDate)) {
    return `Yesterday, ${time}`
  }

  const dateLabel = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
  }).format(date)
  return `${dateLabel}, ${time}`
}
