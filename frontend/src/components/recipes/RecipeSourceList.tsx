import { ExternalLink, FileText } from 'lucide-react'
import { Card } from '../ui/Card'
import type { RecipeSourceViewModel } from '../../services/mappers/recipeMappers'

export interface RecipeSourceListProps {
  sources: RecipeSourceViewModel[]
}

function displayValue(value: string | null): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

function SourceRow({ label, value }: { label: string; value: string | null }) {
  if (!value) {
    return null
  }

  return (
    <p className="mt-1 text-xs leading-5 text-muted">
      <span className="font-bold text-ink">{label}:</span> {value}
    </p>
  )
}

export function RecipeSourceList({ sources }: RecipeSourceListProps) {
  if (sources.length === 0) {
    return (
      <Card className="text-sm text-muted">
        No recipe sources are provided.
      </Card>
    )
  }

  return (
    <div className="grid gap-3">
      {sources.map((source) => {
        const label =
          displayValue(source.sourceName) ??
          displayValue(source.sourceIdentifier) ??
          'Untitled source'
        const uri = displayValue(source.sourceUri)

        return (
          <Card
            className="rounded-2xl p-4"
            key={`${source.id}-${label}-${uri ?? ''}`}
          >
            <div className="flex items-start gap-3">
              <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-forest-green/8 text-fresh-green">
                <FileText aria-hidden="true" size={15} />
              </span>
              <div className="min-w-0 flex-1">
                {uri ? (
                  <a
                    className="inline-flex items-center gap-1 text-sm font-bold text-ink underline decoration-forest-green/30 underline-offset-2 hover:text-fresh-green"
                    href={uri}
                    rel="noreferrer"
                    target="_blank"
                  >
                    {label}
                    <ExternalLink aria-hidden="true" size={13} />
                  </a>
                ) : (
                  <h3 className="text-sm font-bold text-ink">{label}</h3>
                )}
                <SourceRow
                  label="Identifier"
                  value={displayValue(source.sourceIdentifier)}
                />
                <SourceRow
                  label="Record"
                  value={displayValue(source.sourceRecordIdentifier)}
                />
                <SourceRow
                  label="Location"
                  value={displayValue(source.sourceLocation)}
                />
                <SourceRow
                  label="Attribution"
                  value={displayValue(source.attribution)}
                />
                <SourceRow label="Notes" value={displayValue(source.notes)} />
              </div>
            </div>
          </Card>
        )
      })}
    </div>
  )
}
