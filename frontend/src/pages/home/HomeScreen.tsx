import {
  ArrowRight,
  Bell,
  Bookmark,
  Camera,
  Clock,
  ImageUp,
  Sparkles,
  UtensilsCrossed,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useRecipeCategories, useRecipes } from '../../hooks/queries'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { BottomNavigation } from '../../components/navigation/BottomNavigation'
import { RecipeCard } from '../../components/recipes/RecipeCard'
import { BrandLogo } from '../../components/BrandLogo'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { LoadingIndicator } from '../../components/ui/LoadingIndicator'
import {
  recipeDurationLabel,
  recipeMetaLabel,
} from '../../utils/recipePresentation'

const shortcuts = [
  {
    icon: UtensilsCrossed,
    label: 'Find Recipes',
    to: '/search',
    tile: 'from-[#FF8A00] to-[#F6B73C]',
  },
  {
    icon: Bookmark,
    label: 'Saved Recipes',
    to: '/saved',
    tile: 'from-[#2E7D32] to-[#0B3D2E]',
  },
  {
    icon: Clock,
    label: 'History',
    to: '/history',
    tile: 'from-[#0B3D2E] to-[#2E7D32]',
  },
] as const

export function HomeScreen() {
  const navigate = useNavigate()
  const recipesQuery = useRecipes({ page: 1, per_page: 4, sort: 'name' })
  const categoriesQuery = useRecipeCategories({ page: 1, per_page: 100 })

  const recipes = recipesQuery.data?.data ?? []
  const categoryNames = new Map(
    (categoriesQuery.data?.data ?? []).map((category) => [
      category.id,
      category.name,
    ]),
  )

  return (
    <MobileScreen aria-labelledby="home-title" className="pb-28">
      <header className="flex min-h-14 items-center justify-between md:justify-end">
        <BrandLogo
          imageAlt="RasaLanka logo"
          className="md:hidden"
          size="sm"
          src="/rasalanka-logo.png"
        />
        <Button
          aria-label="Notifications"
          className="h-10 w-10 rounded-full border border-forest-green/10 bg-white/90 p-0 shadow-sm"
          onClick={() => navigate('/notifications')}
          size="sm"
          variant="ghost"
        >
          <Bell aria-hidden="true" size={20} />
        </Button>
      </header>

      <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:items-start">
        <div>
          <section className="hero-card rounded-3xl p-6 md:p-8 lg:p-10">
            <p className="eyebrow-label text-spice-orange">
              Cook smarter today
            </p>
            <h1
              className="mt-2 text-[1.75rem] font-extrabold md:text-4xl"
              id="home-title"
            >
              Welcome, Sahan
            </h1>
            <p className="mt-2 text-sm leading-6 text-muted md:max-w-xl md:text-base">
              Discover Sri Lankan recipes from the ingredients you have.
            </p>
            <div className="relative mt-5 flex flex-wrap items-center gap-2">
              <span className="rounded-full bg-forest-green/8 px-3 py-1 text-[11px] font-bold text-fresh-green backdrop-blur-sm">
                Smart recipe ideas
              </span>
              <span className="rounded-full bg-forest-green/8 px-3 py-1 text-[11px] font-bold text-fresh-green backdrop-blur-sm">
                Local ingredients
              </span>
            </div>
          </section>

          <div className="mt-5 grid gap-3 md:grid-cols-2 lg:grid-cols-1">
            <button
              className="group w-full text-left"
              onClick={() => navigate('/ingredients/camera')}
              type="button"
            >
              <Card className="flex items-center gap-4 p-4 transition-all duration-200 group-hover:-translate-y-0.5 group-hover:shadow-elevated md:min-h-24 md:p-5">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#FF8A00] to-[#F6B73C] text-white shadow-md">
                  <Camera aria-hidden="true" size={24} />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block font-bold text-ink">
                    Scan Ingredients
                  </span>
                  <span className="block text-xs text-muted">
                    Use your camera to identify ingredients
                  </span>
                </span>
                <ArrowRight
                  aria-hidden="true"
                  className="shrink-0 text-spice-orange transition-transform group-hover:translate-x-1"
                  size={18}
                />
              </Card>
            </button>

            <button
              className="group w-full text-left"
              onClick={() => navigate('/ingredients/upload')}
              type="button"
            >
              <Card className="flex items-center gap-4 p-4 transition-all duration-200 group-hover:-translate-y-0.5 group-hover:shadow-elevated md:min-h-24 md:p-5">
                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#2E7D32] to-[#0B3D2E] text-white shadow-md">
                  <ImageUp aria-hidden="true" size={24} />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block font-bold text-ink">Upload Image</span>
                  <span className="block text-xs text-muted">
                    Choose an image from your gallery
                  </span>
                </span>
                <ArrowRight
                  aria-hidden="true"
                  className="shrink-0 text-fresh-green transition-transform group-hover:translate-x-1"
                  size={18}
                />
              </Card>
            </button>
          </div>

          <div className="mt-6 grid grid-cols-3 gap-3">
            {shortcuts.map(({ icon: Icon, label, to, tile }) => (
              <button key={to} onClick={() => navigate(to)} type="button">
                <Card className="flex h-[78px] flex-col items-center justify-center gap-2 px-1 py-2 text-center transition-all duration-200 hover:-translate-y-0.5 hover:shadow-elevated md:h-28 md:gap-3">
                  <span
                    className={`flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br ${tile} text-white shadow-sm md:h-11 md:w-11`}
                  >
                    <Icon aria-hidden="true" size={18} />
                  </span>
                  <span className="text-[11px] font-bold leading-tight text-ink md:text-sm">
                    {label}
                  </span>
                </Card>
              </button>
            ))}
          </div>
        </div>

        <div>
          <section aria-labelledby="popular-title" className="lg:mt-1">
            <h2
              className="section-title text-lg font-extrabold md:text-2xl"
              id="popular-title"
            >
              Sri Lankan Recipes
            </h2>

            {recipesQuery.isPending ? (
              <div className="mt-5">
                <LoadingIndicator label="Loading recipes..." />
              </div>
            ) : recipesQuery.isError ? (
              <div className="mt-4 rounded-2xl border border-chilli-red/20 bg-white/70 p-5 text-center">
                <h3 className="text-sm font-bold text-ink">
                  Recipes could not be loaded.
                </h3>
                <p className="mt-1 text-xs leading-5 text-muted">
                  {recipesQuery.error?.message ??
                    'The recipe service is temporarily unavailable.'}
                </p>
                <Button
                  className="mt-3"
                  onClick={() => void recipesQuery.refetch()}
                  size="sm"
                >
                  Try again
                </Button>
              </div>
            ) : recipes.length === 0 ? (
              <div className="mt-4 rounded-2xl border border-progress-track/50 p-6 text-center">
                <h3 className="text-sm font-bold text-ink">
                  No recipes are available yet.
                </h3>
                <p className="mt-1 text-xs leading-5 text-muted">
                  Check back after the recipe catalogue has been updated.
                </p>
              </div>
            ) : (
              <div className="mt-4 grid grid-cols-2 gap-3 md:gap-5">
                {recipes.map((recipe) => (
                  <RecipeCard
                    duration={recipeDurationLabel(recipe.durationMinutes)}
                    key={recipe.id}
                    meta={
                      recipe.recipeCategory?.name ??
                      categoryNames.get(recipe.recipeCategoryId ?? '') ??
                      recipeMetaLabel(recipe)
                    }
                    name={recipe.name}
                    onSelect={() => navigate(`/recipes/${recipe.id}`)}
                  />
                ))}
              </div>
            )}
          </section>

          <div className="glass-panel mt-6 flex items-center justify-center gap-2 rounded-2xl px-4 py-3 text-xs font-semibold text-muted md:py-4">
            <Sparkles
              aria-hidden="true"
              className="text-spice-orange"
              size={14}
            />
            <span>Fresh ideas from the ingredients you already have</span>
          </div>
        </div>
      </div>

      <BottomNavigation />
    </MobileScreen>
  )
}
