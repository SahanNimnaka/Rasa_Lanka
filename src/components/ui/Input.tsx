import { useId, type InputHTMLAttributes } from 'react'
import { cn } from '../../utils/cn'

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string
  hint?: string
  error?: string
}

export function Input({
  'aria-describedby': ariaDescribedBy,
  className,
  error,
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
        className="text-sm font-semibold text-forest-green"
        htmlFor={inputId}
      >
        {label}
      </label>
      <input
        aria-describedby={describedBy || undefined}
        aria-invalid={error ? true : undefined}
        className={cn(
          'min-h-11 w-full rounded-control border border-forest-green/20 bg-white px-3 py-2 text-base text-forest-green placeholder:text-forest-green/55 focus:border-fresh-green focus:outline-none focus:ring-2 focus:ring-fresh-green/25',
          error &&
            'border-chilli-red focus:border-chilli-red focus:ring-chilli-red/25',
          className,
        )}
        id={inputId}
        {...props}
      />
      {hint && (
        <p className="text-sm text-forest-green/75" id={hintId}>
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
