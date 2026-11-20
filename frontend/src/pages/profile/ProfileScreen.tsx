import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import {
  Bookmark,
  ChevronRight,
  Clock3,
  Info,
  LogOut,
  Settings as SettingsIcon,
  SlidersHorizontal,
} from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { BottomNavigation } from '../../components/navigation/BottomNavigation'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { Input } from '../../components/ui/Input'
import { WireframeImagePlaceholder } from '../../components/ui/WireframeImagePlaceholder'
import { useAppState } from '../../context/appStateStore'
import { useAuth } from '../../context/authStore'
import { profileSchema, type ProfileValues } from '../../services/validation'

const MENU_ITEMS = [
  {
    id: 'preferences',
    label: 'Cooking Preferences',
    to: '/preferences',
    icon: SlidersHorizontal,
  },
  { id: 'saved', label: 'Saved Recipes', to: '/saved', icon: Bookmark },
  { id: 'history', label: 'History', to: '/history', icon: Clock3 },
  { id: 'settings', label: 'Settings', to: '/settings', icon: SettingsIcon },
  { id: 'about', label: 'About', to: '/about', icon: Info },
] as const

export function ProfileScreen() {
  const navigate = useNavigate()
  const { profile, updateProfile } = useAppState()
  const { user, logout } = useAuth()
  const [isEditing, setIsEditing] = useState(false)
  const [isLoggingOut, setIsLoggingOut] = useState(false)
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

  async function handleSignOut() {
    setStatus('')
    setIsLoggingOut(true)

    try {
      await logout()
      navigate('/login', { replace: true })
    } catch {
      setStatus('Could not sign out. Please try again.')
    } finally {
      setIsLoggingOut(false)
    }
  }

  return (
    <MobileScreen aria-labelledby="profile-title" className="pb-28">
      <header className="flex min-h-11 items-center" />

      <p className="eyebrow-label mt-2">Your kitchen space</p>
      <h1
        className="mt-1 text-[28px] leading-tight font-extrabold"
        id="profile-title"
      >
        Profile
      </h1>

      <div className="mt-6 grid gap-8 lg:grid-cols-[minmax(280px,0.8fr)_minmax(0,1.2fr)] lg:items-start">
        <div>
          <div className="hero-card flex flex-col items-center rounded-3xl p-6 text-center md:p-8">
            <div className="logo-glow rounded-full bg-forest-green/8 p-1.5 backdrop-blur-sm">
              <WireframeImagePlaceholder
                accessibleLabel="Profile image placeholder"
                className="h-[104px] w-[104px] border-forest-green/15 bg-white text-muted"
                label="Profile image"
              />
            </div>
            <p className="mt-5 text-xl font-extrabold">
              {user?.name ?? profile.fullName}
            </p>
            <p className="mt-1 text-xs font-semibold text-muted">
              {user?.email ?? 'Local demo profile'}
            </p>
            {user ? (
              <p className="sr-only">Signed in as {user.email}.</p>
            ) : (
              <p className="sr-only">Demo profile — no account is signed in.</p>
            )}
          </div>

          {!isEditing ? (
            <Button
              aria-label="Edit profile"
              className="mx-auto mt-5 block w-[180px] shadow-md"
              onClick={openEditor}
              variant="secondary"
            >
              Edit Profile
            </Button>
          ) : (
            <Card className="mt-5 rounded-lg border-progress-track/50 p-4 shadow-none">
              <form className="grid gap-3" onSubmit={handleSubmit(saveProfile)}>
                <Input
                  autoComplete="name"
                  error={errors.fullName?.message}
                  label="Full name"
                  {...register('fullName')}
                />
                <div className="grid grid-cols-2 gap-3">
                  <Button
                    onClick={() => setIsEditing(false)}
                    variant="secondary"
                  >
                    Cancel
                  </Button>
                  <Button type="submit">Save</Button>
                </div>
              </form>
            </Card>
          )}
        </div>
        <div className="glass-panel overflow-hidden rounded-2xl">
          <ul className="divide-y divide-forest-green/10">
            {MENU_ITEMS.map((item) => {
              const Icon = item.icon
              return (
                <li key={item.id}>
                  <button
                    className="flex min-h-14 w-full items-center justify-between px-4 text-left text-sm font-bold text-ink transition-colors hover:bg-warm-cream/70"
                    onClick={() => navigate(item.to)}
                    type="button"
                  >
                    <span className="flex items-center gap-3">
                      <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-warm-cream text-fresh-green">
                        <Icon aria-hidden="true" size={17} />
                      </span>
                      {item.label}
                    </span>
                    <ChevronRight
                      aria-hidden="true"
                      className="text-spice-orange"
                      size={18}
                    />
                  </button>
                </li>
              )
            })}
            <li>
              <button
                className="flex min-h-14 w-full items-center justify-between px-4 text-left text-sm font-bold text-chilli-red transition-colors hover:bg-chilli-red/5 disabled:opacity-50"
                disabled={isLoggingOut}
                onClick={handleSignOut}
                type="button"
              >
                <span className="flex items-center gap-3">
                  <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-chilli-red/10 text-chilli-red">
                    <LogOut aria-hidden="true" size={17} />
                  </span>
                  {isLoggingOut ? 'Signing out…' : 'Sign out'}
                </span>
              </button>
            </li>
          </ul>
        </div>
      </div>

      <p aria-live="polite" className="sr-only">
        {status}
      </p>

      <BottomNavigation />
    </MobileScreen>
  )
}
