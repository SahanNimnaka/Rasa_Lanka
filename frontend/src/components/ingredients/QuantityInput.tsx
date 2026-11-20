import { useId } from 'react'
import {
  MEASUREMENT_UNITS,
  type MeasurementUnit,
} from '../../types/ingredients'
import { cn } from '../../utils/cn'

export interface QuantityInputProps {
  label: string
  value: string
  unit: MeasurementUnit
  error?: string
  onValueChange: (value: string) => void
  onUnitChange: (unit: MeasurementUnit) => void
}

export function QuantityInput({
  error,
  label,
  onUnitChange,
  onValueChange,
  unit,
  value,
}: QuantityInputProps) {
  const generatedId = useId()
  const inputId = `${generatedId}-quantity`
  const errorId = `${generatedId}-error`

  return (
    <div className="grid min-w-0 grid-cols-1 gap-2">
      <label className="text-sm font-semibold text-ink" htmlFor={inputId}>
        {label}
      </label>
      <div className="flex min-w-0 gap-2">
        <input
          aria-describedby={error ? errorId : undefined}
          aria-invalid={error ? true : undefined}
          aria-label={`Quantity for ${label}`}
          className={cn(
            'min-w-0 flex-1 rounded-control border border-forest-green/20 bg-white px-3 py-2 text-base text-ink focus:border-fresh-green focus:outline-none focus:ring-2 focus:ring-fresh-green/25',
            error &&
              'border-chilli-red focus:border-chilli-red focus:ring-chilli-red/25',
          )}
          id={inputId}
          inputMode="decimal"
          onChange={(event) => onValueChange(event.target.value)}
          type="text"
          value={value}
        />
        <select
          aria-label={`Unit for ${label}`}
          className="w-[86px] shrink-0 rounded-control border border-forest-green/20 bg-white px-2 py-2 text-sm text-ink focus:border-fresh-green focus:outline-none focus:ring-2 focus:ring-fresh-green/25"
          onChange={(event) =>
            onUnitChange(event.target.value as MeasurementUnit)
          }
          value={unit}
        >
          {MEASUREMENT_UNITS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </div>
      {error && (
        <p
          className="text-xs font-medium text-chilli-red"
          id={errorId}
          role="alert"
        >
          {error}
        </p>
      )}
    </div>
  )
}
