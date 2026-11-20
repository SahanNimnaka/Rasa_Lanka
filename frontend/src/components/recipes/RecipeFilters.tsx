import { useId } from 'react'
import type { RecipeFilterOptions } from '../../services/recommendationService'
import { Button } from '../ui/Button'

export interface RecipeFiltersProps {
  filters: RecipeFilterOptions
  onChange: (filters: RecipeFilterOptions) => void
  onClear: () => void
}

export function RecipeFilters({
  filters,
  onChange,
  onClear,
}: RecipeFiltersProps) {
  const highMatchId = useId()
  const quickId = useId()

  return (
    <section
      aria-label="Recipe filters"
      className="glass-panel mt-3 rounded-2xl p-4"
    >
      <h2 className="text-sm font-bold text-ink">Filter recipes</h2>
      <div className="mt-3 grid gap-3">
        <label
          className="flex items-center gap-2 text-sm text-ink"
          htmlFor={highMatchId}
        >
          <input
            checked={filters.highMatchOnly}
            className="h-4 w-4 accent-fresh-green"
            id={highMatchId}
            onChange={(event) =>
              onChange({ ...filters, highMatchOnly: event.target.checked })
            }
            type="checkbox"
          />
          High match (80% or more)
        </label>
        <label
          className="flex items-center gap-2 text-sm text-ink"
          htmlFor={quickId}
        >
          <input
            checked={filters.underThirtyMinutesOnly}
            className="h-4 w-4 accent-fresh-green"
            id={quickId}
            onChange={(event) =>
              onChange({
                ...filters,
                underThirtyMinutesOnly: event.target.checked,
              })
            }
            type="checkbox"
          />
          Under 30 minutes
        </label>
      </div>
      <Button className="mt-3" onClick={onClear} size="sm" variant="ghost">
        Clear filters
      </Button>
    </section>
  )
}
