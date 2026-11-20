import type { HTMLAttributes } from 'react'
import { cn } from '../utils/cn'

const imageSizes = {
  sm: { className: 'h-8 w-8', dimension: 32 },
  md: { className: 'h-11 w-11', dimension: 44 },
  lg: { className: 'h-16 w-16', dimension: 64 },
  xl: { className: 'h-24 w-24', dimension: 96 },
} as const

export interface BrandLogoProps extends HTMLAttributes<HTMLDivElement> {
  src?: string
  imageAlt?: string
  size?: keyof typeof imageSizes
  showWordmark?: boolean
}

export function BrandLogo({
  className,
  imageAlt = '',
  size = 'md',
  showWordmark = true,
  src,
  ...props
}: BrandLogoProps) {
  const imageSize = imageSizes[size]

  return (
    <div className={cn('inline-flex items-center gap-3', className)} {...props}>
      {src && (
        <img
          alt={imageAlt}
          className={cn('shrink-0 object-contain', imageSize.className)}
          height={imageSize.dimension}
          src={src}
          width={imageSize.dimension}
        />
      )}
      {showWordmark && (
        <span className="font-display text-xl font-bold tracking-tight text-forest-green">
          RasaLanka
        </span>
      )}
    </div>
  )
}
