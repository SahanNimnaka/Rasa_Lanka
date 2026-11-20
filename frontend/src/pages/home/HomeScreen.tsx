import {
  Bell,
  Bookmark,
  Camera,
  Clock,
  Clock3,
  ImageUp,
  Sparkles,
  UtensilsCrossed,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { BottomNavigation } from '../../components/navigation/BottomNavigation'
import { RecipeCard } from '../../components/recipes/RecipeCard'
import { BrandLogo } from '../../components/BrandLogo'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'

const shortcuts = [
  { icon: UtensilsCrossed, label: 'Find Recipes', to: '/search' },
  { icon: Bookmark, label: 'Saved Recipes', to: '/saved' },
  { icon: Clock, label: 'History', to: '/history' },
] as const

const popularRecipes = [
  {
    id: 'dhal-curry',
    name: 'Dhal Curry',
    image: '/images/recipes/dhal-curry.jpg',
    rating: '4.8',
    duration: '25 min',
  },
  {
    id: 'pol-sambol',
    name: 'Pol Sambol',
    image: '/images/recipes/pol-sambol.jpg',
    rating: '4.8',
    duration: '10 min',
  },
] as const

export function HomeScreen() {
  const navigate = useNavigate()

  return (
    <MobileScreen aria-labelledby="home-title" className="pb-28">
      <header className="flex min-h-14 items-center justify-between">
        <BrandLogo
          imageAlt="RasaLanka logo"
          size="sm"
          src="/rasalanka-logo.png"
        />
        <Button
          aria-label="Notifications"
          className="h-10 w-10 rounded-full p-0"
          onClick={() => navigate('/notifications')}
          size="sm"
          variant="ghost"
        >
          <Bell aria-hidden="true" size={20} />
        </Button>
      </header>

      <h1 className="mt-10 text-[1.5rem] font-bold" id="home-title">
        Welcome, Sahan
      </h1>
      <p className="mt-2 text-sm leading-6 text-muted">
        Discover Sri Lankan recipes from the ingredients you have.
      </p>

      <div className="mt-8 grid gap-4">
        <button
          className="w-full text-left"
          onClick={() => navigate('/ingredients/camera')}
          type="button"
        >
          <Card className="flex items-center gap-4 py-4">
            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-warm-cream text-forest-green">
              <Camera aria-hidden="true" size={24} />
            </span>
            <span className="min-w-0">
              <span className="block font-semibold text-forest-green">
                Scan Ingredients
              </span>
              <span className="block text-xs text-muted">
                Use your camera to identify ingredients
              </span>
            </span>
          </Card>
        </button>

        <button
          className="w-full text-left"
          onClick={() => navigate('/ingredients/upload')}
          type="button"
        >
          <Card className="flex items-center gap-4 py-4">
            <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-warm-cream text-forest-green">
              <ImageUp aria-hidden="true" size={24} />
            </span>
            <span className="min-w-0">
              <span className="block font-semibold text-forest-green">
                Upload Image
              </span>
              <span className="block text-xs text-muted">
                Choose an image from your gallery
              </span>
            </span>
          </Card>
        </button>
      </div>

      <div className="mt-7 grid grid-cols-3 gap-3">
        {shortcuts.map(({ icon: Icon, label, to }) => (
          <button key={to} onClick={() => navigate(to)} type="button">
            <Card className="flex h-[58px] flex-col items-center justify-center gap-1 px-1 py-2 text-center">
              <Icon aria-hidden="true" className="text-fresh-green" size={19} />
              <span className="text-[11px] font-semibold leading-tight text-forest-green">
                {label}
              </span>
            </Card>
          </button>
        ))}
      </div>

      <section className="mt-8" aria-labelledby="popular-title">
        <h2 className="text-lg font-bold" id="popular-title">
          Popular Sri Lankan Recipes
        </h2>
        <div className="mt-4 grid grid-cols-2 gap-3">
          {popularRecipes.map((recipe) => (
            <RecipeCard
              duration={recipe.duration}
              image={recipe.image}
              key={recipe.id}
              name={recipe.name}
              onSelect={() => navigate(`/recipes/${recipe.id}`)}
              rating={recipe.rating}
            />
          ))}
        </div>
      </section>

      <p className="mt-6 flex items-center justify-center gap-2 text-xs text-muted">
        <Sparkles aria-hidden="true" size={14} />
        <Clock3 aria-hidden="true" size={14} />
        Fresh ideas from the ingredients you already have
      </p>

      <BottomNavigation />
    </MobileScreen>
  )
}
