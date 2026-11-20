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
      className={cn('choice-chip px-2', className)}
      onClick={onClick}
      type="button"
    >
      {label}
    </button>
  )
}
