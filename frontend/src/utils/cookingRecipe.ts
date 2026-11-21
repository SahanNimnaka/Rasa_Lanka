import type {
  RecipeInstructionViewModel,
  RecipeMethodSummaryViewModel,
} from '../services/mappers/recipeMappers'

export interface CookingQuantity {
  quantity: string
  unit: string
}

export interface MethodSummaryPart {
  label: string
  value: string
}

export function displayValue(value: string | null | undefined): string | null {
  const trimmed = value?.trim()
  return trimmed ? trimmed : null
}

export function minuteLabel(minutes: number | null | undefined): string {
  return typeof minutes === 'number' && Number.isFinite(minutes)
    ? `${minutes} min`
    : 'Not provided'
}

export function methodSummaryParts(
  instruction: RecipeInstructionViewModel | RecipeMethodSummaryViewModel | null,
): MethodSummaryPart[] {
  if (!instruction) {
    return []
  }

  return [
    {
      label: 'Method paragraph',
      value: displayValue(instruction.cookingMethodParagraph),
    },
    {
      label: 'Ordered steps',
      value: displayValue(instruction.cookingStepsOrdered),
    },
    {
      label: 'Summary',
      value: displayValue(instruction.instructionText),
    },
  ].filter((part): part is MethodSummaryPart => part.value !== null)
}

export function instructionText(
  instruction: RecipeInstructionViewModel | RecipeMethodSummaryViewModel,
): string {
  return (
    displayValue(instruction.instructionText) ??
    displayValue(instruction.cookingStepsOrdered) ??
    displayValue(instruction.cookingMethodParagraph) ??
    'No instruction text is provided.'
  )
}

export function stepDescription(
  instruction: RecipeInstructionViewModel,
): string | null {
  const title = instructionText(instruction)
  const descriptions = [
    instruction.cookingMethodParagraph,
    instruction.cookingStepsOrdered,
  ]
    .map(displayValue)
    .filter((value): value is string => value !== null && value !== title)

  return descriptions.length > 0 ? descriptions.join(' ') : null
}

/** Keeps API step numbers intact while excluding a step 0 method summary. */
export function numberedCookingSteps(
  instructions: RecipeInstructionViewModel[],
): RecipeInstructionViewModel[] {
  return instructions
    .filter((step) => step.stepNumber > 0)
    .sort((first, second) => first.stepNumber - second.stepNumber)
}

function normalizeName(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/\([^)]*\)/g, '')
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

export function collectUserQuantities(
  ingredients: Array<{ name: string; quantity: string; unit: string }>,
): Map<string, CookingQuantity> {
  const quantities = new Map<string, CookingQuantity>()
  for (const ingredient of ingredients) {
    const key = normalizeName(ingredient.name)
    if (key && !quantities.has(key)) {
      quantities.set(key, {
        quantity: ingredient.quantity,
        unit: ingredient.unit,
      })
    }
  }
  return quantities
}
