import { Bookmark, Clock, Heart, Star } from 'lucide-react'
import { cn } from '../../utils/cn'
import { Card } from '../ui/Card'

export interface RecipeCardProps {
  name: string
  image: string
  rating: number | string
  duration: string
  onSelect: () => void
  matchPercent?: number
  isFavorite?: boolean
  onToggleFavorite?: () => void
  showSavedIndicator?: boolean
}

export function RecipeCard({
  duration,
  image,
  isFavorite = false,
  matchPercent,
  name,
  onSelect,
  onToggleFavorite,
  rating,
  showSavedIndicator = false,
}: RecipeCardProps) {
  const isDetailed = matchPercent !== undefined

  return (
    <div className="relative w-full">
      <button className="w-full text-left" onClick={onSelect} type="button">
        <Card className="overflow-hidden p-0 transition-shadow hover:shadow-elevated">
          <img
            alt={name}
            className={cn(
              'block w-full bg-warm-cream object-cover',
              isDetailed ? 'h-[130px]' : 'h-[92px]',
            )}
            height={isDetailed ? 130 : 92}
            loading="lazy"
            src={image}
            width={340}
          />
          <div className="p-3">
            <h3 className="truncate text-sm font-semibold text-forest-green">
              {name}
            </h3>
            <p className="mt-1 flex items-center gap-1 text-xs text-muted">
              <Star
                aria-hidden="true"
                className="fill-spice-orange text-spice-orange"
                size={13}
              />
              <span>{rating}</span>
              <span aria-hidden="true">·</span>
              <Clock aria-hidden="true" size={13} />
              <span>{duration}</span>
            </p>
            {isDetailed && (
              <p className="mt-2 text-xs text-muted">
                <span className="font-bold text-fresh-green">
                  {matchPercent}%
                </span>{' '}
                ingredient match
              </p>
            )}
            {showSavedIndicator && (
              <p className="mt-2 flex items-center gap-1 text-xs font-semibold text-fresh-green">
                <Bookmark aria-hidden="true" size={13} />
                Saved
              </p>
            )}
          </div>
        </Card>
      </button>

      {onToggleFavorite && (
        <button
          aria-label={
            isFavorite
              ? `Remove ${name} from favourites`
              : `Add ${name} to favourites`
          }
          aria-pressed={isFavorite}
          className="absolute right-3 top-3 z-10 flex h-9 w-9 items-center justify-center rounded-full bg-white/95 text-chilli-red shadow-card transition-colors hover:bg-warm-cream"
          onClick={onToggleFavorite}
          type="button"
        >
          <Heart
            aria-hidden="true"
            className={cn(isFavorite && 'fill-chilli-red')}
            size={18}
          />
        </button>
      )}
    </div>
  )
}
