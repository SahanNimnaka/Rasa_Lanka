import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Mail } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { AuthPlaceholderPanel } from '../../components/auth/AuthPlaceholderPanel'
import { PasswordInput } from '../../components/auth/PasswordInput'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { BrandLogo } from '../../components/BrandLogo'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Input'
import { loginSchema, type LoginValues } from '../../services/validation'

export function LoginScreen() {
  const navigate = useNavigate()
  const [status, setStatus] = useState('')
  const {
    formState: { errors },
    handleSubmit,
    register,
  } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
  })

  function handleValidSubmit() {
    setStatus(
      'Authentication is not connected yet, so no sign-in was attempted.',
    )
  }

  function showUnavailable(provider: string) {
    setStatus(
      `${provider} sign-in is unavailable until its OAuth provider is configured.`,
    )
  }

  return (
    <MobileScreen aria-labelledby="login-title">
      <div className="pt-6">
        <BrandLogo
          imageAlt="RasaLanka logo"
          showWordmark={false}
          size="lg"
          src="/rasalanka-logo.png"
        />
      </div>

      <section className="mt-8">
        <h1 className="text-[1.75rem] font-bold" id="login-title">
          Welcome Back
        </h1>
        <p className="mt-2 text-sm text-muted">
          Log in to discover your next meal.
        </p>

        <form
          className="mt-7 grid gap-5"
          noValidate
          onSubmit={handleSubmit(handleValidSubmit)}
        >
          <Input
            autoComplete="email"
            endAdornment={
              <Mail
                aria-hidden="true"
                className="text-forest-green/45"
                size={18}
              />
            }
            error={errors.email?.message}
            inputMode="email"
            label="Email"
            placeholder="Enter your email"
            type="email"
            {...register('email')}
          />

          <div>
            <PasswordInput
              autoComplete="current-password"
              error={errors.password?.message}
              label="Password"
              placeholder="Enter your password"
              {...register('password')}
            />
            <div className="mt-2 flex justify-end">
              <Button
                className="min-h-8 px-1 text-sm font-semibold text-fresh-green"
                onClick={() => navigate('/forgot-password')}
                size="sm"
                variant="ghost"
              >
                Forgot Password
              </Button>
            </div>
          </div>

          <Button
            className="h-[46px] w-full rounded-none text-sm"
            size="lg"
            type="submit"
          >
            Login
          </Button>
        </form>

        <p className="mt-8 text-center text-sm text-muted">or continue with</p>

        <div className="mt-4 grid gap-3">
          <Button
            className="h-[47px] w-full rounded-control"
            onClick={() => showUnavailable('Google')}
            variant="secondary"
          >
            Google sign-in
          </Button>
          <Button
            className="h-[47px] w-full rounded-control"
            onClick={() => showUnavailable('Apple')}
            variant="secondary"
          >
            Apple sign-in
          </Button>
        </div>

        <p className="mt-8 text-center text-sm text-forest-green">
          Don&apos;t have an account?{' '}
          <Button
            className="min-h-8 px-1 text-sm font-semibold text-fresh-green"
            onClick={() => navigate('/register')}
            size="sm"
            variant="ghost"
          >
            Sign up
          </Button>
        </p>

        {status && (
          <p
            aria-live="polite"
            className="mt-3 text-center text-sm text-fresh-green"
            role="status"
          >
            {status}
          </p>
        )}

        <AuthPlaceholderPanel onOpenHome={() => navigate('/home')} />
      </section>
    </MobileScreen>
  )
}
