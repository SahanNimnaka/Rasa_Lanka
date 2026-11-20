import type {
  IngredientCategoryDto,
  IngredientDto,
} from '../../types/apiIngredients'
import type {
  DataQualityIssueDto,
  RecipeCategoryDto,
  RecipeDetailDto,
  RecipeIngredientDto,
  RecipeInstructionDto,
  RecipeSourceDto,
  RecipeSummaryDto,
} from '../../types/apiRecipes'

export interface RecipeCategoryViewModel {
  id: string
  name: string
  description: string | null
  recipesCount?: number
}

export interface IngredientCategoryProvenanceViewModel {
  sourceIdentifier: string | null
  sourceRecordIdentifier: string | null
  sourceCategoryText: string | null
}

export interface IngredientCategoryViewModel {
  id: string
  name: string
  description: string | null
  ingredientsCount?: number
  provenance?: IngredientCategoryProvenanceViewModel
}

export interface IngredientResourceViewModel {
  id: string
  name: string
  ingredientKey: string | null
  description: string | null
  categories?: IngredientCategoryViewModel[]
  recipesCount?: number
}

export interface RecipeSummaryViewModel {
  id: string
  recipeCategoryId: string | null
  recipeCode: string | null
  name: string
  mainIngredient: string | null
  recipeType: string | null
  cookingVariation: string | null
  region: string | null
  description: string | null
  preparationTimeMinutes: number | null
  cookingTimeMinutes: number | null
  totalTimeMinutes: number | null
  durationMinutes: number | null
  sourceYield: string | null
  yieldPeople: string | null
  timeIsEstimated: boolean
  yieldIsEstimated: boolean
  timeArithmeticStatus: string | null
  recipeCategory?: RecipeCategoryViewModel | null
  recipeIngredientsCount?: number
  recipeInstructionsCount?: number
  recipeSourcesCount?: number
  dataQualityIssuesCount?: number
}

export interface RecipeIngredientRecipeViewModel {
  id: string
  recipeCode: string | null
  name: string
}

export interface RecipeIngredientViewModel {
  id: string
  recipeId: string
  ingredientId: string | null
  recipe?: RecipeIngredientRecipeViewModel
  ingredient?: IngredientResourceViewModel
  sourceIdentifier: string | null
  sourceRecordIdentifier: string | null
  originalIngredientId: string | null
  originalIngredientName: string | null
  originalQuantityText: string | null
  originalUnit: string | null
  perPersonQuantityMin: string | null
  perPersonQuantityMax: string | null
  perPersonQuantityDescription: string | null
  perPersonUnit: string | null
  expertApprovedQuantity: string | null
  expertApprovedUnit: string | null
  isExpertApproved: boolean
  approvalStatus: string | null
  originalRequirementLabel: string | null
  ingredientPreparation: string | null
  isRequired: boolean | null
  isMainIngredient: boolean | null
  ingredientNotes: string | null
  dataQualityStatus: string | null
  quantityIsDerived: boolean
  quantityDescriptionIsEstimated: boolean
  quantityDependsOnEstimatedYield: boolean
}

export interface RecipeMethodSummaryViewModel {
  id: string
  recipeId: string
  stepNumber: number
  isMethodSummary: true
  cookingMethodParagraph: string | null
  cookingStepsOrdered: string | null
  instructionText: string | null
}

export interface RecipeInstructionViewModel {
  id: string
  recipeId: string
  stepNumber: number
  isMethodSummary: false
  cookingMethodParagraph: string | null
  cookingStepsOrdered: string | null
  instructionText: string | null
}

export interface RecipeSourceViewModel {
  id: string
  sourceName: string | null
  sourceIdentifier: string | null
  sourceRecordIdentifier: string | null
  sourceLocation: string | null
  sourceUri: string | null
  attribution: string | null
  notes: string | null
}

export interface DataQualityIssueViewModel {
  id: string
  sourceFile: string | null
  sourceRow: number | null
  entityType: string | null
  entityReference: string | null
  fieldName: string | null
  severity: string | null
  issueType: string | null
  description: string | null
  recommendedAction: string | null
  resolutionStatus: string | null
  recipeId: string | null
  ingredientId: string | null
}

export interface RecipeDetailViewModel extends RecipeSummaryViewModel {
  recipeIngredients: RecipeIngredientViewModel[]
  methodSummary: RecipeMethodSummaryViewModel | null
  instructions: RecipeInstructionViewModel[]
  recipeSources: RecipeSourceViewModel[]
  dataQualityIssues: DataQualityIssueViewModel[]
}

function toViewId(id: number): string {
  return String(id)
}

function toNullableViewId(id: number | null): string | null {
  return id === null ? null : String(id)
}

function hasExpertApproval(dto: RecipeIngredientDto): boolean {
  if (dto.quantity_is_derived) {
    return false
  }

  const status = dto.approval_status?.trim().toLowerCase()
  const hasApprovedValue =
    dto.expert_approved_quantity !== null ||
    dto.expert_approved_unit !== null

  return status === 'approved' && hasApprovedValue
}

export function mapRecipeCategory(
  dto: RecipeCategoryDto,
): RecipeCategoryViewModel {
  return {
    id: toViewId(dto.id),
    name: dto.name,
    description: dto.description,
    ...(dto.recipes_count === undefined
      ? {}
      : { recipesCount: dto.recipes_count }),
  }
}

export function mapIngredientCategory(
  dto: IngredientCategoryDto,
): IngredientCategoryViewModel {
  return {
    id: toViewId(dto.id),
    name: dto.name,
    description: dto.description,
    ...(dto.ingredients_count === undefined
      ? {}
      : { ingredientsCount: dto.ingredients_count }),
    ...(dto.provenance === undefined
      ? {}
      : {
          provenance: {
            sourceIdentifier: dto.provenance.source_identifier,
            sourceRecordIdentifier: dto.provenance.source_record_identifier,
            sourceCategoryText: dto.provenance.source_category_text,
          },
        }),
  }
}

export function mapRecipeSummary(
  dto: RecipeSummaryDto,
): RecipeSummaryViewModel {
  return {
    id: toViewId(dto.id),
    recipeCategoryId: toNullableViewId(dto.recipe_category_id),
    recipeCode: dto.recipe_code,
    name: dto.name,
    mainIngredient: dto.main_ingredient,
    recipeType: dto.recipe_type,
    cookingVariation: dto.cooking_variation,
    region: dto.region,
    description: dto.description,
    preparationTimeMinutes: dto.preparation_time_minutes,
    cookingTimeMinutes: dto.cooking_time_minutes,
    totalTimeMinutes: dto.total_time_minutes,
    durationMinutes: dto.total_time_minutes,
    sourceYield: dto.source_yield,
    yieldPeople: dto.yield_people,
    timeIsEstimated: dto.time_is_estimated,
    yieldIsEstimated: dto.yield_is_estimated,
    timeArithmeticStatus: dto.time_arithmetic_status,
    ...(dto.recipe_category === undefined
      ? {}
      : {
          recipeCategory:
            dto.recipe_category === null
              ? null
              : mapRecipeCategory(dto.recipe_category),
        }),
    ...(dto.recipe_ingredients_count === undefined
      ? {}
      : { recipeIngredientsCount: dto.recipe_ingredients_count }),
    ...(dto.recipe_instructions_count === undefined
      ? {}
      : { recipeInstructionsCount: dto.recipe_instructions_count }),
    ...(dto.recipe_sources_count === undefined
      ? {}
      : { recipeSourcesCount: dto.recipe_sources_count }),
    ...(dto.data_quality_issues_count === undefined
      ? {}
      : { dataQualityIssuesCount: dto.data_quality_issues_count }),
  }
}

function mapNestedIngredient(
  dto: IngredientDto,
): IngredientResourceViewModel {
  return {
    id: toViewId(dto.id),
    name: dto.name,
    ingredientKey: dto.ingredient_key,
    description: dto.description,
    ...(dto.categories === undefined
      ? {}
      : { categories: dto.categories.map(mapIngredientCategory) }),
    ...(dto.recipes_count === undefined
      ? {}
      : { recipesCount: dto.recipes_count }),
  }
}

export function mapRecipeIngredient(
  dto: RecipeIngredientDto,
): RecipeIngredientViewModel {
  return {
    id: toViewId(dto.id),
    recipeId: toViewId(dto.recipe_id),
    ingredientId: toNullableViewId(dto.ingredient_id),
    ...(dto.recipe === undefined
      ? {}
      : {
          recipe: {
            id: toViewId(dto.recipe.id),
            recipeCode: dto.recipe.recipe_code,
            name: dto.recipe.name,
          },
        }),
    ...(dto.ingredient === undefined
      ? {}
      : { ingredient: mapNestedIngredient(dto.ingredient) }),
    sourceIdentifier: dto.source_identifier,
    sourceRecordIdentifier: dto.source_record_identifier,
    originalIngredientId: dto.original_ingredient_id,
    originalIngredientName: dto.original_ingredient_name,
    originalQuantityText: dto.original_quantity_text,
    originalUnit: dto.original_unit,
    perPersonQuantityMin: dto.per_person_quantity_min,
    perPersonQuantityMax: dto.per_person_quantity_max,
    perPersonQuantityDescription: dto.per_person_quantity_description,
    perPersonUnit: dto.per_person_unit,
    expertApprovedQuantity: dto.expert_approved_quantity,
    expertApprovedUnit: dto.expert_approved_unit,
    isExpertApproved: hasExpertApproval(dto),
    approvalStatus: dto.approval_status,
    originalRequirementLabel: dto.original_requirement_label,
    ingredientPreparation: dto.ingredient_preparation,
    isRequired: dto.is_required,
    isMainIngredient: dto.is_main_ingredient,
    ingredientNotes: dto.ingredient_notes,
    dataQualityStatus: dto.data_quality_status,
    quantityIsDerived: dto.quantity_is_derived,
    quantityDescriptionIsEstimated: dto.quantity_description_is_estimated,
    quantityDependsOnEstimatedYield: dto.quantity_depends_on_estimated_yield,
  }
}

export function mapMethodSummary(
  dto: RecipeInstructionDto,
): RecipeMethodSummaryViewModel {
  return {
    id: toViewId(dto.id),
    recipeId: toViewId(dto.recipe_id),
    stepNumber: dto.step_number,
    isMethodSummary: true,
    cookingMethodParagraph: dto.cooking_method_paragraph,
    cookingStepsOrdered: dto.cooking_steps_ordered,
    instructionText: dto.instruction_text,
  }
}

export function mapRecipeInstruction(
  dto: RecipeInstructionDto,
): RecipeInstructionViewModel {
  return {
    id: toViewId(dto.id),
    recipeId: toViewId(dto.recipe_id),
    stepNumber: dto.step_number,
    isMethodSummary: false,
    cookingMethodParagraph: dto.cooking_method_paragraph,
    cookingStepsOrdered: dto.cooking_steps_ordered,
    instructionText: dto.instruction_text,
  }
}

export function mapRecipeSource(
  dto: RecipeSourceDto,
): RecipeSourceViewModel {
  return {
    id: toViewId(dto.id),
    sourceName: dto.source_name,
    sourceIdentifier: dto.source_identifier,
    sourceRecordIdentifier: dto.source_record_identifier,
    sourceLocation: dto.source_location,
    sourceUri: dto.source_uri,
    attribution: dto.attribution,
    notes: dto.notes,
  }
}

export function mapDataQualityIssue(
  dto: DataQualityIssueDto,
): DataQualityIssueViewModel {
  return {
    id: toViewId(dto.id),
    sourceFile: dto.source_file,
    sourceRow: dto.source_row,
    entityType: dto.entity_type,
    entityReference: dto.entity_reference,
    fieldName: dto.field_name,
    severity: dto.severity,
    issueType: dto.issue_type,
    description: dto.description,
    recommendedAction: dto.recommended_action,
    resolutionStatus: dto.resolution_status,
    recipeId: toNullableViewId(dto.recipe_id),
    ingredientId: toNullableViewId(dto.ingredient_id),
  }
}

export function mapRecipeDetail(dto: RecipeDetailDto): RecipeDetailViewModel {
  return {
    ...mapRecipeSummary(dto),
    recipeIngredients: dto.recipe_ingredients.map(mapRecipeIngredient),
    methodSummary:
      dto.method_summary === null ? null : mapMethodSummary(dto.method_summary),
    instructions: dto.instructions.map(mapRecipeInstruction),
    recipeSources: dto.recipe_sources.map(mapRecipeSource),
    dataQualityIssues: dto.data_quality_issues.map(mapDataQualityIssue),
  }
}
