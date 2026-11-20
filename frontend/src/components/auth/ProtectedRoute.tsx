import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { LoadingIndicator } from '../ui/LoadingIndicator'
import { MobileScreen } from '../layout/MobileScreen'
import { useAuth } from '../../context/authStore'

export interface ProtectedRouteProps {
  children: ReactNode
}

export function ProtectedRoute({ children }: ProtectedRouteProps) {
  const { status } = useAuth()
  const location = useLocation()

  if (status === 'loading') {
    return (
      <MobileScreen>
        <div className="flex min-h-[60vh] items-center justify-center">
          <LoadingIndicator label="Checking your session..." />
        </div>
      </MobileScreen>
    )
  }

  if (status === 'unauthenticated') {
    return <Navigate replace state={{ from: location }} to="/login" />
  }

  return <>{children}</>
}
