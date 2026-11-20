import { cn } from '../../utils/cn'

export interface ChoiceChipProps {
  label: string
  selected: boolean
  onClick: () => void
  className?: string
}

export function ChoiceChip({
  className,
  label,
  onClick,
  selected,
}: ChoiceChipProps) {
  return (
    <button
      aria-pressed={selected}
      className={cn(
        'min-h-10 w-full rounded-control border px-2 text-center text-sm font-semibold transition-colors',
        selected
          ? 'border-fresh-green bg-fresh-green text-white'
          : 'border-forest-green/20 bg-white text-forest-green hover:bg-warm-cream',
        className,
      )}
      onClick={onClick}
      type="button"
    >
      {label}
    </button>
  )
}
