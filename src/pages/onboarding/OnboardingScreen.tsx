import { useLocation, useNavigate } from 'react-router-dom'
import { OnboardingLayout } from './OnboardingLayout'
import { OnboardingStep } from './OnboardingStep'
import { onboardingSteps } from './onboardingData'

export interface OnboardingScreenProps {
  onComplete: () => void
}

export function OnboardingScreen({ onComplete }: OnboardingScreenProps) {
  const location = useLocation()
  const navigate = useNavigate()
  const locationState = location.state as { stepIndex?: unknown } | null
  const requestedStep = locationState?.stepIndex
  const currentStep =
    typeof requestedStep === 'number' &&
    Number.isInteger(requestedStep) &&
    requestedStep >= 0 &&
    requestedStep < onboardingSteps.length
      ? requestedStep
      : 0
  const step = onboardingSteps[currentStep]

  function handleNext() {
    if (currentStep === onboardingSteps.length - 1) {
      onComplete()
      return
    }

    navigate('/onboarding', {
      state: { stepIndex: currentStep + 1 },
    })
  }

  return (
    <OnboardingLayout
      actionLabel={step.actionLabel}
      canSkip={step.canSkip}
      currentStep={currentStep}
      onAction={handleNext}
      onSkip={onComplete}
      totalSteps={onboardingSteps.length}
    >
      <OnboardingStep key={step.id} step={step} />
    </OnboardingLayout>
  )
}
