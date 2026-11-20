import { VideoOff } from 'lucide-react'
import { Card } from '../ui/Card'

export interface VideoUnavailableProps {
  recipeName: string
}

export function VideoUnavailable({ recipeName }: VideoUnavailableProps) {
  return (
    <Card className="mt-5 flex flex-col items-center px-4 py-10 text-center">
      <span className="flex h-14 w-14 items-center justify-center rounded-full bg-warm-cream text-fresh-green">
        <VideoOff aria-hidden="true" size={26} />
      </span>
      <h3 className="mt-4 text-base font-bold">Video unavailable</h3>
      <p className="mt-2 max-w-[280px] text-sm leading-6 text-muted">
        No video asset is provided for {recipeName}. Written instructions are
        available in the Instructions tab.
      </p>
    </Card>
  )
}
