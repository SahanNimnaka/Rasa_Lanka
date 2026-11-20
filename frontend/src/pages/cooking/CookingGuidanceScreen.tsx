import { useState } from 'react'
import { Lightbulb, Play } from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  getDefaultRecipe,
  getRecipeById,
} from '../../services/mockRecipeService'
import type { RecipeTab } from '../../types/recipe'
import { cn } from '../../utils/cn'

const COOKING_TIPS = [
  'Rinse lentils until the water runs clear.',
  'Keep the heat low after adding coconut milk.',
  'Stir occasionally to prevent sticking.',
  'Adjust salt and spice to your preference.',
] as const

const TABS: Array<{ id: RecipeTab; label: string }> = [
  { id: 'ingredients', label: 'Ingredients' },
  { id: 'instructions', label: 'Instructions' },
  { id: 'video', label: 'Video' },
]

export function CookingGuidanceScreen() {
  const navigate = useNavigate()
  const { recipeId } = useParams()
  const flow = useCookingFlow()
  const goBack = useNavigateBack(`/cooking/${recipeId ?? 'dhal-curry'}`)

  const recipe =
    getRecipeById(recipeId ?? flow.selectedRecipeId ?? '') ?? getDefaultRecipe()
  const [activeTab, setActiveTab] = useState<RecipeTab>('video')

  function handleTabChange(tab: RecipeTab) {
    if (tab === 'video') {
      setActiveTab('video')
      return
    }
    navigate(`/recipes/${recipe.id}`, { state: { tab } })
  }

  return (
    <MobileScreen aria-labelledby="guidance-title" className="pb-12">
      <ScreenHeader onBack={goBack} />

      <p className="eyebrow-label mt-2">Learn before you cook</p>
      <h1
        className="mt-1 text-[28px] leading-tight font-extrabold"
        id="guidance-title"
      >
        {recipe.name}
      </h1>
      <h2 className="mt-1 text-base font-bold text-fresh-green">
        Cooking Guidance
      </h2>

      <div
        aria-label="Recipe sections"
        className="glass-panel mt-6 grid grid-cols-3 gap-1 rounded-2xl p-1"
        role="tablist"
      >
        {TABS.map((tab) => (
          <button
            aria-controls="guidance-panel"
            aria-selected={activeTab === tab.id}
            className={cn(
              'min-h-10 rounded-xl px-1 text-sm font-bold transition-all duration-200',
              activeTab === tab.id
                ? 'bg-gradient-to-r from-[#2E7D32] to-[#0B3D2E] text-white shadow-md'
                : 'text-muted hover:bg-white/70 hover:text-ink',
            )}
            id={`guidance-${tab.id}-tab`}
            key={tab.id}
            onClick={() => handleTabChange(tab.id)}
            role="tab"
            type="button"
          >
            {tab.label}
          </button>
        ))}
      </div>

      <div
        aria-labelledby="guidance-video-tab"
        className="mt-5"
        id="guidance-panel"
        role="tabpanel"
      >
        <div
          aria-label="Video player placeholder. No video asset is connected."
          className="glass-panel relative mx-auto flex h-[232px] w-full flex-col items-center justify-center overflow-hidden rounded-2xl text-center shadow-elevated md:h-[360px] lg:max-w-5xl"
          role="group"
        >
          <div
            aria-hidden="true"
            className="absolute inset-0 opacity-20"
            style={{
              backgroundImage:
                'radial-gradient(rgba(31,35,40,0.12) 1px, transparent 1px)',
              backgroundSize: '18px 18px',
            }}
          />
          <div className="logo-glow relative">
            <span
              aria-hidden="true"
              className="flex h-16 w-16 items-center justify-center rounded-full border border-forest-green/15 bg-white text-fresh-green shadow-lg backdrop-blur-sm"
            >
              <Play className="ml-1 fill-current" size={26} />
            </span>
          </div>
          <p className="relative mt-4 text-xs font-bold tracking-wide text-ink">
            Video player
          </p>
          <h3 className="sr-only">Video unavailable</h3>
          <p className="sr-only">
            No video asset is connected. Use the Instructions tab for written
            steps.
          </p>
        </div>

        <div
          aria-label="Static video timeline preview: 01:20 of 08:30"
          className="glass-panel mt-4 rounded-2xl px-4 py-3"
          role="img"
        >
          <div className="h-1.5 w-full overflow-hidden rounded-full bg-forest-green/10">
            <div className="progress-fill h-full w-[16%] rounded-full" />
          </div>
          <div className="mt-2 flex justify-between text-[11px] font-bold text-muted">
            <span>01:20</span>
            <span>08:30</span>
          </div>
        </div>

        <section aria-labelledby="cooking-tips-title" className="mt-8">
          <h3
            className="section-title text-base font-extrabold"
            id="cooking-tips-title"
          >
            Cooking Tips
          </h3>
          <ul className="mt-4 grid gap-3 md:grid-cols-2 md:gap-5">
            {COOKING_TIPS.map((tip) => (
              <li
                className="glass-panel flex items-start gap-3 rounded-2xl p-3"
                key={tip}
              >
                <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-spice-orange/15 text-spice-orange">
                  <Lightbulb aria-hidden="true" size={15} />
                </span>
                <p className="text-sm leading-6 text-muted">{tip}</p>
              </li>
            ))}
          </ul>
        </section>
      </div>
    </MobileScreen>
  )
}
