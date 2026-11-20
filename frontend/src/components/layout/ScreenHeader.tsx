import type { ReactNode } from 'react'
import { ArrowLeft } from 'lucide-react'
import { cn } from '../../utils/cn'
import { Button } from '../ui/Button'

export interface ScreenHeaderProps {
  onBack?: () => void
  action?: ReactNode
  className?: string
}

export function ScreenHeader({ action, className, onBack }: ScreenHeaderProps) {
  return (
    <header
      className={cn('flex min-h-11 items-center justify-between', className)}
    >
      {onBack ? (
        <Button
          aria-label="Go back"
          className="-ml-2 h-10 w-10 rounded-full p-0"
          onClick={onBack}
          size="sm"
          variant="ghost"
        >
          <ArrowLeft aria-hidden="true" size={20} />
        </Button>
      ) : (
        <span aria-hidden="true" className="h-10 w-10" />
      )}
      {action}
    </header>
  )
}
