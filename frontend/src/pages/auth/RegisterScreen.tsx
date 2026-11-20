import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Mail, UserRound } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { PasswordInput } from '../../components/auth/PasswordInput'
import { useNavigateBack } from '../../hooks/useNavigateBack'
import { MobileScreen } from '../../components/layout/MobileScreen'
import { ScreenHeader } from '../../components/layout/ScreenHeader'
import { Button } from '../../components/ui/Button'
import { Input } from '../../components/ui/Input'
import {
  PASSWORD_REQUIREMENT,
  registerSchema,
  type RegisterValues,
} from '../../services/validation'

export function RegisterScreen() {
  const navigate = useNavigate()
  const goBack = useNavigateBack('/login')
  const [status, setStatus] = useState('')
  const {
    formState: { errors },
    handleSubmit,
    register,
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

  function handleValidSubmit() {
    setStatus(
      'Account creation is not connected yet, so no account was created.',
    )
  }

  return (
    <MobileScreen aria-labelledby="register-title">
      <ScreenHeader onBack={goBack} />

      <section className="mt-3">
        <h1 className="text-[1.75rem] font-bold" id="register-title">
          Create Account
        </h1>
        <p className="mt-2 text-sm text-muted">
          Start cooking with what you have.
        </p>

        <form
          className="mt-8 grid gap-4"
          noValidate
          onSubmit={handleSubmit(handleValidSubmit)}
        >
          <Input
            autoComplete="name"
            endAdornment={
              <UserRound
                aria-hidden="true"
                className="text-forest-green/45"
                size={18}
              />
            }
            error={errors.fullName?.message}
            label="Full Name"
            placeholder="Enter your full name"
            {...register('fullName')}
          />

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
            <label className="flex items-start gap-3 text-sm text-forest-green">
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
            size="lg"
            type="submit"
          >
            Sign up
          </Button>
        </form>

        <p className="mt-7 text-center text-sm text-forest-green">
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

        {status && (
          <p
            aria-live="polite"
            className="mt-3 text-center text-sm text-fresh-green"
            role="status"
          >
            {status}
          </p>
        )}
      </section>
    </MobileScreen>
  )
}
