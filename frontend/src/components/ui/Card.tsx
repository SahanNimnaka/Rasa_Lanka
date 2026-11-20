import type { HTMLAttributes } from 'react'
import { cn } from '../../utils/cn'

export type CardProps = HTMLAttributes<HTMLDivElement>

export function Card({ className, ...props }: CardProps) {
  return (
    <div
      className={cn(
        'rounded-card border border-forest-green/10 bg-white/95 p-4 shadow-card backdrop-blur-sm transition-all duration-200 sm:p-6',
        className,
      )}
      {...props}
    />
  )
}
