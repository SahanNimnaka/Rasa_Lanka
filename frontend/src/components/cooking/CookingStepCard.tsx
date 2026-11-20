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
        'flex items-center gap-3 rounded-2xl p-3 transition-all duration-200',
        isCurrent
          ? 'border-fresh-green/40 bg-gradient-to-r from-warm-cream/90 to-white/90 shadow-elevated'
          : 'opacity-85',
      )}
      role="article"
    >
      <span
        aria-hidden="true"
        className={cn(
          'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-sm font-extrabold',
          isCurrent
            ? 'bg-gradient-to-br from-[#FF8A00] to-[#F6B73C] text-white shadow-md'
            : 'bg-warm-cream text-muted',
        )}
      >
        {String(step.number).padStart(2, '0')}
      </span>
      <div className="min-w-0 flex-1">
        <h3 className="text-sm font-bold text-ink">{step.title}</h3>
        <p className="mt-1 text-xs leading-5 text-muted">{step.description}</p>
      </div>
      {isCurrent && onNext && (
        <button
          className="shrink-0 rounded-full bg-forest-green px-3 py-2 text-[11px] font-bold text-white transition-transform hover:scale-105"
          onClick={onNext}
          type="button"
        >
          Next
        </button>
      )}
    </Card>
  )
}
