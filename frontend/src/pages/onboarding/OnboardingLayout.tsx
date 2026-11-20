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
    <main className="onboarding-screen relative mx-auto min-h-dvh w-full max-w-[430px] overflow-hidden px-6 text-ink md:max-w-none md:px-8 lg:px-12">
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 overflow-hidden"
      >
        <div className="absolute -left-24 -top-24 h-64 w-64 rounded-full bg-spice-orange/12 blur-2xl" />
        <div className="absolute -bottom-28 -right-20 h-72 w-72 rounded-full bg-fresh-green/12 blur-2xl" />
      </div>

      <header className="onboarding-header relative z-10 flex items-center justify-between">
        <span className="eyebrow-label">RasaLanka guide</span>
        {canSkip && (
          <Button
            className="min-h-8 px-3 text-sm font-semibold"
            onClick={onSkip}
            size="sm"
            variant="ghost"
          >
            Skip
          </Button>
        )}
      </header>

      <div className="onboarding-content relative z-10 mx-auto w-full max-w-5xl">
        {children}
      </div>

      <footer className="onboarding-footer glass-panel absolute inset-x-6 z-10 flex flex-col items-center rounded-3xl border px-4 py-4">
        <PaginationDots currentStep={currentStep} totalSteps={totalSteps} />
        <Button
          className="mt-3 h-[50px] min-h-[50px] w-full text-base"
          onClick={onAction}
          size="lg"
        >
          {actionLabel}
        </Button>
      </footer>
    </main>
  )
}
