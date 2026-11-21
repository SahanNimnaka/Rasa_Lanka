export const DEFAULT_SERVINGS = 2
export const MIN_SERVINGS = 1
export const MAX_SERVINGS = 20

export function clampServings(value: number): number {
  if (!Number.isFinite(value)) {
    return DEFAULT_SERVINGS
  }

  return Math.max(MIN_SERVINGS, Math.min(MAX_SERVINGS, Math.floor(value)))
}
