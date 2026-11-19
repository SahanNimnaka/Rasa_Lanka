import { motion, useReducedMotion } from 'framer-motion'
import type { OnboardingStepData } from './onboardingData'

export interface OnboardingStepProps {
  step: OnboardingStepData
}

export function OnboardingStep({ step }: OnboardingStepProps) {
  const prefersReducedMotion = useReducedMotion() ?? false

  return (
    <motion.section
      animate={{ opacity: 1, y: 0 }}
      aria-labelledby={`${step.id}-title`}
      className="w-full"
      initial={prefersReducedMotion ? false : { opacity: 0, y: 8 }}
      transition={{
        duration: prefersReducedMotion ? 0 : 0.25,
        ease: 'easeOut',
      }}
    >
      <img
        alt={step.imageAlt}
        className="onboarding-artwork block w-full border border-progress-track"
        height="258"
        src={step.image}
        width="342"
      />
      <div className="onboarding-copy">
        <div className="onboarding-title-slot">
          <h1
            className="onboarding-step-title text-center text-[1.625rem] font-semibold leading-9 tracking-normal text-ink"
            id={`${step.id}-title`}
          >
            {step.title}
          </h1>
        </div>
        <p className="onboarding-description text-center text-base leading-6 text-muted">
          {step.description}
        </p>
      </div>
    </motion.section>
  )
}
