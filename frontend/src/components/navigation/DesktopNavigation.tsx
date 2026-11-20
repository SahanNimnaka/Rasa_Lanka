import { useSyncExternalStore } from 'react'
import { Bookmark, House, Search, UserRound } from 'lucide-react'
import { NavLink, useLocation } from 'react-router-dom'
import { BrandLogo } from '../BrandLogo'
import { cn } from '../../utils/cn'

const items = [
  { label: 'Home', to: '/home', icon: House },
  { label: 'Search', to: '/search', icon: Search },
  { label: 'Saved Recipes', to: '/saved', icon: Bookmark },
  { label: 'Profile', to: '/profile', icon: UserRound },
] as const

function subscribeToDesktopViewport(onChange: () => void) {
  if (!window.matchMedia) {
    return () => {}
  }

  const mediaQuery = window.matchMedia('(min-width: 768px)')
  mediaQuery.addEventListener('change', onChange)
  return () => mediaQuery.removeEventListener('change', onChange)
}

function isDesktopViewport() {
  return window.matchMedia?.('(min-width: 768px)').matches ?? false
}

export function DesktopNavigation() {
  const { pathname } = useLocation()
  const isDesktop = useSyncExternalStore(
    subscribeToDesktopViewport,
    isDesktopViewport,
    () => false,
  )

  if (!isDesktop) {
    return null
  }

  return (
    <header className="desktop-nav relative z-20 hidden border-b border-forest-green/10 md:block">
      <div className="mx-auto flex min-h-20 max-w-[1600px] items-center justify-between gap-8 px-6 lg:px-12">
        <BrandLogo
          imageAlt="RasaLanka logo"
          size="md"
          src="/rasalanka-logo.png"
        />
        <nav aria-label="Primary navigation">
          <ul className="flex items-center gap-2">
            {items.map(({ icon: Icon, label, to }) => {
              const isActive =
                pathname === to ||
                (to === '/search' &&
                  (pathname.startsWith('/recipes/') ||
                    pathname.startsWith('/cooking/') ||
                    pathname.startsWith('/ingredients/'))) ||
                (to === '/profile' &&
                  ['/preferences', '/settings', '/history', '/about'].includes(
                    pathname,
                  ))

              return (
                <li key={to}>
                  <NavLink
                    aria-current={isActive ? 'page' : undefined}
                    className={cn(
                      'desktop-nav-link inline-flex min-h-11 items-center gap-2 rounded-xl px-4 text-sm font-semibold transition-colors',
                      isActive && 'font-bold',
                    )}
                    to={to}
                  >
                    <Icon aria-hidden="true" size={18} strokeWidth={1.8} />
                    {label}
                  </NavLink>
                </li>
              )
            })}
          </ul>
        </nav>
      </div>
    </header>
  )
}
