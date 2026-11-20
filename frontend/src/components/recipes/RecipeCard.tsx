import { Bookmark, Clock, Heart, Star, UtensilsCrossed } from 'lucide-react'
import { cn } from '../../utils/cn'
import { Card } from '../ui/Card'

export interface RecipeCardProps {
  name: string
  image?: string | null
  rating?: number | string | null
  duration?: string | null
  meta?: string | null
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
  meta,
  name,
  onSelect,
  onToggleFavorite,
  rating,
  showSavedIndicator = false,
}: RecipeCardProps) {
  const isDetailed = matchPercent !== undefined

  return (
    <div className="group relative w-full">
      <button className="w-full text-left" onClick={onSelect} type="button">
        <Card className="overflow-hidden p-0 transition-all duration-300 group-hover:-translate-y-1 group-hover:shadow-elevated">
          <div className="relative overflow-hidden">
            {image ? (
              <img
                alt={name}
                className={cn(
                  'block w-full bg-warm-cream object-cover transition-transform duration-500 group-hover:scale-105',
                  isDetailed
                    ? 'h-[140px] md:h-[190px] xl:h-[230px]'
                    : 'h-[100px] md:h-[150px] xl:h-[190px]',
                )}
                height={isDetailed ? 140 : 100}
                loading="lazy"
                src={image}
                width={340}
              />
            ) : (
              <div
                aria-hidden="true"
                className={cn(
                  'flex w-full items-center justify-center bg-warm-cream text-forest-green/25',
                  isDetailed
                    ? 'h-[140px] md:h-[190px] xl:h-[230px]'
                    : 'h-[100px] md:h-[150px] xl:h-[190px]',
                )}
              >
                <UtensilsCrossed size={34} />
              </div>
            )}
            <div
              aria-hidden="true"
              className="absolute inset-0 bg-gradient-to-t from-forest-green/45 via-transparent to-transparent"
            />
            {isDetailed && (
              <span className="absolute left-3 top-3 z-10 rounded-full bg-spice-orange px-2.5 py-1 text-[10px] font-extrabold text-white shadow-lg">
                {matchPercent}%
                <span className="sr-only"> ingredient match</span>
              </span>
            )}
          </div>
          <div className="p-3">
            <h3 className="truncate text-sm font-bold text-ink">{name}</h3>
            {(rating !== undefined && rating !== null) ||
            duration ||
            meta ? (
              <p className="mt-1.5 flex items-center gap-1 text-xs text-muted">
                {rating !== undefined && rating !== null && (
                  <>
                    <Star
                      aria-hidden="true"
                      className="fill-spice-orange text-spice-orange"
                      size={13}
                    />
                    <span>{rating}</span>
                    <span aria-hidden="true">·</span>
                  </>
                )}
                {meta && <span className="truncate">{meta}</span>}
                {meta && duration && <span aria-hidden="true">·</span>}
                {duration && (
                  <>
                    <Clock aria-hidden="true" size={13} />
                    <span>{duration}</span>
                  </>
                )}
              </p>
            ) : null}
            {showSavedIndicator && (
              <p className="mt-2 flex items-center gap-1 text-xs font-bold text-fresh-green">
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
          className="absolute right-3 top-3 z-10 flex h-9 w-9 items-center justify-center rounded-full border border-white/70 bg-white/95 text-chilli-red shadow-lg backdrop-blur-sm transition-transform hover:scale-105"
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
