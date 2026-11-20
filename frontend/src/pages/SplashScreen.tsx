import { useEffect } from 'react'
import { motion, useReducedMotion } from 'framer-motion'
import { Sparkles } from 'lucide-react'
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
      className="splash-screen relative min-h-dvh w-full overflow-hidden"
    >
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 overflow-hidden"
      >
        <div className="absolute -left-24 -top-24 h-72 w-72 rounded-full border border-forest-green/10 bg-fresh-green/5 blur-sm" />
        <div className="absolute -bottom-32 -right-20 h-80 w-80 rounded-full bg-spice-orange/10 blur-2xl" />
      </div>
      <div
        aria-hidden="true"
        className="absolute inset-0 opacity-20"
        style={{
          backgroundImage:
            'radial-gradient(rgba(31,35,40,0.12) 1px, transparent 1px)',
          backgroundSize: '22px 22px',
        }}
      />

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
        <div className="logo-glow splash-logo-shell rounded-full p-5 backdrop-blur-sm">
          <BrandLogo
            imageAlt="RasaLanka cooking logo"
            size="xl"
            showWordmark={false}
            src="/rasalanka-logo.png"
          />
        </div>
        <p className="eyebrow-label mt-8 text-spice-orange/90">
          Taste the island
        </p>
        <h1
          className="mt-2 text-4xl font-extrabold leading-tight tracking-tight text-ink"
          id="splash-title"
        >
          RasaLanka
        </h1>
        <p className="mt-3 flex max-w-80 items-center justify-center gap-2 text-sm leading-6 text-muted">
          <Sparkles
            aria-hidden="true"
            className="text-spice-orange"
            size={15}
          />
          Discover Sri Lankan Recipes From Your Ingredients.
        </p>
      </motion.section>

      <div className="splash-loading absolute left-1/2 top-[78%] w-48 -translate-x-1/2 -translate-y-1/2">
        <LoadingIndicator />
      </div>
    </main>
  )
}
