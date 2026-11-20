import { Bookmark, House, Search, UserRound } from 'lucide-react'
import { NavLink } from 'react-router-dom'
import { cn } from '../../utils/cn'

const items = [
  { label: 'Home', to: '/home', icon: House },
  { label: 'Search', to: '/search', icon: Search },
  { label: 'Saved', to: '/saved', icon: Bookmark },
  { label: 'Profile', to: '/profile', icon: UserRound },
] as const

export function BottomNavigation() {
  return (
    <nav
      aria-label="Primary navigation"
      className="bottom-nav fixed inset-x-0 bottom-0 z-10 mx-auto w-full max-w-[430px] px-2 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] md:hidden"
    >
      <ul className="grid grid-cols-4">
        {items.map(({ icon: Icon, label, to }) => (
          <li key={to}>
            <NavLink
              className={({ isActive }) =>
                cn(
                  'bottom-nav-link flex min-h-12 flex-col items-center justify-center gap-1 px-1 text-[11px] font-semibold',
                  isActive && 'font-bold',
                )
              }
              to={to}
            >
              <Icon aria-hidden="true" size={20} strokeWidth={1.8} />
              <span>{label}</span>
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}
