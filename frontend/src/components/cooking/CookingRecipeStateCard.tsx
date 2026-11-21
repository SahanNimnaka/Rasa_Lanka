import type { ReactNode } from 'react'
import { MobileScreen } from '../layout/MobileScreen'
import { ScreenHeader } from '../layout/ScreenHeader'
import { Card } from '../ui/Card'

export interface CookingRecipeStateCardProps {
  action?: ReactNode
  message: string
  onBack: () => void
  title: string
}

export function CookingRecipeStateCard({
  action,
  message,
  onBack,
  title,
}: CookingRecipeStateCardProps) {
  return (
    <MobileScreen aria-labelledby="cooking-recipe-state-title">
      <ScreenHeader onBack={onBack} />
      <Card className="mt-8 text-center">
        <h1 className="text-xl font-extrabold" id="cooking-recipe-state-title">
          {title}
        </h1>
        <p className="mt-2 text-sm leading-6 text-muted">{message}</p>
        {action}
      </Card>
    </MobileScreen>
  )
}
