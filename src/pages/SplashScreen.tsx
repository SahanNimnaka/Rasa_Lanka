import { useEffect } from 'react'
import { motion, useReducedMotion } from 'framer-motion'
import { BrandLogo } from '../components/BrandLogo'
import { LoadingIndicator } from '../components/ui/LoadingIndicator'

export interface SplashScreenProps {
  onComplete?: () => void
}

export function SplashScreen({ onComplete }: SplashScreenProps) {
  const prefersReducedMotion = useReducedMotion() ?? false

  useEffect(() => {
    if (!onComplete) {
      return
    }

    const timeoutId = window.setTimeout(onComplete, 1200)

    return () => window.clearTimeout(timeoutId)
  }, [onComplete])

  return (
    <main
      aria-busy="true"
      className="relative min-h-dvh w-full overflow-hidden bg-white text-ink"
    >
      <motion.section
        animate={{ opacity: 1, y: 0 }}
        aria-labelledby="splash-title"
        className="absolute left-1/2 top-[42%] flex w-[calc(100%-3rem)] max-w-80 -translate-x-1/2 -translate-y-1/2 flex-col items-center text-center"
        initial={prefersReducedMotion ? false : { opacity: 0, y: 10 }}
        transition={{
          duration: prefersReducedMotion ? 0 : 0.45,
          ease: 'easeOut',
        }}
      >
        <BrandLogo
          imageAlt="RasaLanka cooking logo"
          size="xl"
          showWordmark={false}
          src="/rasalanka-logo.png"
        />
        <h1
          className="mt-8 text-3xl font-semibold leading-tight tracking-normal text-ink"
          id="splash-title"
        >
          RasaLanka
        </h1>
        <p className="mt-4 max-w-80 text-base leading-6 text-muted">
          Discover Sri Lankan Recipes From Your Ingredients.
        </p>
      </motion.section>

      <div className="absolute left-1/2 top-[74%] -translate-x-1/2 -translate-y-1/2">
        <LoadingIndicator />
      </div>
    </main>
  )
}
