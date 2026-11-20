import { useState } from 'react'
import { Play } from 'lucide-react'
import { useNavigate, useParams } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { RecipeTabs } from '../../components/recipes/RecipeTabs'
import { Card } from '../../components/ui/Card'
import { useCookingFlow } from '../../context/cookingFlowStore'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import {
  getDefaultRecipe,
  getRecipeById,
} from '../../services/mockRecipeService'
import type { RecipeTab } from '../../types/recipe'

const COOKING_TIPS = [
  'Rinse lentils until the water runs clear.',
  'Keep the heat low after adding coconut milk.',
  'Stir occasionally to prevent sticking.',
  'Adjust salt and spice to your preference.',
] as const

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

      <section className="mt-2">
        <h1 className="text-2xl font-bold" id="guidance-title">
          {recipe.name}
        </h1>
        <h2 className="mt-2 text-lg font-bold text-forest-green">
          Cooking Guidance
        </h2>

        <RecipeTabs
          activeTab={activeTab}
          idPrefix="guidance"
          onChange={handleTabChange}
        />

        <div
          aria-labelledby="guidance-tab-video"
          className="mt-5"
          id="guidance-panel"
          role="tabpanel"
        >
          <div
            aria-label="Video player placeholder. No video asset is connected."
            className="relative flex h-[200px] w-full flex-col items-center justify-center overflow-hidden rounded-card border border-forest-green/20 bg-warm-cream/70 px-4 text-center"
            role="group"
          >
            <p className="text-xs font-semibold uppercase tracking-wider text-muted">
              Video player
            </p>
            <span
              aria-hidden="true"
              className="mt-4 flex h-12 w-12 items-center justify-center rounded-full border border-forest-green/20 bg-white/80 text-forest-green"
            >
              <Play className="ml-0.5 fill-current" size={20} />
            </span>
            <h3 className="mt-4 text-sm font-bold text-forest-green">
              Video unavailable
            </h3>
            <p className="mt-1 max-w-[260px] text-xs leading-5 text-muted">
              No video asset is connected. Use the Instructions tab for written
              steps.
            </p>
          </div>

          <div
            aria-label="Static video timeline preview: 01:20 of 08:30"
            className="mt-4"
            role="img"
          >
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-progress-track/50">
              <div className="h-full w-[16%] rounded-full bg-forest-green" />
            </div>
            <div className="mt-2 flex justify-between text-xs font-semibold text-muted">
              <span>01:20</span>
              <span>08:30</span>
            </div>
          </div>

          <section aria-labelledby="cooking-tips-title" className="mt-10">
            <h3 className="text-lg font-bold" id="cooking-tips-title">
              Cooking Tips
            </h3>
            <Card className="mt-4">
              <ul className="grid gap-4">
                {COOKING_TIPS.map((tip) => (
                  <li className="flex gap-3" key={tip}>
                    <span
                      aria-hidden="true"
                      className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-spice-orange"
                    />
                    <p className="text-sm leading-6 text-muted">{tip}</p>
                  </li>
                ))}
              </ul>
            </Card>
          </section>
        </div>
      </section>
    </MobileScreen>
  )
}
