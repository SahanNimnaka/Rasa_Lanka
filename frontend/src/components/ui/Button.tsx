import type { ButtonHTMLAttributes } from 'react'
import { cn } from '../../utils/cn'

const variantClasses = {
  primary: 'btn-variant-primary',
  secondary: 'btn-variant-secondary',
  ghost: 'btn-variant-ghost',
  danger: 'btn-variant-danger',
  ink: 'btn-variant-ink',
} as const

const sizeClasses = {
  sm: 'min-h-9 px-3 text-sm',
  md: 'min-h-11 px-4 text-base',
  lg: 'min-h-12 px-6 text-base',
} as const

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: keyof typeof variantClasses
  size?: keyof typeof sizeClasses
}

export function Button({
  className,
  size = 'md',
  type = 'button',
  variant = 'primary',
  ...props
}: ButtonProps) {
  return (
    <button
      className={cn(
        'inline-flex items-center justify-center gap-2 rounded-full font-semibold transition-all duration-200 focus-visible:ring-2 focus-visible:ring-spice-orange focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:transform-none disabled:shadow-none',
        variantClasses[variant],
        sizeClasses[size],
        className,
      )}
      type={type}
      {...props}
    />
  )
}
