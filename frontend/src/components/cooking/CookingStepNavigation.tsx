import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '../ui/Button'

export interface CookingStepNavigationProps {
  currentIndex: number
  totalSteps: number
  isComplete: boolean
  onPrevious: () => void
  onNext: () => void
}

export function CookingStepNavigation({
  currentIndex,
  isComplete,
  onNext,
  onPrevious,
  totalSteps,
}: CookingStepNavigationProps) {
  const isFirstStep = currentIndex === 0
  const isLastStep = currentIndex === totalSteps - 1

  return (
    <div className="mt-6 grid grid-cols-2 gap-3">
      <Button
        disabled={isFirstStep || isComplete}
        onClick={onPrevious}
        variant="secondary"
      >
        <ChevronLeft aria-hidden="true" size={18} />
        Previous step
      </Button>
      <Button disabled={isComplete} onClick={onNext}>
        {isLastStep ? 'Finish recipe' : 'Next step'}
        <ChevronRight aria-hidden="true" size={18} />
      </Button>
    </div>
  )
}
