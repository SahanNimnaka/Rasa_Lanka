import type { ReactNode } from 'react'
import { cn } from '../../utils/cn'

export interface MobileScreenProps {
  children: ReactNode
  className?: string
  'aria-labelledby'?: string
}

export function MobileScreen({
  children,
  className,
  'aria-labelledby': ariaLabelledBy,
}: MobileScreenProps) {
  return (
    <main
      aria-labelledby={ariaLabelledBy}
      className={cn(
        'relative mx-auto min-h-dvh w-full max-w-[430px] bg-white px-6 pb-10 text-ink',
        className,
      )}
    >
      {children}
    </main>
  )
}
