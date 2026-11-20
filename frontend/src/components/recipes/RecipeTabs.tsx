import type { RecipeTab } from '../../types/recipe'
import { cn } from '../../utils/cn'

export interface RecipeTabsProps {
  activeTab: RecipeTab
  idPrefix: string
  onChange: (tab: RecipeTab) => void
}

const TABS: Array<{ id: RecipeTab; label: string }> = [
  { id: 'ingredients', label: 'Ingredients' },
  { id: 'instructions', label: 'Instructions' },
  { id: 'video', label: 'Video' },
]

export function RecipeTabs({ activeTab, idPrefix, onChange }: RecipeTabsProps) {
  return (
    <div
      aria-label="Recipe sections"
      className="mt-6 grid grid-cols-3 gap-1 rounded-2xl bg-warm-cream/70 p-1"
      role="tablist"
    >
      {TABS.map((tab) => {
        const isActive = tab.id === activeTab
        return (
          <button
            aria-controls={`${idPrefix}-panel`}
            aria-selected={isActive}
            className={cn(
              'min-h-10 rounded-xl px-1 text-sm font-bold transition-all duration-200',
              isActive
                ? 'bg-gradient-to-r from-[#2E7D32] to-[#0B3D2E] text-white shadow-md'
                : 'text-muted hover:bg-white/70 hover:text-ink',
            )}
            id={`${idPrefix}-tab-${tab.id}`}
            key={tab.id}
            onClick={() => onChange(tab.id)}
            role="tab"
            tabIndex={isActive ? 0 : -1}
            type="button"
          >
            {tab.label}
          </button>
        )
      })}
    </div>
  )
}
