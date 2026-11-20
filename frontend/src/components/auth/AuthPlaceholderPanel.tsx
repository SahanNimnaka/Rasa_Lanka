import { Info } from 'lucide-react'
import { Button } from '../ui/Button'

export interface AuthPlaceholderPanelProps {
  onOpenHome: () => void
}

export function AuthPlaceholderPanel({
  onOpenHome,
}: AuthPlaceholderPanelProps) {
  return (
    <section
      aria-label="Guest preview"
      className="glass-panel relative mt-5 overflow-hidden rounded-2xl border border-dashed border-spice-orange/50 p-4"
    >
      <div
        aria-hidden="true"
        className="absolute -right-8 -top-8 h-24 w-24 rounded-full bg-spice-orange/10 blur-lg"
      />
      <div className="relative flex gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-spice-orange/15 text-spice-orange">
          <Info aria-hidden="true" size={18} />
        </span>
        <div className="min-w-0">
          <p className="text-xs font-extrabold text-ink">Browsing as a guest</p>
          <p className="mt-1 text-xs leading-5 text-muted">
            Open Home as a preview. Personal screens require signing in.
          </p>
        </div>
      </div>
      <Button
        className="relative mt-3 min-h-9 w-full text-sm"
        onClick={onOpenHome}
        size="sm"
        variant="secondary"
      >
        Open Home preview
      </Button>
    </section>
  )
}
