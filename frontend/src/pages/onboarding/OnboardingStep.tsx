import { motion, useReducedMotion } from 'framer-motion'
import { Sparkles } from 'lucide-react'
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
      className="w-full lg:grid lg:grid-cols-2 lg:items-center lg:gap-16"
      initial={prefersReducedMotion ? false : { opacity: 0, y: 8 }}
      transition={{
        duration: prefersReducedMotion ? 0 : 0.25,
        ease: 'easeOut',
      }}
    >
      <div className="relative">
        <div
          aria-hidden="true"
          className="absolute -right-2 -top-3 z-10 flex items-center gap-1 rounded-full bg-spice-orange px-3 py-1 text-[10px] font-extrabold uppercase tracking-wider text-white shadow-lg"
        >
          <Sparkles size={12} />
          Easy cooking
        </div>
        <img
          alt={step.imageAlt}
          className="onboarding-artwork block w-full"
          height="258"
          src={step.image}
          width="342"
        />
      </div>
      <div className="onboarding-copy">
        <div className="onboarding-title-slot">
          <h1
            className="onboarding-step-title text-center text-[1.625rem] font-semibold leading-9 tracking-normal text-ink lg:text-left"
            id={`${step.id}-title`}
          >
            {step.title}
          </h1>
        </div>
        <p className="onboarding-description text-center text-base leading-6 text-muted lg:text-left">
          {step.description}
        </p>
      </div>
    </motion.section>
  )
}
