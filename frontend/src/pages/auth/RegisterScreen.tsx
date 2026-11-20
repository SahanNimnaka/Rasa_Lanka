import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Mail, UserRound } from 'lucide-react'
import { useLocation, useNavigate } from 'react-router-dom'
import { PasswordInput } from '../../components/auth/PasswordInput'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Input'
import { useAuth } from '../../context/authStore'
import { toApiError } from '../../services/api'
import {
  PASSWORD_REQUIREMENT,
  registerSchema,
  type RegisterValues,
} from '../../services/validation'
import { getPostAuthPath } from '../../utils/authRedirect'
import { cn } from '../../utils/cn'

export function RegisterScreen() {
  const navigate = useNavigate()
  const location = useLocation()
  const goBack = useNavigateBack('/login')
  const { register: registerUser } = useAuth()
  const [status, setStatus] = useState('')
  const [statusTone, setStatusTone] = useState<'error' | 'info'>('error')
  const {
    formState: { errors, isSubmitting },
    handleSubmit,
    register,
    setError,
  } = useForm<RegisterValues>({
    defaultValues: {
      fullName: '',
      email: '',
      password: '',
      confirmPassword: '',
      termsAccepted: false,
    },
    resolver: zodResolver(registerSchema),
  })

  async function handleValidSubmit(values: RegisterValues) {
    setStatus('')
    setStatusTone('error')

    try {
      await registerUser({
        name: values.fullName.trim(),
        email: values.email.trim(),
        password: values.password,
        password_confirmation: values.confirmPassword,
      })
      navigate(getPostAuthPath(location), { replace: true })
    } catch (error) {
      const apiError = toApiError(error)
      const details = apiError.details
      const fieldMap = {
        name: 'fullName',
        email: 'email',
        password: 'password',
        password_confirmation: 'confirmPassword',
      } as const
      let fieldErrorShown = false

      for (const [backendField, formField] of Object.entries(fieldMap)) {
        const message = details?.[backendField]?.[0]
        if (message) {
          setError(formField as keyof RegisterValues, { message })
          fieldErrorShown = true
        }
      }

      if (!fieldErrorShown) {
        setStatus(apiError.message)
      }
    }
  }

  return (
    <MobileScreen aria-labelledby="register-title">
      <ScreenHeader onBack={goBack} />

      <section className="mt-3 lg:mx-auto lg:max-w-2xl">
        <p className="eyebrow-label">Start your flavour journey</p>
        <h1 className="mt-2 text-[1.9rem] font-extrabold" id="register-title">
          Create Account
        </h1>
        <p className="mt-2 text-sm text-muted">
          Start cooking with what you have.
        </p>

        <form
          className="glass-panel mt-8 grid gap-4 rounded-2xl p-4"
          noValidate
          onSubmit={handleSubmit(handleValidSubmit)}
        >
          <Input
            autoComplete="name"
            endAdornment={
              <UserRound aria-hidden="true" className="text-ink/45" size={18} />
            }
            error={errors.fullName?.message}
            label="Full Name"
            placeholder="Enter your full name"
            {...register('fullName')}
          />

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

          <PasswordInput
            autoComplete="new-password"
            error={errors.password?.message}
            hint={PASSWORD_REQUIREMENT}
            label="Password"
            placeholder="Create a password"
            {...register('password')}
          />

          <PasswordInput
            autoComplete="new-password"
            error={errors.confirmPassword?.message}
            label="Confirm Password"
            placeholder="Re-enter your password"
            {...register('confirmPassword')}
          />

          <div>
            <label className="flex items-start gap-3 text-sm text-ink">
              <input
                aria-describedby={
                  errors.termsAccepted ? 'terms-error' : undefined
                }
                aria-invalid={errors.termsAccepted ? true : undefined}
                className="mt-0.5 h-5 w-5 shrink-0 accent-fresh-green"
                type="checkbox"
                {...register('termsAccepted')}
              />
              <span>I agree to the Terms and Privacy Policy.</span>
            </label>
            {errors.termsAccepted?.message && (
              <p
                className="mt-2 text-sm font-medium text-chilli-red"
                id="terms-error"
                role="alert"
              >
                {errors.termsAccepted.message}
              </p>
            )}
          </div>

          <Button
            className="h-[46px] w-full rounded-none text-sm"
            disabled={isSubmitting}
            size="lg"
            type="submit"
          >
            Sign up
          </Button>
        </form>

        <p className="mt-7 text-center text-sm text-ink">
          Already have an account?{' '}
          <Button
            className="min-h-8 px-1 text-sm font-semibold text-fresh-green"
            onClick={() => navigate('/login')}
            size="sm"
            variant="ghost"
          >
            Login
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
            {isSubmitting ? 'Creating account…' : status}
          </p>
        )}
      </section>
    </MobileScreen>
  )
}
