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
        'relative h-8 w-14 shrink-0 rounded-full transition-all duration-200',
        checked
          ? 'bg-gradient-to-r from-[#2E7D32] to-[#0B3D2E] shadow-[0_6px_16px_rgba(11,61,46,0.24)]'
          : 'bg-progress-track/60',
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
