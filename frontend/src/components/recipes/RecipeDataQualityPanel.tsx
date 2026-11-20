import { AlertTriangle } from 'lucide-react'
import { Card } from '../ui/Card'
import type { DataQualityIssueViewModel } from '../../services/mappers/recipeMappers'

export interface RecipeDataQualityPanelProps {
  issues: DataQualityIssueViewModel[]
}

function displayValue(value: string | null): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

function DetailRow({ label, value }: { label: string; value: string | null }) {
  if (!value) {
    return null
  }

  return (
    <p className="mt-1 text-xs leading-5 text-muted">
      <span className="font-bold text-ink">{label}:</span> {value}
    </p>
  )
}

export function RecipeDataQualityPanel({
  issues,
}: RecipeDataQualityPanelProps) {
  if (issues.length === 0) {
    return null
  }

  return (
    <section aria-labelledby="data-quality-title" className="mt-8">
      <h2 className="section-title text-base font-extrabold" id="data-quality-title">
        Data quality notes
      </h2>
      <p className="mt-2 text-xs leading-5 text-muted">
        Source values are shown unchanged alongside these warnings.
      </p>
      <div className="mt-3 grid gap-3">
        {issues.map((issue) => {
          const title =
            displayValue(issue.fieldName) ??
            displayValue(issue.issueType) ??
            'Data quality issue'

          return (
            <Card
              className="rounded-2xl border-chilli-red/20 bg-white/80 p-4"
              key={`${issue.id}-${title}-${displayValue(issue.description) ?? ''}`}
            >
              <div className="flex items-start gap-3">
                <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-chilli-red/10 text-chilli-red">
                  <AlertTriangle aria-hidden="true" size={14} />
                </span>
                <div className="min-w-0 flex-1">
                  <h3 className="text-sm font-bold text-ink">
                    {title}
                    {displayValue(issue.severity)
                      ? ` · ${displayValue(issue.severity)}`
                      : ''}
                  </h3>
                  <DetailRow
                    label="Entity"
                    value={displayValue(issue.entityType)}
                  />
                  <DetailRow
                    label="Reference"
                    value={displayValue(issue.entityReference)}
                  />
                  <DetailRow
                    label="Source"
                    value={
                      displayValue(issue.sourceFile)
                        ? `${issue.sourceFile}${
                            issue.sourceRow === null
                              ? ''
                              : `, row ${issue.sourceRow}`
                          }`
                        : null
                    }
                  />
                  <DetailRow
                    label="Warning"
                    value={displayValue(issue.description)}
                  />
                  <DetailRow
                    label="Recommended action"
                    value={displayValue(issue.recommendedAction)}
                  />
                  <DetailRow
                    label="Resolution"
                    value={displayValue(issue.resolutionStatus)}
                  />
                </div>
              </div>
            </Card>
          )
        })}
      </div>
    </section>
  )
}
