import type { ReactNode } from 'react'
import { Button } from '../../components/ui/Button'
import { PaginationDots } from './PaginationDots'
import './onboarding.css'

export interface OnboardingLayoutProps {
  children: ReactNode
  currentStep: number
  totalSteps: number
  actionLabel: string
  canSkip: boolean
  onAction: () => void
  onSkip: () => void
}

export function OnboardingLayout({
  actionLabel,
  canSkip,
  children,
  currentStep,
  onAction,
  onSkip,
  totalSteps,
}: OnboardingLayoutProps) {
  return (
    <main className="onboarding-screen relative mx-auto min-h-dvh w-full max-w-[430px] overflow-hidden bg-white px-6 text-ink">
      <header className="onboarding-header flex items-center justify-end">
        {canSkip && (
          <Button
            className="min-h-8 px-3 text-sm font-normal text-ink hover:bg-transparent"
            onClick={onSkip}
            size="sm"
            variant="ghost"
          >
            Skip
          </Button>
        )}
      </header>

      <div className="onboarding-content">{children}</div>

      <footer className="onboarding-footer absolute inset-x-6 flex flex-col items-center">
        <PaginationDots currentStep={currentStep} totalSteps={totalSteps} />
        <Button
          className="h-[46px] min-h-[46px] w-full rounded-none px-4 text-sm"
          onClick={onAction}
          variant="ink"
        >
          {actionLabel}
        </Button>
      </footer>
    </main>
  )
}
