import type { SelectedIngredient } from '../types/ingredients'

export const DEMO_RECOGNITION_NOTICE =
  'Demonstration results only — no AI recognition model is connected yet.'

export interface RecognizedIngredient extends SelectedIngredient {
  source: 'demo'
}

/**
 * Frontend-only mock. These values mirror the approved wireframe and must be
 * labelled as demonstration data wherever they are shown. Replace this
 * service with a real recognition API call when the model is available.
 */
export function getDemoRecognitionResults(): RecognizedIngredient[] {
  return [
    {
      id: 'demo-red-lentils',
      name: 'Red lentils',
      confidence: 98,
      source: 'demo',
    },
    {
      id: 'demo-coconut-milk',
      name: 'Coconut milk',
      confidence: 95,
      source: 'demo',
    },
    { id: 'demo-onion', name: 'Onion', confidence: 92, source: 'demo' },
  ]
}
