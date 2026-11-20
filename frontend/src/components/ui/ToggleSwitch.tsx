import { cn } from '../../utils/cn'

export interface ToggleSwitchProps {
  label: string
  checked: boolean
  onChange: (checked: boolean) => void
}

export function ToggleSwitch({ checked, label, onChange }: ToggleSwitchProps) {
  return (
    <button
      aria-checked={checked}
      aria-label={label}
      className={cn(
        'relative h-8 w-14 shrink-0 rounded-full transition-colors',
        checked ? 'bg-fresh-green' : 'bg-progress-track',
      )}
      onClick={() => onChange(!checked)}
      role="switch"
      type="button"
    >
      <span
        aria-hidden="true"
        className={cn(
          'toggle-knob absolute top-1 h-6 w-6 rounded-full bg-white shadow-card transition-transform',
          checked ? 'translate-x-7' : 'translate-x-1',
        )}
      />
    </button>
  )
}
