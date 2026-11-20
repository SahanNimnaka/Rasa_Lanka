import { cn } from '../../utils/cn'

export interface PaginationDotsProps {
  currentStep: number
  totalSteps: number
}

export function PaginationDots({
  currentStep,
  totalSteps,
}: PaginationDotsProps) {
  return (
    <nav aria-label="Onboarding progress">
      <ol className="flex items-center justify-center gap-2">
        {Array.from({ length: totalSteps }, (_, index) => {
          const isCurrentStep = index === currentStep

          return (
            <li
              aria-current={isCurrentStep ? 'step' : undefined}
              aria-label={`Step ${index + 1} of ${totalSteps}${isCurrentStep ? ', current step' : ''}`}
              className={cn(
                'h-2 rounded-full transition-all duration-300',
                isCurrentStep
                  ? 'w-7 bg-gradient-to-r from-[#FF8A00] to-[#F6B73C] shadow-[0_0_10px_rgba(255,138,0,0.5)]'
                  : 'w-2 bg-progress-track',
              )}
              key={index}
            />
          )
        })}
      </ol>
    </nav>
  )
}
