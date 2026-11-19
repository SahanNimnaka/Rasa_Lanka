import type { ButtonHTMLAttributes } from 'react'
import { cn } from '../../utils/cn'

const variantClasses = {
  primary: 'bg-fresh-green text-white hover:bg-forest-green',
  secondary:
    'border border-forest-green/20 bg-white text-forest-green hover:bg-warm-cream',
  ghost: 'bg-transparent text-forest-green hover:bg-forest-green/5',
  danger: 'bg-chilli-red text-white hover:bg-chilli-red/90',
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
        'inline-flex items-center justify-center gap-2 rounded-control font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-spice-orange focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
        variantClasses[variant],
        sizeClasses[size],
        className,
      )}
      type={type}
      {...props}
    />
  )
}
