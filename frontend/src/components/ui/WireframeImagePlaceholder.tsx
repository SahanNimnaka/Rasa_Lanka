import { Image as ImageIcon } from 'lucide-react'
import { cn } from '../../utils/cn'

export interface WireframeImagePlaceholderProps {
  label?: string
  accessibleLabel?: string
  className?: string
}

export function WireframeImagePlaceholder({
  accessibleLabel,
  className,
  label = 'Recipe image',
}: WireframeImagePlaceholderProps) {
  return (
    <div
      aria-label={accessibleLabel ?? label}
      className={cn(
        'relative flex shrink-0 flex-col items-center justify-center overflow-hidden rounded-xl border border-forest-green/12 bg-gradient-to-br from-warm-cream to-warm-cream/35 px-2 text-center text-[11px] font-semibold leading-4 text-muted',
        className,
      )}
      role="img"
    >
      <span
        aria-hidden="true"
        className="mb-1.5 flex h-7 w-7 items-center justify-center rounded-full bg-white/80 text-fresh-green shadow-sm"
      >
        <ImageIcon size={15} strokeWidth={2} />
      </span>
      <span className="px-1">{label}</span>
    </div>
  )
}
