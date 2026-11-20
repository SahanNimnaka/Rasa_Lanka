import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { ChevronRight } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { BottomNavigation } from '../../components/navigation/BottomNavigation'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { Input } from '../../components/ui/Input'
import { useAppState } from '../../context/appStateStore'
import { profileSchema, type ProfileValues } from '../../services/validation'

const MENU_ITEMS = [
  { id: 'preferences', label: 'Cooking Preferences', to: '/preferences' },
  { id: 'saved', label: 'Saved Recipes', to: '/saved' },
  { id: 'history', label: 'History', to: '/history' },
  { id: 'settings', label: 'Settings', to: '/settings' },
  { id: 'about', label: 'About', to: '' },
] as const

export function ProfileScreen() {
  const navigate = useNavigate()
  const { profile, updateProfile } = useAppState()
  const [isEditing, setIsEditing] = useState(false)
  const [status, setStatus] = useState('')

  const {
    formState: { errors },
    handleSubmit,
    register,
    reset,
  } = useForm<ProfileValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: { fullName: profile.fullName },
  })

  function openEditor() {
    reset({ fullName: profile.fullName })
    setIsEditing(true)
    setStatus('')
  }

  function saveProfile(values: ProfileValues) {
    updateProfile(values)
    setIsEditing(false)
    setStatus('Profile updated locally.')
  }

  function handleMenuAction(to: string, label: string) {
    if (to) {
      navigate(to)
      return
    }
    setStatus(`${label} details are not included in this build.`)
  }

  return (
    <MobileScreen aria-labelledby="profile-title" className="pb-28">
      <header className="flex min-h-11 items-center" />

      <h1 className="mt-2 text-2xl font-bold" id="profile-title">
        Profile
      </h1>

      <div className="mt-8 flex flex-col items-center">
        <div
          aria-label="Profile image placeholder"
          className="flex h-[88px] w-[88px] items-center justify-center rounded-card border border-dashed border-forest-green/30 bg-warm-cream/60 px-2 text-center text-[11px] font-semibold text-muted"
          role="img"
        >
          Profile image
        </div>
        <p className="mt-5 text-xl font-bold">{profile.fullName}</p>
        <p className="mt-2 text-center text-xs leading-5 text-muted">
          Demo profile — no account is signed in.
        </p>
      </div>

      {!isEditing ? (
        <Button
          className="mx-auto mt-5 block w-[180px]"
          onClick={openEditor}
          variant="secondary"
        >
          Edit profile
        </Button>
      ) : (
        <Card className="mt-5">
          <form className="grid gap-3" onSubmit={handleSubmit(saveProfile)}>
            <Input
              autoComplete="name"
              error={errors.fullName?.message}
              label="Full name"
              {...register('fullName')}
            />
            <div className="grid grid-cols-2 gap-3">
              <Button onClick={() => setIsEditing(false)} variant="secondary">
                Cancel
              </Button>
              <Button type="submit">Save</Button>
            </div>
          </form>
        </Card>
      )}

      <Card className="mt-8 overflow-hidden p-0">
        <ul className="divide-y divide-forest-green/10">
          {MENU_ITEMS.map((item) => (
            <li key={item.id}>
              <button
                className="flex min-h-14 w-full items-center justify-between px-4 text-left text-sm font-semibold text-forest-green"
                onClick={() => handleMenuAction(item.to, item.label)}
                type="button"
              >
                {item.label}
                <ChevronRight aria-hidden="true" size={18} />
              </button>
            </li>
          ))}
        </ul>
      </Card>

      <p aria-live="polite" className="mt-4 text-center text-xs text-muted">
        {status}
      </p>

      <BottomNavigation />
    </MobileScreen>
  )
}
