import { Card } from '../ui/Card'
import type { RecipeStep } from '../../types/recipe'
import { cn } from '../../utils/cn'

export interface CookingStepCardProps {
  step: RecipeStep
  isCurrent: boolean
  onNext?: () => void
}

export function CookingStepCard({
  isCurrent,
  onNext,
  step,
}: CookingStepCardProps) {
  return (
    <Card
      aria-current={isCurrent ? 'step' : undefined}
      aria-label={`Step ${step.number}: ${step.title}`}
      className={cn(
        'p-3 transition-colors',
        isCurrent && 'border-fresh-green bg-warm-cream/60 shadow-elevated',
      )}
      role="article"
    >
      <div className="flex gap-3">
        <span
          aria-hidden="true"
          className={cn(
            'shrink-0 text-lg font-bold',
            isCurrent ? 'text-spice-orange' : 'text-progress-track',
          )}
        >
          {String(step.number).padStart(2, '0')}
        </span>
        <div className="min-w-0 flex-1">
          <h3 className="text-sm font-bold text-forest-green">{step.title}</h3>
          <div className="mt-2 flex gap-3">
            <img
              alt={step.imageAlt}
              className="h-16 w-16 shrink-0 rounded-lg bg-warm-cream object-cover"
              height={64}
              loading="lazy"
              src={step.image}
              width={64}
            />
            <p className="text-xs leading-5 text-muted">{step.description}</p>
          </div>
          {isCurrent && onNext && (
            <button
              className="mt-2 text-xs font-bold text-fresh-green hover:text-forest-green"
              onClick={onNext}
              type="button"
            >
              Next
            </button>
          )}
        </div>
      </div>
    </Card>
  )
}
