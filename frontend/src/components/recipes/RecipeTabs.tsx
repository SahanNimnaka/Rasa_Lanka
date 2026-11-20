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
      className="mt-6 grid grid-cols-3 border-b border-forest-green/15"
      role="tablist"
    >
      {TABS.map((tab) => {
        const isActive = tab.id === activeTab
        return (
          <button
            aria-controls={`${idPrefix}-panel`}
            aria-selected={isActive}
            className={cn(
              'min-h-11 border-b-2 px-1 text-sm font-semibold transition-colors',
              isActive
                ? 'border-spice-orange text-forest-green'
                : 'border-transparent text-muted hover:text-forest-green',
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
