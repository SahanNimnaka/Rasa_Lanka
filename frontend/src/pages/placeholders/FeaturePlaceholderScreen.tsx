import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { useNavigateBack } from '../../hooks/useNavigateBack'

export interface FeaturePlaceholderScreenProps {
  title: string
  description: string
}

export function FeaturePlaceholderScreen({
  description,
  title,
}: FeaturePlaceholderScreenProps) {
  const navigate = useNavigate()
  const goBack = useNavigateBack('/home')

  return (
    <MobileScreen aria-labelledby="placeholder-title">
      <ScreenHeader onBack={goBack} />
      <section className="glass-panel mt-8 rounded-3xl p-8 text-center">
        <p className="text-xs font-bold uppercase tracking-wider text-spice-orange">
          Placeholder
        </p>
        <h1 className="mt-3 text-2xl font-bold" id="placeholder-title">
          {title}
        </h1>
        <p className="mt-3 text-sm leading-6 text-muted">{description}</p>
        <Button className="mt-8 w-full" onClick={() => navigate('/home')}>
          Back to Home
        </Button>
      </section>
    </MobileScreen>
  )
}
