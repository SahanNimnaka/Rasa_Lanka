import { useState } from 'react'
import { Minus, Plus, Users } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { clampServings, useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { cn } from '../../utils/cn'

interface ServingSizeRouteState {
  servings?: number
}

const QUICK_SERVINGS = [1, 2, 3, 4, 5, 6] as const

export function ServingSizeScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const goBack = useNavigateBack('/ingredients/quantities')
  const flow = useCookingFlow()

  const routeServings = (location.state as ServingSizeRouteState | null)
    ?.servings

  const [servings, setServings] = useState<number>(() =>
    clampServings(routeServings ?? flow.servings),
  )

  function updateServings(next: number) {
    const clamped = clampServings(next)
    setServings(clamped)
    flow.setServings(clamped)
  }

  function handleFindRecipes() {
    flow.setServings(servings)
    navigate('/recipes/recommendations', {
      state: { servings, ingredients: flow.ingredients },
    })
  }

  return (
    <MobileScreen aria-labelledby="serving-size-title">
      <ScreenHeader onBack={goBack} />

      <section className="mt-3 text-center lg:mx-auto lg:max-w-3xl">
        <p className="eyebrow-label">Plan your meal</p>
        <h1
          className="mt-1 text-[1.8rem] font-extrabold"
          id="serving-size-title"
        >
          Serving Size
        </h1>

        <Card className="glass-panel mx-auto mt-10 flex h-[170px] w-full max-w-[244px] flex-col items-center justify-center gap-3 rounded-3xl">
          <span className="flex h-14 w-14 items-center justify-center rounded-full bg-white text-fresh-green shadow-card">
            <Users aria-hidden="true" size={28} />
          </span>
          <p className="text-sm font-semibold text-ink">Number of people</p>
        </Card>

        <h2 className="mx-auto mt-8 max-w-[290px] text-xl font-bold leading-8 text-ink">
          How many people are you cooking for?
        </h2>

        <div className="mt-8 flex items-center justify-center gap-6">
          <Button
            aria-label="Decrease servings"
            className="h-12 w-12 rounded-full p-0"
            disabled={servings <= 1}
            onClick={() => updateServings(servings - 1)}
            variant="secondary"
          >
            <Minus aria-hidden="true" size={22} />
          </Button>
          <div aria-live="polite">
            <p
              className="serving-count bg-gradient-to-br from-[#1F2328] to-[#4B5563] bg-clip-text text-5xl font-extrabold text-transparent"
              data-testid="serving-count"
            >
              {servings}
            </p>
            <p className="mt-1 text-sm font-semibold text-muted">servings</p>
          </div>
          <Button
            aria-label="Increase servings"
            className="h-12 w-12 rounded-full p-0"
            disabled={servings >= 20}
            onClick={() => updateServings(servings + 1)}
            variant="secondary"
          >
            <Plus aria-hidden="true" size={22} />
          </Button>
        </div>

        <div className="mt-8 text-left">
          <h3 className="text-sm font-bold text-ink">Quick selection</h3>
          <div className="mt-3 grid grid-cols-6 gap-2">
            {QUICK_SERVINGS.map((option) => {
              const isSelected = option === servings
              return (
                <button
                  aria-label={
                    option === 6
                      ? 'Select 6 or more servings'
                      : `Select ${option} ${option === 1 ? 'serving' : 'servings'}`
                  }
                  aria-pressed={isSelected}
                  className={cn(
                    'min-h-11 rounded-control border text-sm font-semibold transition-colors',
                    isSelected
                      ? 'border-fresh-green bg-fresh-green text-white'
                      : 'border-forest-green/20 bg-white text-ink hover:bg-warm-cream',
                  )}
                  key={option}
                  onClick={() => updateServings(option)}
                  type="button"
                >
                  {option === 6 ? '6+' : option}
                </button>
              )
            })}
          </div>
        </div>

        <Button
          className="mt-8 h-[46px] w-full"
          onClick={handleFindRecipes}
          size="lg"
        >
          Find Recipes
        </Button>
      </section>
    </MobileScreen>
  )
}
