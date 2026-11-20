import { motion, useReducedMotion } from 'framer-motion'

export interface LoadingIndicatorProps {
  label?: string
}

export function LoadingIndicator({
  label = 'Loading...',
}: LoadingIndicatorProps) {
  const prefersReducedMotion = useReducedMotion() ?? false

  return (
    <div className="flex flex-col items-center">
      <div
        aria-hidden="true"
        className="h-1.5 w-40 overflow-hidden rounded-full bg-forest-green/10"
      >
        <motion.div
          animate={{ scaleX: 0.5 }}
          className="progress-fill h-full w-full origin-left"
          initial={prefersReducedMotion ? false : { scaleX: 0 }}
          transition={{
            delay: prefersReducedMotion ? 0 : 0.15,
            duration: prefersReducedMotion ? 0 : 0.65,
            ease: 'easeOut',
          }}
        />
      </div>
      <p
        aria-live="polite"
        className="mt-4 text-sm leading-5 text-muted"
        role="status"
      >
        {label}
      </p>
    </div>
  )
}
