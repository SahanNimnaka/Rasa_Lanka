import { Button } from '../ui/Button'

export interface AuthPlaceholderPanelProps {
  onOpenHome: () => void
}

export function AuthPlaceholderPanel({
  onOpenHome,
}: AuthPlaceholderPanelProps) {
  return (
    <section
      aria-label="Development authentication placeholder"
      className="mt-5 rounded-card border border-dashed border-spice-orange/70 bg-warm-cream/60 p-3 text-center"
    >
      <p className="text-xs font-semibold text-forest-green">
        Authentication backend not connected
      </p>
      <p className="mt-1 text-xs leading-5 text-muted">
        Open Home as a frontend preview. This does not sign you in.
      </p>
      <Button
        className="mt-2 min-h-9 w-full text-sm"
        onClick={onOpenHome}
        size="sm"
        variant="secondary"
      >
        Open Home preview
      </Button>
    </section>
  )
}
