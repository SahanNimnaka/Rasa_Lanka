import { useId, type InputHTMLAttributes, type ReactNode } from 'react'
import { cn } from '../../utils/cn'

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  hint?: string
  error?: string
  endAdornment?: ReactNode
  hideLabel?: boolean
}

export function Input({
  'aria-describedby': ariaDescribedBy,
  className,
  endAdornment,
  error,
  hideLabel = false,
  hint,
  id,
  label,
  ...props
}: InputProps) {
  const generatedId = useId()
  const inputId = id ?? generatedId
  const hintId = hint ? `${inputId}-hint` : undefined
  const errorId = error ? `${inputId}-error` : undefined
  const describedBy = [ariaDescribedBy, hintId, errorId]
    .filter(Boolean)
    .join(' ')

  return (
    <div className="grid gap-2">
      <label
        className={cn('text-sm font-semibold text-ink', hideLabel && 'sr-only')}
        htmlFor={inputId}
      >
        {label}
      </label>
      <div className="relative">
        <input
          aria-describedby={describedBy || undefined}
          aria-invalid={error ? true : undefined}
          className={cn(
            'min-h-12 w-full rounded-xl border border-forest-green/15 bg-warm-cream/60 px-4 py-2.5 text-base text-ink shadow-sm placeholder:text-ink/45 focus:border-spice-orange focus:bg-white focus:outline-none focus:ring-4 focus:ring-spice-orange/15',
            error &&
              'border-chilli-red focus:border-chilli-red focus:ring-chilli-red/25',
            endAdornment && 'pr-12',
            className,
          )}
          id={inputId}
          {...props}
        />
        {endAdornment && (
          <div className="absolute inset-y-0 right-0 flex items-center pr-2">
            {endAdornment}
          </div>
        )}
      </div>
      {hint && (
        <p className="text-sm text-ink/75" id={hintId}>
          {hint}
        </p>
      )}
      {error && (
        <p
          className="text-sm font-medium text-chilli-red"
          id={errorId}
          role="alert"
        >
          {error}
        </p>
      )}
    </div>
  )
}
