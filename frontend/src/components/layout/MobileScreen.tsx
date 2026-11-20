import type { ReactNode } from 'react'
import { DesktopNavigation } from '../navigation/DesktopNavigation'
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
        'mobile-screen relative mx-auto min-h-dvh w-full max-w-[430px] px-6 pb-10 text-ink md:max-w-none md:px-8 lg:px-12',
        className,
      )}
    >
      <DesktopNavigation />
      <div className="relative z-10 mx-auto w-full max-w-[1440px] md:pt-8">
        {children}
      </div>
    </main>
  )
}
