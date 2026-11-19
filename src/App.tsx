import { useCallback } from 'react'
import {
  BrowserRouter,
  Navigate,
  Route,
  Routes,
  useNavigate,
} from 'react-router-dom'
import { OnboardingScreen } from './pages/onboarding/OnboardingScreen'
import { SplashScreen } from './pages/SplashScreen'
import { TemporaryLoginDestination } from './pages/login/TemporaryLoginDestination'

function SplashRoute() {
  const navigate = useNavigate()
  const onComplete = useCallback(
    () => navigate('/onboarding', { replace: true }),
    [navigate],
  )

  return <SplashScreen onComplete={onComplete} />
}

function OnboardingRoute() {
  const navigate = useNavigate()
  const onComplete = useCallback(
    () => navigate('/login', { replace: true }),
    [navigate],
  )

  return <OnboardingScreen onComplete={onComplete} />
}

export function AppRoutes() {
  return (
    <Routes>
      <Route element={<SplashRoute />} path="/" />
      <Route element={<OnboardingRoute />} path="/onboarding" />
      <Route element={<TemporaryLoginDestination />} path="/login" />
      <Route element={<Navigate replace to="/" />} path="*" />
    </Routes>
  )
}

function App() {
  return (
    <BrowserRouter>
      <AppRoutes />
    </BrowserRouter>
  )
}

export default App
