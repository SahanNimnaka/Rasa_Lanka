import { use, useCallback, useEffect, useRef, type ReactNode } from 'react'
import { QueryClientProvider } from '@tanstack/react-query'
import {
  BrowserRouter,
  Navigate,
  Route,
  Routes,
  useLocation,
  useNavigate,
  useParams,
} from 'react-router-dom'
import { AppStateProvider } from './context/AppStateContext'
import { AuthProvider } from './context/AuthProvider'
import { CookingFlowProvider } from './context/CookingFlowContext'
import { AuthContext } from './context/authStore'
import { ProtectedRoute } from './components/auth/ProtectedRoute'
import { CookingGuidanceScreen } from './pages/cooking/CookingGuidanceScreen'
import { CookingInstructionsScreen } from './pages/cooking/CookingInstructionsScreen'
import { OnboardingScreen } from './pages/onboarding/OnboardingScreen'
import { SplashScreen } from './pages/SplashScreen'
import { LoginScreen } from './pages/auth/LoginScreen'
import { RegisterScreen } from './pages/auth/RegisterScreen'
import { HomeScreen } from './pages/home/HomeScreen'
import { CameraScreen } from './pages/ingredients/CameraScreen'
import { UploadImageScreen } from './pages/ingredients/UploadImageScreen'
import { IngredientRecognitionScreen } from './pages/ingredients/IngredientRecognitionScreen'
import { IngredientQuantitiesScreen } from './pages/ingredients/IngredientQuantitiesScreen'
import { ServingSizeScreen } from './pages/ingredients/ServingSizeScreen'
import { RecipeRecommendationsScreen } from './pages/recipes/RecipeRecommendationsScreen'
import { RecipeDetailsScreen } from './pages/recipes/RecipeDetailsScreen'
import { SavedRecipesScreen } from './pages/recipes/SavedRecipesScreen'
import { SearchScreen } from './pages/recipes/SearchScreen'
import { HistoryScreen } from './pages/history/HistoryScreen'
import { ProfileScreen } from './pages/profile/ProfileScreen'
import { CookingPreferencesScreen } from './pages/preferences/CookingPreferencesScreen'
import { SettingsScreen } from './pages/settings/SettingsScreen'
import { AboutScreen } from './pages/about/AboutScreen'
import { FeaturePlaceholderScreen } from './pages/placeholders/FeaturePlaceholderScreen'
import { useRecipe, useRecordRecipeView } from './hooks/queries'
import { queryClient } from './lib/queryClient'
import { parseApiId } from './services/queries/queryKeys'

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

function IngredientRecognitionRoute() {
  const location = useLocation()

  return <IngredientRecognitionScreen key={location.key} />
}

function RecipeViewRoute({ children }: { children: ReactNode }) {
  const { recipeId } = useParams()
  const auth = use(AuthContext)
  const numericId = parseApiId(recipeId)
  const recipeQuery = useRecipe(recipeId)
  const { mutate: recordView } = useRecordRecipeView()
  const recordedForRef = useRef<string | null>(null)

  useEffect(() => {
    if (
      !auth?.isAuthenticated ||
      numericId === null ||
      !recipeQuery.isSuccess
    ) {
      return
    }

    const resolvedId = recipeQuery.data.id
    if (
      resolvedId !== String(numericId) ||
      recordedForRef.current === resolvedId
    ) {
      return
    }

    recordedForRef.current = resolvedId
    recordView(numericId)
  }, [
    auth?.isAuthenticated,
    numericId,
    recordView,
    recipeQuery.data?.id,
    recipeQuery.isSuccess,
  ])

  return <>{children}</>
}

function AppRouteViews() {
  return (
    <Routes>
      <Route element={<SplashRoute />} path="/" />
      <Route element={<OnboardingRoute />} path="/onboarding" />
      <Route element={<LoginScreen />} path="/login" />
      <Route element={<RegisterScreen />} path="/register" />
      <Route element={<HomeScreen />} path="/home" />

      <Route element={<CameraScreen />} path="/ingredients/camera" />
      <Route element={<UploadImageScreen />} path="/ingredients/upload" />
      <Route
        element={<IngredientRecognitionRoute />}
        path="/ingredients/recognition"
      />
      <Route
        element={<IngredientQuantitiesScreen />}
        path="/ingredients/quantities"
      />
      <Route element={<ServingSizeScreen />} path="/ingredients/serving-size" />

      <Route
        element={<RecipeRecommendationsScreen />}
        path="/recipes/recommendations"
      />
      <Route
        element={
          <RecipeViewRoute>
            <RecipeDetailsScreen />
          </RecipeViewRoute>
        }
        path="/recipes/:recipeId"
      />
      <Route
        element={
          <RecipeViewRoute>
            <CookingGuidanceScreen />
          </RecipeViewRoute>
        }
        path="/cooking/:recipeId/guidance"
      />
      <Route
        element={<CookingInstructionsScreen />}
        path="/cooking/:recipeId"
      />

      <Route element={<SearchScreen />} path="/search" />
      <Route
        element={
          <ProtectedRoute>
            <SavedRecipesScreen />
          </ProtectedRoute>
        }
        path="/saved"
      />
      <Route
        element={
          <ProtectedRoute>
            <ProfileScreen />
          </ProtectedRoute>
        }
        path="/profile"
      />
      <Route
        element={
          <ProtectedRoute>
            <HistoryScreen />
          </ProtectedRoute>
        }
        path="/history"
      />
      <Route
        element={
          <ProtectedRoute>
            <CookingPreferencesScreen />
          </ProtectedRoute>
        }
        path="/preferences"
      />
      <Route
        element={
          <ProtectedRoute>
            <SettingsScreen />
          </ProtectedRoute>
        }
        path="/settings"
      />
      <Route element={<AboutScreen />} path="/about" />
      <Route
        element={
          <FeaturePlaceholderScreen
            description="Notifications are not connected to a backend yet."
            title="Notifications"
          />
        }
        path="/notifications"
      />
      <Route
        element={
          <FeaturePlaceholderScreen
            description="Password recovery is not available yet."
            title="Forgot Password"
          />
        }
        path="/forgot-password"
      />

      <Route element={<Navigate replace to="/" />} path="*" />
    </Routes>
  )
}

export function AppRoutes() {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <CookingFlowProvider>
          <AppStateProvider>
            <AppRouteViews />
          </AppStateProvider>
        </CookingFlowProvider>
      </AuthProvider>
    </QueryClientProvider>
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
