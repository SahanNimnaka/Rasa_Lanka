import { GraduationCap, Sparkles, UserRound } from 'lucide-react'
import { BrandLogo } from '../../components/BrandLogo'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { useNavigateBack } from '../../hooks/useNavigateBack'

export function AboutScreen() {
  const goBack = useNavigateBack('/profile')

  return (
    <MobileScreen aria-labelledby="about-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <h1 className="sr-only" id="about-title">
        About
      </h1>

      <div className="lg:mx-auto lg:max-w-4xl">
        <div className="hero-card mt-2 flex flex-col items-center rounded-3xl px-6 py-8 text-center">
          <div className="logo-glow rounded-full bg-forest-green/8 p-4 backdrop-blur-sm">
            <BrandLogo
              className="justify-center"
              imageAlt="RasaLanka logo"
              size="lg"
              showWordmark
              src="/rasalanka-logo.png"
            />
          </div>
          <p className="eyebrow-label mt-6 text-spice-orange">Our mission</p>
          <p className="mt-2 text-sm leading-6 text-muted">
            AI-powered recipe recommendation for Sri Lankan cuisine.
          </p>
        </div>

        <section className="glass-panel mt-6 rounded-2xl p-5">
          <div className="flex items-center gap-3">
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-spice-orange/15 text-spice-orange">
              <Sparkles aria-hidden="true" size={17} />
            </span>
            <h2 className="text-base font-extrabold">Research Project</h2>
          </div>
          <p className="mt-3 text-sm leading-6 text-muted">
            Multi-Ingredient Image Recognition and Deep Learning for Sri Lankan
            Cuisine.
          </p>
          <p className="mt-2 text-sm font-bold text-fresh-green">
            RasaLanka Deep Learning
          </p>
        </section>

        <section className="glass-panel mt-5 rounded-2xl p-5">
          <p className="eyebrow-label">Developed by</p>
          <div className="mt-3 flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-[#2E7D32] to-[#0B3D2E] text-white shadow-md">
              <UserRound aria-hidden="true" size={19} />
            </span>
            <div>
              <p className="text-base font-extrabold">Sahan Nimnaka</p>
              <p className="mt-0.5 flex items-center gap-1 text-sm text-muted">
                <GraduationCap aria-hidden="true" size={14} />
                APIIT University
              </p>
            </div>
          </div>
        </section>
      </div>
    </MobileScreen>
  )
}
