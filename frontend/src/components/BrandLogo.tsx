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
        <span className="logo-glow inline-flex shrink-0">
          <img
            alt={imageAlt}
            className={cn(
              'relative object-contain drop-shadow-sm',
              imageSize.className,
            )}
            height={imageSize.dimension}
            src={src}
            width={imageSize.dimension}
          />
        </span>
      )}
      {showWordmark && (
        <span className="brand-wordmark font-display bg-gradient-to-r from-[#1F2328] to-[#4B5563] bg-clip-text text-xl font-extrabold tracking-tight text-transparent">
          RasaLanka
        </span>
      )}
    </div>
  )
}
