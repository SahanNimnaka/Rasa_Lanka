import type { IngredientWithQuantity } from '../types/ingredients'
import type { Recipe } from '../types/recipe'

export const MOCK_RECOMMENDATION_NOTICE =
  'Local mock recipes for demonstration — not AI-generated recommendations.'

function getIngredientImage(name: string): string {
  const key = name.trim().toLowerCase()
  if (key.includes('lentil')) {
    return '/images/ingredients/red-lentils.jpg'
  }
  if (key.includes('coconut milk')) {
    return '/images/ingredients/coconut-milk.jpg'
  }
  if (key.includes('onion')) {
    return '/images/ingredients/onion.jpg'
  }
  return ''
}

/**
 * Frontend-only recipe fixtures. This module is the single place to replace
 * when the Laravel recipe API becomes available. Components never construct
 * recipe data themselves.
 */
const MOCK_RECIPES: Recipe[] = [
  {
    id: 'dhal-curry',
    name: 'Dhal Curry',
    image: '/images/recipes/dhal-curry.jpg',
    imageAlt: 'A bowl of Sri Lankan dhal curry',
    category: 'curry',
    cuisine: 'Sri Lankan',
    rating: 4.8,
    durationMinutes: 25,
    matchPercent: 90,
    servings: 2,
    description: 'A comforting red lentil curry with coconut milk and spices.',
    availableIngredients: [
      {
        id: 'dhal-red-lentils',
        name: 'Red lentils',
        quantity: '200',
        unit: 'g',
        available: true,
        image: getIngredientImage('Red lentils'),
      },
      {
        id: 'dhal-coconut-milk',
        name: 'Coconut milk',
        quantity: '1',
        unit: 'cup',
        available: true,
        image: getIngredientImage('Coconut milk'),
      },
      {
        id: 'dhal-onion',
        name: 'Onion',
        quantity: '1',
        unit: 'piece',
        available: true,
        image: getIngredientImage('Onion'),
      },
    ],
    missingIngredients: [
      {
        id: 'dhal-turmeric',
        name: 'Turmeric',
        quantity: '½',
        unit: 'tsp',
        available: false,
      },
      {
        id: 'dhal-curry-leaves',
        name: 'Curry leaves',
        quantity: '1',
        unit: 'sprig',
        available: false,
      },
    ],
    instructions: [
      {
        id: 'dhal-step-1',
        number: 1,
        title: 'Rinse the lentils',
        description: 'Wash red lentils thoroughly, then drain the water.',
        image: getIngredientImage('Red lentils'),
        imageAlt: 'Dried red lentils ready for rinsing',
      },
      {
        id: 'dhal-step-2',
        number: 2,
        title: 'Prepare the ingredients',
        description: 'Slice the onion. Measure coconut milk and spices.',
        image: getIngredientImage('Onion'),
        imageAlt: 'A whole onion and onion rings',
      },
      {
        id: 'dhal-step-3',
        number: 3,
        title: 'Simmer the curry',
        description:
          'Add lentils, onion and spices to a pan. Cover with water and simmer.',
        image: '/images/recipes/dhal-curry.jpg',
        imageAlt: 'Finished dhal curry in a bowl',
      },
    ],
  },
  {
    id: 'coconut-roti',
    name: 'Coconut Roti',
    image: '/images/recipes/coconut-roti.jpg',
    imageAlt: 'Sri Lankan coconut roti served with curry and sambol',
    category: 'other',
    cuisine: 'Sri Lankan',
    rating: 4.6,
    durationMinutes: 30,
    matchPercent: 75,
    servings: 2,
    description: 'Sri Lankan-style flatbread with grated coconut.',
    availableIngredients: [
      {
        id: 'roti-coconut-milk',
        name: 'Coconut milk',
        quantity: '1',
        unit: 'cup',
        available: true,
        image: getIngredientImage('Coconut milk'),
      },
      {
        id: 'roti-onion',
        name: 'Onion',
        quantity: '1',
        unit: 'piece',
        available: true,
        image: getIngredientImage('Onion'),
      },
    ],
    missingIngredients: [
      {
        id: 'roti-flour',
        name: 'Wheat flour',
        quantity: '2',
        unit: 'cup',
        available: false,
      },
      {
        id: 'roti-salt',
        name: 'Salt',
        quantity: '1',
        unit: 'pinch',
        available: false,
      },
    ],
    instructions: [
      {
        id: 'roti-step-1',
        number: 1,
        title: 'Mix the dough',
        description:
          'Combine coconut milk with flour and onion to form a soft dough.',
        image: getIngredientImage('Coconut milk'),
        imageAlt: 'Coconut milk ready for the roti dough',
      },
      {
        id: 'roti-step-2',
        number: 2,
        title: 'Cook the roti',
        description:
          'Flatten the dough and cook on a hot griddle until golden.',
        image: '/images/recipes/coconut-roti.jpg',
        imageAlt: 'Cooked coconut roti',
      },
    ],
  },
  {
    id: 'pol-sambol',
    name: 'Pol Sambol',
    image: '/images/recipes/pol-sambol.jpg',
    imageAlt: 'A bowl of Sri Lankan pol sambol',
    category: 'sambol',
    cuisine: 'Sri Lankan',
    rating: 4.7,
    durationMinutes: 10,
    matchPercent: 80,
    servings: 2,
    description: 'A bright coconut and chilli sambol served with rice or roti.',
    availableIngredients: [
      {
        id: 'sambol-coconut',
        name: 'Coconut',
        quantity: '1',
        unit: 'cup',
        available: true,
      },
      {
        id: 'sambol-onion',
        name: 'Onion',
        quantity: '1',
        unit: 'piece',
        available: true,
        image: getIngredientImage('Onion'),
      },
    ],
    missingIngredients: [
      {
        id: 'sambol-red-chilli',
        name: 'Red chilli',
        quantity: '2',
        unit: 'piece',
        available: false,
      },
      {
        id: 'sambol-lime',
        name: 'Lime',
        quantity: '1',
        unit: 'piece',
        available: false,
      },
    ],
    instructions: [
      {
        id: 'sambol-step-1',
        number: 1,
        title: 'Grate the coconut',
        description: 'Grate fresh coconut and finely chop the onion.',
        image: '/images/recipes/pol-sambol.jpg',
        imageAlt: 'Pol sambol ingredients ready to mix',
      },
      {
        id: 'sambol-step-2',
        number: 2,
        title: 'Mix and season',
        description:
          'Combine coconut, onion, chilli and lime. Season and serve.',
        image: '/images/recipes/pol-sambol.jpg',
        imageAlt: 'Finished pol sambol in a bowl',
      },
    ],
  },
]

const RECOMMENDED_RECIPE_IDS = new Set(['dhal-curry', 'coconut-roti'])

function cloneRecipes(recipes: Recipe[]): Recipe[] {
  return recipes.map((recipe) => ({
    ...recipe,
    availableIngredients: recipe.availableIngredients.map((ingredient) => ({
      ...ingredient,
    })),
    missingIngredients: recipe.missingIngredients.map((ingredient) => ({
      ...ingredient,
    })),
    instructions: recipe.instructions.map((step) => ({ ...step })),
  }))
}

/** Recommendation fixtures only; Screen 13 behavior stays unchanged. */
export function getMockRecipes(): Recipe[] {
  return cloneRecipes(
    MOCK_RECIPES.filter((recipe) => RECOMMENDED_RECIPE_IDS.has(recipe.id)),
  )
}

/** Full local recipe catalog used by saved, search, history and guidance. */
export function getRecipeCatalog(): Recipe[] {
  return cloneRecipes(MOCK_RECIPES)
}

export function getRecipeById(recipeId: string): Recipe | undefined {
  return getRecipeCatalog().find((recipe) => recipe.id === recipeId)
}

/** Applies quantities entered on Screen 11 to a recipe's available ingredients. */
export function applyUserQuantities(
  recipe: Recipe,
  ingredients: IngredientWithQuantity[],
): Recipe {
  return {
    ...recipe,
    availableIngredients: recipe.availableIngredients.map((ingredient) => {
      const match = ingredients.find(
        (item) =>
          item.name.trim().toLowerCase() === ingredient.name.toLowerCase(),
      )
      return match
        ? { ...ingredient, quantity: match.quantity, unit: match.unit }
        : { ...ingredient }
    }),
  }
}

export function getDefaultRecipe(): Recipe {
  const recipe = getRecipeById('dhal-curry')
  if (!recipe) {
    throw new Error('Mock Dhal Curry recipe is missing')
  }
  return recipe
}
