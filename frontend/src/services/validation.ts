import { z } from 'zod'

export const loginSchema = z.object({
  email: z
    .string()
    .trim()
    .min(1, 'Email is required')
    .email('Enter a valid email address'),
  password: z.string().min(1, 'Password is required'),
})

export type LoginValues = z.infer<typeof loginSchema>

export const PASSWORD_REQUIREMENT =
  'Use at least 8 characters with a letter and a number.'

export const registerSchema = z
  .object({
    fullName: z
      .string()
      .trim()
      .min(2, 'Enter your full name')
      .max(80, 'Full name is too long'),
    email: z
      .string()
      .trim()
      .min(1, 'Email is required')
      .email('Enter a valid email address'),
    password: z
      .string()
      .min(8, 'Use at least 8 characters')
      .regex(/[A-Za-z]/, PASSWORD_REQUIREMENT)
      .regex(/\d/, PASSWORD_REQUIREMENT),
    confirmPassword: z.string().min(1, 'Re-enter your password'),
    termsAccepted: z.boolean().refine((accepted) => accepted, {
      message: 'Accept the Terms and Privacy Policy to continue',
    }),
  })
  .refine((values) => values.password === values.confirmPassword, {
    message: 'Passwords must match',
    path: ['confirmPassword'],
  })

export type RegisterValues = z.infer<typeof registerSchema>

export const profileSchema = z.object({
  fullName: z
    .string()
    .trim()
    .min(2, 'Enter your full name')
    .max(80, 'Full name is too long'),
})

export type ProfileValues = z.infer<typeof profileSchema>
