import cameraIllustration from '../../assets/onboarding/identify-ingredients.svg'
import recipeIllustration from '../../assets/onboarding/recipe-recommendations.svg'
import servingsIllustration from '../../assets/onboarding/ingredients-servings.svg'

export interface OnboardingStepData {
  id: string
  title: string
  description: string
  image: string
  imageAlt: string
  actionLabel: 'Next' | 'Get Started'
  canSkip: boolean
}

export const onboardingSteps: OnboardingStepData[] = [
  {
    id: 'identify-ingredients',
    title: 'Identify Ingredients',
    description:
      'Point your camera at ingredients. AI helps identify what you have for your next meal.',
    image: cameraIllustration,
    imageAlt: 'A phone scanning a fresh ingredient with its camera',
    actionLabel: 'Next',
    canSkip: true,
  },
  {
    id: 'recipe-recommendations',
    title: 'Get Recipe Recommendations',
    description:
      'Discover Sri Lankan recipes that match the ingredients you already have.',
    image: recipeIllustration,
    imageAlt: 'A Sri Lankan meal served with rice, curry, and fresh greens',
    actionLabel: 'Next',
    canSkip: true,
  },
  {
    id: 'cook-for-your-needs',
    title: 'Cook for Your Needs',
    description:
      'Enter ingredient quantities and choose your serving size to find recipes for your needs.',
    image: servingsIllustration,
    imageAlt:
      'Ingredient quantities and servings arranged around a cooking pot',
    actionLabel: 'Get Started',
    canSkip: false,
  },
]
