import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Mail } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { AuthPlaceholderPanel } from '../../components/auth/AuthPlaceholderPanel'
import { PasswordInput } from '../../components/auth/PasswordInput'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { BrandLogo } from '../../components/BrandLogo'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Input'
import { useAuth } from '../../context/authStore'
import { toApiError } from '../../services/api'
import { loginSchema, type LoginValues } from '../../services/validation'
import { getPostAuthPath } from '../../utils/authRedirect'
import { cn } from '../../utils/cn'

export function LoginScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const { login } = useAuth()
  const [status, setStatus] = useState('')
  const [statusTone, setStatusTone] = useState<'error' | 'info'>('error')
  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
    setError,
  } = useForm<LoginValues>({
    defaultValues: { email: '', password: '' },
    resolver: zodResolver(loginSchema),
  })

  async function handleValidSubmit(values: LoginValues) {
    setStatus('')
    setStatusTone('error')

    try {
      await login({ email: values.email.trim(), password: values.password })
      navigate(getPostAuthPath(location), { replace: true })
    } catch (error) {
      const apiError = toApiError(error)
      const details = apiError.details
      let fieldErrorShown = false

      if (details?.email?.[0]) {
        setError('email', { message: details.email[0] })
        fieldErrorShown = true
      }
      if (details?.password?.[0]) {
        setError('password', { message: details.password[0] })
        fieldErrorShown = true
      }

      if (!fieldErrorShown) {
        setStatus(apiError.message)
      }
    }
  }

  function showUnavailable(provider: string) {
    setStatusTone('info')
    setStatus(
      `${provider} sign-in is unavailable until its OAuth provider is configured.`,
    )
  }

  return (
    <MobileScreen aria-labelledby="login-title">
      <div className="relative pt-6">
        <div
          aria-hidden="true"
          className="pointer-events-none absolute inset-0 overflow-hidden"
        >
          <div className="absolute -left-12 -top-12 h-40 w-40 rounded-full bg-spice-orange/12 blur-2xl" />
        </div>
        <BrandLogo
          imageAlt="RasaLanka logo"
          showWordmark={false}
          size="lg"
          src="/rasalanka-logo.png"
        />
      </div>

      <section className="mt-8 lg:mx-auto lg:max-w-2xl">
        <p className="eyebrow-label">Welcome to RasaLanka</p>
        <h1 className="mt-2 text-[1.9rem] font-extrabold" id="login-title">
          Welcome Back
        </h1>
        <p className="mt-2 text-sm text-muted">
          Log in to discover your next meal.
        </p>

        <form
          className="glass-panel mt-7 grid gap-5 rounded-2xl p-4"
          noValidate
          onSubmit={handleSubmit(handleValidSubmit)}
        >
          <Input
            autoComplete="email"
            endAdornment={
              <Mail aria-hidden="true" className="text-ink/45" size={18} />
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
            disabled={isSubmitting}
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

        <p className="mt-8 text-center text-sm text-ink">
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

        {(status || isSubmitting) && (
          <p
            aria-live="polite"
            className={cn(
              'mt-3 text-center text-sm',
              isSubmitting
                ? 'text-muted'
                : statusTone === 'error'
                  ? 'text-chilli-red'
                  : 'text-fresh-green',
            )}
            role="status"
          >
            {isSubmitting ? 'Signing in…' : status}
          </p>
        )}

        <AuthPlaceholderPanel onOpenHome={() => navigate('/home')} />
      </section>
    </MobileScreen>
  )
}
