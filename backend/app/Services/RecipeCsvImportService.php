<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\DataQualityIssue;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\RecipeIngredient;
use App\Models\RecipeInstruction;
use App\Models\RecipeSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RecipeCsvImportService
{
    private const REQUIRED_FILES = [
        'recipes_import.csv',
        'ingredients_import.csv',
        'ingredient_categories_import.csv',
        'recipe_ingredients_import.csv',
        'recipe_instructions_import.csv',
        'recipe_sources_import.csv',
        'ingredient_category_conflicts.csv',
        'data_quality_issues.csv',
        'data_dictionary.csv',
    ];

    private const HEADERS = [
        'recipes_import.csv' => [
            'category', 'recipe_id', 'recipe_name', 'main_ingredient', 'recipe_type',
            'cooking_variation', 'region', 'recipe_description', 'preparation_time_min',
            'cooking_time_min', 'total_time_min', 'source_yield_people',
            'time_estimated_flag', 'yield_estimated_flag', 'time_arithmetic_status',
        ],
        'ingredients_import.csv' => [
            'ingredient_key', 'ingredient_name', 'category_labels', 'category_conflict_flag',
        ],
        'ingredient_categories_import.csv' => ['ingredient_category'],
        'recipe_ingredients_import.csv' => [
            'source_ingredient_id', 'recipe_id', 'ingredient_key', 'ingredient_name',
            'ingredient_category', 'is_main_ingredient', 'original_quantity_text',
            'original_unit', 'quantity_per_person_numeric_min',
            'quantity_per_person_numeric_max', 'quantity_per_person_description',
            'per_person_unit', 'approved_quantity_per_person', 'approved_unit',
            'approval_status', 'ingredient_preparation', 'required_or_optional',
            'ingredient_notes', 'time_estimated_flag', 'yield_estimated_flag',
            'quantity_derived_flag', 'quantity_description_estimated_flag',
            'quantity_depends_on_estimated_yield_flag', 'data_quality_status',
        ],
        'recipe_instructions_import.csv' => [
            'recipe_id', 'cooking_method_paragraph', 'cooking_steps_ordered',
        ],
        'recipe_sources_import.csv' => ['source_id', 'recipe_id', 'source_title', 'source_url'],
        'ingredient_category_conflicts.csv' => [
            'ingredient_name', 'category_labels', 'category_count', 'review_required', 'note',
        ],
        'data_quality_issues.csv' => [
            'severity', 'issue_type', 'record_id', 'details', 'recommended_action',
        ],
        'data_dictionary.csv' => ['Column', 'PostgreSQL_Staging_Type', 'Handling'],
        'master_recipe_ingredients.csv' => [
            'Category', 'Recipe_ID', 'Recipe_Name', 'Main_Ingredient', 'Recipe_Type',
            'Cooking_Variation', 'Region', 'Recipe_Description', 'Preparation_Time_Min',
            'Cooking_Time_Min', 'Total_Time_Min', 'Source_Yield_People',
            'Cooking_Method_Paragraph', 'Cooking_Steps_Ordered', 'Ingredient_ID',
            'Ingredient_Name', 'Ingredient_Category', 'Is_Main_Ingredient',
            'Original_Quantity', 'Original_Unit', 'Quantity_Per_Person_Calculated',
            'Per_Person_Unit', 'Approved_Quantity_Per_Person', 'Approved_Unit',
            'Ingredient_Preparation', 'Required_or_Optional', 'Ingredient_Notes',
            'Source_ID', 'Source_Title', 'Source_URL', 'Data_Quality_Status',
            'Time_Estimated_Flag', 'Yield_Estimated_Flag', 'Quantity_Derived_Flag',
            'Quantity_Description_Estimated_Flag',
            'Quantity_Depends_On_Estimated_Yield_Flag', 'Any_Estimated_Flag',
        ],
        'audit_summary.csv' => ['Metric', 'Count', 'Finding'],
    ];

    private const OPTIONAL_RECIPE_HEADERS = [
        'preparation_time_source',
        'cooking_time_source',
        'total_time_source',
        'source_yield',
    ];

    private const TABLES = [
        'recipe_categories',
        'ingredient_categories',
        'ingredients',
        'recipes',
        'ingredient_category',
        'recipe_ingredients',
        'recipe_instructions',
        'recipe_sources',
        'data_quality_issues',
    ];

    private const QUALITY_ISSUE_MAPPINGS = [
        'Recipe preparation/cooking time estimate' => ['recipe', null],
        'Recipe time arithmetic mismatch' => ['recipe', null],
        'Serving yield estimate' => ['recipe', 'yield_people'],
        'Ingredient category conflict' => ['ingredient', 'category_labels'],
    ];

    public function analyze(string $directory): array
    {
        $report = [
            'files_found' => [],
            'records_detected' => [],
            'ready_records' => [],
            'skipped_records' => [],
            'duplicate_identifiers' => [],
            'missing_foreign_keys' => [],
            'invalid_numeric_values' => [],
            'numeric_precision_adjustments' => [],
            'instruction_parsing_failures' => [],
            'unmapped_columns' => [],
            'ambiguous_requirement_labels' => [],
            'aggregate_flag_inconsistencies' => [],
            'warnings' => [],
            'errors' => [],
            'category_conflicts' => 0,
            'estimated_values' => [],
            'expert_validation_pending' => 0,
            'quality_issues_by_severity' => [],
        ];

        if (! is_dir($directory)) {
            $report['errors'][] = "Import package directory does not exist: {$directory}";

            return ['directory' => $directory, 'report' => $report, 'plan' => []];
        }

        $csvPaths = glob(rtrim($directory, '\\/').DIRECTORY_SEPARATOR.'*.csv') ?: [];
        $loaded = [];

        foreach ($csvPaths as $path) {
            $name = basename($path);
            $report['files_found'][] = $name;

            try {
                $loaded[$name] = $this->readCsv($path, $report);
                $report['records_detected'][$name] = count($loaded[$name]['rows']);
            } catch (RuntimeException $exception) {
                $report['errors'][] = $exception->getMessage();
            }
        }

        foreach (self::REQUIRED_FILES as $requiredFile) {
            if (! array_key_exists($requiredFile, $loaded)) {
                $report['errors'][] = "Required CSV is missing: {$requiredFile}";
            }
        }

        foreach (array_keys($loaded) as $name) {
            if (! array_key_exists($name, self::HEADERS)) {
                $report['unmapped_columns'][] = "{$name}: file is not part of the import mapping";
                $report['errors'][] = end($report['unmapped_columns']);

                continue;
            }

            $allowedHeaders = self::HEADERS[$name];
            if ($name === 'recipes_import.csv') {
                $allowedHeaders = array_merge($allowedHeaders, self::OPTIONAL_RECIPE_HEADERS);
            }

            $missing = array_values(array_diff(self::HEADERS[$name], $loaded[$name]['headers']));
            $extra = array_values(array_diff($loaded[$name]['headers'], $allowedHeaders));

            if ($missing !== []) {
                $report['errors'][] = "{$name} is missing columns: ".implode(', ', $missing);
            }

            if ($extra !== []) {
                $report['unmapped_columns'][] = "{$name}: ".implode(', ', $extra);
                $report['errors'][] = end($report['unmapped_columns']);
            }
        }

        if ($report['errors'] !== []) {
            return ['directory' => $directory, 'report' => $report, 'plan' => []];
        }

        $rows = [];
        foreach ($loaded as $name => $file) {
            $rows[$name] = $file['rows'];
        }

        $plan = [
            'recipe_categories' => [],
            'ingredient_categories' => [],
            'ingredients' => [],
            'recipes' => [],
            'ingredient_category' => [],
            'recipe_ingredients' => [],
            'recipe_instructions' => [],
            'recipe_sources' => [],
            'data_quality_issues' => [],
        ];

        $recipesByCode = [];
        $recipeSourceFlags = [];
        $recipeCategories = [];
        $seenRecipeCodes = [];

        foreach ($rows['recipes_import.csv'] as $index => $row) {
            $record = $index + 2;
            $code = trim($row['recipe_id']);
            $category = trim($row['category']);
            $beforeErrors = count($report['errors']);

            if ($code === '' || $category === '' || trim($row['recipe_name']) === '') {
                $report['errors'][] = "recipes_import.csv row {$record} has a blank recipe_id, category, or recipe_name.";
            }

            if (isset($seenRecipeCodes[$code])) {
                $report['duplicate_identifiers'][] = "recipes_import.csv duplicate recipe_id {$code}";
                $report['errors'][] = "Duplicate recipe_id in recipes_import.csv: {$code}";
            }
            $seenRecipeCodes[$code] = true;

            $preparationTime = $this->parseNumber(
                $row['preparation_time_min'], 10, 0, 'recipes_import.csv',
                $record, 'preparation_time_min', $report
            );
            $cookingTime = $this->parseNumber(
                $row['cooking_time_min'], 10, 0, 'recipes_import.csv',
                $record, 'cooking_time_min', $report
            );
            $totalTime = $this->parseNumber(
                $row['total_time_min'], 10, 0, 'recipes_import.csv',
                $record, 'total_time_min', $report
            );
            $yieldPeople = $this->parseNumber(
                $row['source_yield_people'], 6, 2, 'recipes_import.csv',
                $record, 'source_yield_people', $report, true
            );
            $timeEstimated = $this->parseBoolean(
                $row['time_estimated_flag'], 'recipes_import.csv', $record, 'time_estimated_flag', $report
            );
            $yieldEstimated = $this->parseBoolean(
                $row['yield_estimated_flag'], 'recipes_import.csv', $record, 'yield_estimated_flag', $report
            );

            if (count($report['errors']) !== $beforeErrors) {
                $this->skip($report, 'recipes_import.csv', $code ?: "row {$record}");

                continue;
            }

            $values = [
                'recipe_code' => $code,
                'name' => $row['recipe_name'],
                'main_ingredient' => $this->nullableText($row['main_ingredient']),
                'recipe_type' => $this->nullableText($row['recipe_type']),
                'cooking_variation' => $this->nullableText($row['cooking_variation']),
                'region' => $this->nullableText($row['region']),
                'description' => $this->nullableText($row['recipe_description']),
                'preparation_time_minutes' => $preparationTime === null ? null : (int) $preparationTime,
                'cooking_time_minutes' => $cookingTime === null ? null : (int) $cookingTime,
                'total_time_minutes' => $totalTime === null ? null : (int) $totalTime,
                'source_yield' => array_key_exists('source_yield', $row)
                    ? $this->nullableText($row['source_yield'])
                    : $this->nullableText($row['source_yield_people']),
                'yield_people' => $yieldPeople,
                'time_is_estimated' => $timeEstimated,
                'yield_is_estimated' => $yieldEstimated,
                'time_arithmetic_status' => $this->nullableText($row['time_arithmetic_status']),
            ];

            foreach (['preparation_time_source', 'cooking_time_source', 'total_time_source'] as $sourceColumn) {
                if (array_key_exists($sourceColumn, $row)) {
                    $values[$sourceColumn] = $this->nullableText($row[$sourceColumn]);
                }
            }

            $plan['recipes'][] = [
                'recipe_code' => $code,
                'category_name' => $category,
                'values' => $values,
            ];
            $recipesByCode[$code] = $row;
            $recipeSourceFlags[$code] = [
                'time_estimated_flag' => $timeEstimated,
                'yield_estimated_flag' => $yieldEstimated,
            ];
            $recipeCategories[$category] = true;
        }

        foreach (array_keys($recipeCategories) as $category) {
            $plan['recipe_categories'][] = ['name' => $category, 'values' => []];
        }

        $ingredientsByKey = [];
        $ingredientKeysByName = [];
        $categoryLabelsByKey = [];
        $seenIngredientKeys = [];
        $conflictKeys = [];

        foreach ($rows['ingredients_import.csv'] as $index => $row) {
            $record = $index + 2;
            $key = trim($row['ingredient_key']);
            $name = $row['ingredient_name'];
            $beforeErrors = count($report['errors']);
            $labels = $this->splitCategoryLabels($row['category_labels']);

            if ($key === '' || trim($name) === '' || $labels === []) {
                $report['errors'][] = "ingredients_import.csv row {$record} has a blank key, name, or category label.";
            }

            if (isset($seenIngredientKeys[$key])) {
                $report['duplicate_identifiers'][] = "ingredients_import.csv duplicate ingredient_key {$key}";
                $report['errors'][] = "Duplicate ingredient_key in ingredients_import.csv: {$key}";
            }
            $seenIngredientKeys[$key] = true;

            $normalizedName = $this->normalizeName($name);
            if (isset($ingredientKeysByName[$normalizedName]) && $ingredientKeysByName[$normalizedName] !== $key) {
                $report['duplicate_identifiers'][] = "Normalized ingredient name collision for {$name}";
                $report['errors'][] = "Two ingredient keys normalize to the same display name: {$name}";
            }

            $ingredientKeysByName[$normalizedName] = $key;
            $ingredientsByKey[$key] = $row;
            $categoryLabelsByKey[$key] = $labels;

            $conflictFlag = $this->parseBoolean(
                $row['category_conflict_flag'], 'ingredients_import.csv',
                $record, 'category_conflict_flag', $report
            );
            if ($conflictFlag !== null && $conflictFlag !== (count($labels) > 1)) {
                $report['errors'][] = "ingredients_import.csv row {$record} has a category_conflict_flag that disagrees with category_labels.";
            }

            if (count($report['errors']) !== $beforeErrors) {
                $this->skip($report, 'ingredients_import.csv', $key ?: "row {$record}");

                continue;
            }

            $plan['ingredients'][] = [
                'ingredient_key' => $key,
                'values' => ['name' => $name],
            ];

            if (count($labels) > 1) {
                $conflictKeys[$key] = true;
            }
        }

        $categoryNames = [];
        $seenCategoryNames = [];
        foreach ($rows['ingredient_categories_import.csv'] as $index => $row) {
            $name = trim($row['ingredient_category']);
            if ($name === '') {
                $report['errors'][] = 'ingredient_categories_import.csv has a blank category at row '.($index + 2).'.';
                $this->skip($report, 'ingredient_categories_import.csv', 'row '.($index + 2));

                continue;
            }
            if (isset($seenCategoryNames[$name])) {
                $report['duplicate_identifiers'][] = "ingredient_categories_import.csv duplicate category {$name}";
                $report['errors'][] = "Duplicate ingredient category in CSV: {$name}";
                $this->skip($report, 'ingredient_categories_import.csv', $name);

                continue;
            }

            $seenCategoryNames[$name] = true;
            $categoryNames[$name] = true;
            $plan['ingredient_categories'][] = ['name' => $name, 'values' => []];
        }

        foreach ($categoryLabelsByKey as $key => $labels) {
            foreach ($labels as $label) {
                if (! isset($categoryNames[$label])) {
                    $report['missing_foreign_keys'][] = "Ingredient {$key} references missing category {$label}";
                    $report['errors'][] = end($report['missing_foreign_keys']);
                }
            }
        }

        $sourceRowsByRecipe = [];
        foreach ($rows['recipe_sources_import.csv'] as $index => $row) {
            $record = $index + 2;
            $recipeCode = trim($row['recipe_id']);
            $sourceId = trim($row['source_id']);
            if ($recipeCode === '' || $sourceId === '' || trim($row['source_title']) === '') {
                $report['errors'][] = "recipe_sources_import.csv row {$record} has a blank recipe_id, source_id, or source_title.";
                $this->skip($report, 'recipe_sources_import.csv', $recipeCode ?: "row {$record}");

                continue;
            }
            if (! isset($recipesByCode[$recipeCode])) {
                $report['missing_foreign_keys'][] = "Source row {$record} references missing recipe {$recipeCode}";
                $report['errors'][] = end($report['missing_foreign_keys']);
                $this->skip($report, 'recipe_sources_import.csv', $recipeCode);

                continue;
            }
            if (isset($sourceRowsByRecipe[$recipeCode])) {
                $report['duplicate_identifiers'][] = "recipe_sources_import.csv duplicate recipe_id {$recipeCode}";
                $report['errors'][] = "Multiple source rows found for recipe {$recipeCode}; manual source identity resolution is required.";
                $this->skip($report, 'recipe_sources_import.csv', $recipeCode);

                continue;
            }

            $sourceRowsByRecipe[$recipeCode] = $row;
            $plan['recipe_sources'][] = [
                'recipe_code' => $recipeCode,
                'source_identifier' => $sourceId,
                'source_record_identifier' => $recipeCode,
                'values' => [
                    'source_name' => $row['source_title'],
                    'source_uri' => $this->nullableText($row['source_url']),
                ],
            ];
        }

        $sourceIngredientRows = [];
        $instructionRowsByRecipe = [];
        foreach ($rows['recipe_instructions_import.csv'] as $instructionRow) {
            $instructionRowsByRecipe[trim($instructionRow['recipe_id'])] = $instructionRow;
        }
        $this->validateMasterRows(
            $rows,
            $ingredientsByKey,
            $categoryLabelsByKey,
            $recipesByCode,
            $sourceRowsByRecipe,
            $instructionRowsByRecipe,
            $report,
            $sourceIngredientRows
        );

        $seenSourceIngredientIds = [];
        foreach ($rows['recipe_ingredients_import.csv'] as $index => $row) {
            $record = $index + 2;
            $sourceId = trim($row['source_ingredient_id']);
            $recipeCode = trim($row['recipe_id']);
            $ingredientKey = trim($row['ingredient_key']);
            $beforeErrors = count($report['errors']);

            if ($sourceId === '' || $recipeCode === '' || $ingredientKey === '') {
                $report['errors'][] = "recipe_ingredients_import.csv row {$record} has a blank stable identifier.";
            }
            if (isset($seenSourceIngredientIds[$sourceId])) {
                $report['duplicate_identifiers'][] = "recipe_ingredients_import.csv duplicate source_ingredient_id {$sourceId}";
                $report['errors'][] = "Duplicate source_ingredient_id in recipe_ingredients_import.csv: {$sourceId}";
            }
            $seenSourceIngredientIds[$sourceId] = true;

            if (! isset($recipesByCode[$recipeCode])) {
                $report['missing_foreign_keys'][] = "Recipe ingredient {$sourceId} references missing recipe {$recipeCode}";
                $report['errors'][] = end($report['missing_foreign_keys']);
            }
            if (! isset($ingredientsByKey[$ingredientKey])) {
                $report['missing_foreign_keys'][] = "Recipe ingredient {$sourceId} references missing ingredient {$ingredientKey}";
                $report['errors'][] = end($report['missing_foreign_keys']);
            }

            $mainIngredient = $this->parseBoolean(
                $row['is_main_ingredient'], 'recipe_ingredients_import.csv',
                $record, 'is_main_ingredient', $report
            );
            $quantityDerived = $this->parseBoolean(
                $row['quantity_derived_flag'], 'recipe_ingredients_import.csv',
                $record, 'quantity_derived_flag', $report
            );
            $quantityDescriptionEstimated = $this->parseBoolean(
                $row['quantity_description_estimated_flag'], 'recipe_ingredients_import.csv',
                $record, 'quantity_description_estimated_flag', $report
            );
            $quantityDependsOnEstimatedYield = $this->parseBoolean(
                $row['quantity_depends_on_estimated_yield_flag'], 'recipe_ingredients_import.csv',
                $record, 'quantity_depends_on_estimated_yield_flag', $report
            );
            $timeEstimated = $this->parseBoolean(
                $row['time_estimated_flag'], 'recipe_ingredients_import.csv',
                $record, 'time_estimated_flag', $report
            );
            $yieldEstimated = $this->parseBoolean(
                $row['yield_estimated_flag'], 'recipe_ingredients_import.csv',
                $record, 'yield_estimated_flag', $report
            );

            $quantityMin = $this->parseNumber(
                $row['quantity_per_person_numeric_min'], 12, 5, 'recipe_ingredients_import.csv',
                $record, 'quantity_per_person_numeric_min', $report
            );
            $quantityMax = $this->parseNumber(
                $row['quantity_per_person_numeric_max'], 12, 5, 'recipe_ingredients_import.csv',
                $record, 'quantity_per_person_numeric_max', $report
            );
            $approvalStatus = $row['approval_status'];
            $pendingApproval = stripos($approvalStatus, 'pending') !== false;
            $approvedQuantityText = $row['approved_quantity_per_person'];
            $approvedUnit = $row['approved_unit'];
            $approvedQuantity = null;
            if ($pendingApproval) {
                $report['expert_validation_pending']++;
                if (trim($approvedQuantityText) !== '' || trim($approvedUnit) !== '') {
                    $report['warnings'][] = "Pending approval row {$sourceId} contained approved values; they will not be written.";
                }
                if (trim($approvedQuantityText) !== '' && ! $this->isNumericText($approvedQuantityText, 12, 5)) {
                    $this->invalidNumeric($report, 'recipe_ingredients_import.csv', $record, 'approved_quantity_per_person', $approvedQuantityText);
                }
                $approvedUnit = '';
            } else {
                $approvedQuantity = $this->parseNumber(
                    $approvedQuantityText, 12, 5, 'recipe_ingredients_import.csv',
                    $record, 'approved_quantity_per_person', $report
                );
            }

            if ($quantityMin !== null && $quantityMax !== null && (float) $quantityMin > (float) $quantityMax) {
                $report['errors'][] = "recipe_ingredients_import.csv row {$record} has a minimum quantity greater than its maximum.";
            }

            if (isset($recipeSourceFlags[$recipeCode])) {
                if ($timeEstimated !== $recipeSourceFlags[$recipeCode]['time_estimated_flag']
                    || $yieldEstimated !== $recipeSourceFlags[$recipeCode]['yield_estimated_flag']) {
                    $report['errors'][] = "Recipe ingredient {$sourceId} has recipe-level estimate flags that disagree with recipes_import.csv.";
                }
            }

            $requirementLabel = $row['required_or_optional'];
            $isRequired = $this->mapRequired($requirementLabel);
            if ($isRequired === null) {
                $labelForReport = trim($requirementLabel);
                $report['ambiguous_requirement_labels'][$labelForReport] =
                    ($report['ambiguous_requirement_labels'][$labelForReport] ?? 0) + 1;
            }
            $notes = $this->nullableText($row['ingredient_notes']);
            $dataQualityStatus = $this->nullableText($row['data_quality_status']);

            if ($beforeErrors !== count($report['errors'])) {
                $this->skip($report, 'recipe_ingredients_import.csv', $sourceId ?: "row {$record}");

                continue;
            }

            $sourceRecord = $sourceIngredientRows[$sourceId] ?? null;
            $sourceIdentifier = $sourceRecord['Source_ID'] ?? ($sourceRowsByRecipe[$recipeCode]['source_id'] ?? null);
            if ($sourceIdentifier === null) {
                $report['missing_foreign_keys'][] = "No source identifier can be resolved for recipe ingredient {$sourceId}";
                $report['errors'][] = end($report['missing_foreign_keys']);
                $this->skip($report, 'recipe_ingredients_import.csv', $sourceId);

                continue;
            }

            $plan['recipe_ingredients'][] = [
                'original_ingredient_id' => $sourceId,
                'recipe_code' => $recipeCode,
                'ingredient_key' => $ingredientKey,
                'values' => [
                    'source_identifier' => $sourceIdentifier,
                    'source_record_identifier' => $sourceId,
                    'original_ingredient_name' => $row['ingredient_name'],
                    'original_quantity_text' => $this->nullableText($row['original_quantity_text']),
                    'original_unit' => $this->nullableText($row['original_unit']),
                    'per_person_quantity_min' => $quantityMin,
                    'per_person_quantity_max' => $quantityMax,
                    'per_person_quantity_description' => $this->nullableText($row['quantity_per_person_description']),
                    'per_person_unit' => $this->nullableText($row['per_person_unit']),
                    'expert_approved_quantity' => $pendingApproval ? null : $approvedQuantity,
                    'expert_approved_unit' => $pendingApproval ? null : $this->nullableText($approvedUnit),
                    'approval_status' => $approvalStatus,
                    'original_requirement_label' => $requirementLabel,
                    'ingredient_preparation' => $this->nullableText($row['ingredient_preparation']),
                    'is_required' => $isRequired,
                    'is_main_ingredient' => $mainIngredient,
                    'ingredient_notes' => $notes,
                    'data_quality_status' => $dataQualityStatus,
                    'quantity_is_derived' => $quantityDerived,
                    'quantity_description_is_estimated' => $quantityDescriptionEstimated,
                    'quantity_depends_on_estimated_yield' => $quantityDependsOnEstimatedYield,
                ],
            ];

        }

        $mainIngredientCounts = [];
        foreach ($plan['recipe_ingredients'] as $row) {
            if ($row['values']['is_main_ingredient'] === true) {
                $mainIngredientCounts[$row['recipe_code']] = ($mainIngredientCounts[$row['recipe_code']] ?? 0) + 1;
            }
        }
        foreach (array_keys($recipesByCode) as $recipeCode) {
            if (($mainIngredientCounts[$recipeCode] ?? 0) !== 1) {
                $report['errors'][] = "Recipe {$recipeCode} must have exactly one main ingredient row.";
            }
        }

        $this->buildIngredientCategoryLinks(
            $rows,
            $ingredientsByKey,
            $ingredientKeysByName,
            $categoryLabelsByKey,
            $report,
            $plan
        );
        $this->validateConflicts($rows, $ingredientsByKey, $categoryLabelsByKey, $conflictKeys, $report);

        $seenInstructionRecipes = [];
        foreach ($rows['recipe_instructions_import.csv'] as $index => $row) {
            $record = $index + 2;
            $recipeCode = trim($row['recipe_id']);
            if (! isset($recipesByCode[$recipeCode])) {
                $report['missing_foreign_keys'][] = "Instructions row {$record} references missing recipe {$recipeCode}";
                $report['errors'][] = end($report['missing_foreign_keys']);
                $this->skip($report, 'recipe_instructions_import.csv', $recipeCode ?: "row {$record}");

                continue;
            }
            if (isset($seenInstructionRecipes[$recipeCode])) {
                $report['duplicate_identifiers'][] = "recipe_instructions_import.csv duplicate recipe_id {$recipeCode}";
                $report['errors'][] = "Multiple instruction source rows found for recipe {$recipeCode}.";
                $this->skip($report, 'recipe_instructions_import.csv', $recipeCode);

                continue;
            }
            $seenInstructionRecipes[$recipeCode] = true;

            $parsed = $this->parseOrderedSteps($row['cooking_steps_ordered']);
            if ($parsed['error'] !== null) {
                $report['instruction_parsing_failures'][] = "{$recipeCode}: {$parsed['error']}";
                $report['errors'][] = "Could not parse ordered instructions for {$recipeCode}: {$parsed['error']}";
                $this->skip($report, 'recipe_instructions_import.csv', $recipeCode);

                continue;
            }

            $plan['recipe_instructions'][] = [
                'recipe_code' => $recipeCode,
                'step_number' => 0,
                'values' => [
                    'is_method_summary' => true,
                    'cooking_method_paragraph' => $this->nullableText($row['cooking_method_paragraph']),
                    'cooking_steps_ordered' => $this->nullableText($row['cooking_steps_ordered']),
                    'instruction_text' => null,
                ],
            ];

            foreach ($parsed['steps'] as $stepNumber => $text) {
                $plan['recipe_instructions'][] = [
                    'recipe_code' => $recipeCode,
                    'step_number' => $stepNumber,
                    'values' => [
                        'is_method_summary' => false,
                        'cooking_method_paragraph' => null,
                        'cooking_steps_ordered' => null,
                        'instruction_text' => $text,
                    ],
                ];
            }
        }

        $this->validateAuditFiles($rows, $recipesByCode, $ingredientKeysByName, $report, $plan);
        $this->validateExistingDatabase($plan, $report);

        $report['category_conflicts'] = count($conflictKeys);
        $report['estimated_values'] = [
            'recipes_with_estimated_times' => count(array_filter(
                $recipeSourceFlags,
                fn (array $flags): bool => $flags['time_estimated_flag'] === true
            )),
            'recipes_with_estimated_yield' => count(array_filter(
                $recipeSourceFlags,
                fn (array $flags): bool => $flags['yield_estimated_flag'] === true
            )),
            'recipe_ingredients_with_derived_quantities' => $this->countTrue($plan['recipe_ingredients'], 'quantity_is_derived'),
            'recipe_ingredients_with_estimated_descriptions' => $this->countTrue($plan['recipe_ingredients'], 'quantity_description_is_estimated'),
            'recipe_ingredients_depending_on_estimated_yield' => $this->countTrue($plan['recipe_ingredients'], 'quantity_depends_on_estimated_yield'),
        ];
        $report['ready_records'] = [
            'recipe_categories' => count($plan['recipe_categories']),
            'ingredient_categories' => count($plan['ingredient_categories']),
            'ingredients' => count($plan['ingredients']),
            'recipes' => count($plan['recipes']),
            'ingredient_category' => count($plan['ingredient_category']),
            'recipe_ingredients' => count($plan['recipe_ingredients']),
            'recipe_instructions' => count($plan['recipe_instructions']),
            'recipe_sources' => count($plan['recipe_sources']),
            'data_quality_issues' => count($plan['data_quality_issues']),
        ];

        return ['directory' => $directory, 'report' => $report, 'plan' => $plan];
    }

    public function parseOrderedSteps(string $original): array
    {
        $text = trim($original);
        if ($text === '') {
            return ['steps' => [], 'error' => 'cooking_steps_ordered is blank'];
        }

        $matches = [];
        $count = preg_match_all('/(?<!\S)([0-9]+)\.\s+/u', $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        if ($count === false || $count === 0 || $matches[0][0][1] !== 0) {
            return ['steps' => [], 'error' => 'expected a numbered list beginning with "1."'];
        }

        $steps = [];
        foreach ($matches as $index => $match) {
            $number = (int) $match[1][0];
            $expected = $index + 1;
            if ($number !== $expected) {
                return ['steps' => [], 'error' => "expected step {$expected}, found {$number}"];
            }

            $contentStart = $match[0][1] + strlen($match[0][0]);
            $contentEnd = $matches[$index + 1][0][1] ?? strlen($text);
            $stepText = trim(substr($text, $contentStart, $contentEnd - $contentStart));
            if ($stepText === '') {
                return ['steps' => [], 'error' => "step {$number} has no text"];
            }

            $steps[$number] = $stepText;
        }

        return ['steps' => $steps, 'error' => null];
    }

    public function commit(array $analysis): array
    {
        $report = $analysis['report'] ?? [];
        if (($report['errors'] ?? []) !== []) {
            throw new RuntimeException('Import is blocked by preflight validation errors.');
        }

        $plan = $analysis['plan'] ?? [];

        return DB::transaction(function () use ($plan): array {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE recipe_categories, ingredient_categories, ingredients, recipes, ingredient_category, recipe_ingredients, recipe_instructions, recipe_sources, data_quality_issues IN SHARE ROW EXCLUSIVE MODE');
            }

            $counts = [];
            $counts['recipe_categories'] = $this->persistSimple(
                RecipeCategory::class,
                $plan['recipe_categories'],
                ['name']
            );
            $counts['ingredient_categories'] = $this->persistSimple(
                IngredientCategory::class,
                $plan['ingredient_categories'],
                ['name']
            );
            $counts['ingredients'] = $this->persistSimple(
                Ingredient::class,
                $plan['ingredients'],
                ['ingredient_key']
            );

            $categoryIds = RecipeCategory::query()->pluck('id', 'name')->all();
            $ingredientCategoryIds = IngredientCategory::query()->pluck('id', 'name')->all();
            $ingredientIds = Ingredient::query()->pluck('id', 'ingredient_key')->all();
            $recipeCounts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];

            foreach ($plan['recipes'] as $row) {
                if (! isset($categoryIds[$row['category_name']])) {
                    throw new RuntimeException("Recipe category was not created: {$row['category_name']}");
                }

                $values = $row['values'];
                $values['recipe_category_id'] = $categoryIds[$row['category_name']];
                $recipeCounts[$this->persistModel(
                    Recipe::class,
                    ['recipe_code' => $row['recipe_code']],
                    $values
                )]++;
            }
            $counts['recipes'] = $recipeCounts;
            $recipeIds = Recipe::query()->pluck('id', 'recipe_code')->all();

            $pivotCounts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($plan['ingredient_category'] as $row) {
                if (! isset($ingredientIds[$row['ingredient_key']])) {
                    throw new RuntimeException("Ingredient key was not created: {$row['ingredient_key']}");
                }
                if (! isset($ingredientCategoryIds[$row['category_name']])) {
                    throw new RuntimeException("Ingredient category was not created: {$row['category_name']}");
                }

                $pivot = [
                    'ingredient_id' => $ingredientIds[$row['ingredient_key']],
                    'ingredient_category_id' => $ingredientCategoryIds[$row['category_name']],
                    'source_identifier' => $row['source_identifier'],
                    'source_record_identifier' => $row['source_record_identifier'],
                    'source_category_text' => $row['source_category_text'],
                ];
                $exists = DB::table('ingredient_category')
                    ->where($pivot)
                    ->exists();

                if ($exists) {
                    $pivotCounts['skipped']++;

                    continue;
                }

                DB::table('ingredient_category')->insert($pivot + [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $pivotCounts['imported']++;
            }
            $counts['ingredient_category'] = $pivotCounts;

            $recipeIngredientCounts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($plan['recipe_ingredients'] as $row) {
                if (! isset($recipeIds[$row['recipe_code']])) {
                    throw new RuntimeException("Recipe code was not created: {$row['recipe_code']}");
                }
                if (! isset($ingredientIds[$row['ingredient_key']])) {
                    throw new RuntimeException("Ingredient key was not created: {$row['ingredient_key']}");
                }

                $values = $row['values'];
                $values['recipe_id'] = $recipeIds[$row['recipe_code']];
                $values['ingredient_id'] = $ingredientIds[$row['ingredient_key']];
                $recipeIngredientCounts[$this->persistModel(
                    RecipeIngredient::class,
                    ['original_ingredient_id' => $row['original_ingredient_id']],
                    $values
                )]++;
            }
            $counts['recipe_ingredients'] = $recipeIngredientCounts;

            $instructionCounts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($plan['recipe_instructions'] as $row) {
                if (! isset($recipeIds[$row['recipe_code']])) {
                    throw new RuntimeException("Recipe code was not created: {$row['recipe_code']}");
                }
                $values = $row['values'];
                $values['recipe_id'] = $recipeIds[$row['recipe_code']];
                $instructionCounts[$this->persistModel(
                    RecipeInstruction::class,
                    ['recipe_id' => $recipeIds[$row['recipe_code']], 'step_number' => $row['step_number']],
                    $values
                )]++;
            }
            $counts['recipe_instructions'] = $instructionCounts;

            $sourceCounts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($plan['recipe_sources'] as $row) {
                if (! isset($recipeIds[$row['recipe_code']])) {
                    throw new RuntimeException("Recipe code was not created: {$row['recipe_code']}");
                }

                $values = $row['values'];
                $values['recipe_id'] = $recipeIds[$row['recipe_code']];
                $values['source_record_identifier'] = $row['source_record_identifier'];
                $sourceCounts[$this->persistModel(
                    RecipeSource::class,
                    [
                        'recipe_id' => $recipeIds[$row['recipe_code']],
                        'source_identifier' => $row['source_identifier'],
                    ],
                    $values
                )]++;
            }
            $counts['recipe_sources'] = $sourceCounts;

            $qualityIssueCounts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
            foreach ($plan['data_quality_issues'] as $row) {
                $values = $row['values'];
                if ($row['recipe_code'] !== null) {
                    if (! isset($recipeIds[$row['recipe_code']])) {
                        throw new RuntimeException("Data-quality issue references missing recipe {$row['recipe_code']}.");
                    }
                    $values['recipe_id'] = $recipeIds[$row['recipe_code']];
                    $values['ingredient_id'] = null;
                } elseif ($row['ingredient_key'] !== null) {
                    if (! isset($ingredientIds[$row['ingredient_key']])) {
                        throw new RuntimeException("Data-quality issue references missing ingredient {$row['ingredient_key']}.");
                    }
                    $values['recipe_id'] = null;
                    $values['ingredient_id'] = $ingredientIds[$row['ingredient_key']];
                } else {
                    throw new RuntimeException('Data-quality issue has no resolved entity reference.');
                }

                $identity = [
                    'source_file' => $values['source_file'],
                    'source_row' => $values['source_row'],
                ];
                $existingIssue = DataQualityIssue::query()->where($identity)->first();
                if ($existingIssue !== null) {
                    $values['resolution_status'] = $existingIssue->resolution_status;
                }

                $qualityIssueCounts[$this->persistModel(
                    DataQualityIssue::class,
                    $identity,
                    $values
                )]++;
            }
            $counts['data_quality_issues'] = $qualityIssueCounts;

            return $counts;
        });
    }

    private function readCsv(string $path, array &$report): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV: {$path}");
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('CSV is empty: '.basename($path));
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        if (count($header) !== count(array_unique($header))) {
            fclose($handle);
            throw new RuntimeException('CSV contains duplicate headers: '.basename($path));
        }

        $rows = [];
        $record = 1;
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $record++;
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($header)) {
                $report['errors'][] = basename($path)." record {$record} has ".count($values)
                    .' cells; expected '.count($header).'.';
                $this->skip($report, basename($path), "record {$record}");

                continue;
            }
            $rows[] = array_combine($header, $values);
        }

        fclose($handle);

        return ['headers' => $header, 'rows' => $rows];
    }

    private function validateMasterRows(
        array $rows,
        array $ingredientsByKey,
        array $categoryLabelsByKey,
        array $recipesByCode,
        array $sourceRowsByRecipe,
        array $instructionRowsByRecipe,
        array &$report,
        array &$sourceIngredientRows
    ): void {
        if (! isset($rows['master_recipe_ingredients.csv'])) {
            return;
        }

        $masterRows = $rows['master_recipe_ingredients.csv'];
        $linkRows = [];
        foreach ($rows['recipe_ingredients_import.csv'] as $row) {
            $linkRows[trim($row['source_ingredient_id'])] = $row;
        }

        $seen = [];
        foreach ($masterRows as $index => $master) {
            $record = $index + 2;
            $id = trim($master['Ingredient_ID']);
            if ($id === '' || isset($seen[$id])) {
                $report['duplicate_identifiers'][] = "master_recipe_ingredients.csv duplicate or blank Ingredient_ID {$id}";
                $report['errors'][] = "master_recipe_ingredients.csv has a duplicate or blank Ingredient_ID at row {$record}.";
                $this->skip($report, 'master_recipe_ingredients.csv', $id ?: "row {$record}");

                continue;
            }
            $seen[$id] = true;
            $sourceIngredientRows[$id] = $master;

            if (! isset($linkRows[$id])) {
                $report['missing_foreign_keys'][] = "Master ingredient row {$id} has no recipe_ingredients_import.csv match.";
                $report['errors'][] = end($report['missing_foreign_keys']);

                continue;
            }

            $link = $linkRows[$id];
            $ingredientKey = trim($link['ingredient_key']);
            $recipeCode = trim($link['recipe_id']);
            $recipe = $recipesByCode[$recipeCode] ?? null;
            $instruction = $instructionRowsByRecipe[$recipeCode] ?? null;
            $source = $sourceRowsByRecipe[$recipeCode] ?? null;
            $checks = [
                'Recipe_ID' => [$master['Recipe_ID'], $recipeCode],
                'Category' => [$master['Category'], $recipe['category'] ?? null],
                'Recipe_Name' => [$master['Recipe_Name'], $recipe['recipe_name'] ?? null],
                'Main_Ingredient' => [$master['Main_Ingredient'], $recipe['main_ingredient'] ?? null],
                'Recipe_Type' => [$master['Recipe_Type'], $recipe['recipe_type'] ?? null],
                'Cooking_Variation' => [$master['Cooking_Variation'], $recipe['cooking_variation'] ?? null],
                'Region' => [$master['Region'], $recipe['region'] ?? null],
                'Recipe_Description' => [$master['Recipe_Description'], $recipe['recipe_description'] ?? null],
                'Ingredient_Name' => [$master['Ingredient_Name'], $link['ingredient_name']],
                'Ingredient_Category' => [$master['Ingredient_Category'], $link['ingredient_category']],
                'Original_Quantity' => [$master['Original_Quantity'], $link['original_quantity_text']],
                'Original_Unit' => [$master['Original_Unit'], $link['original_unit']],
                'Per_Person_Unit' => [$master['Per_Person_Unit'], $link['per_person_unit']],
                'Data_Quality_Status' => [$master['Data_Quality_Status'], $link['data_quality_status']],
                'Required_or_Optional' => [$master['Required_or_Optional'], $link['required_or_optional']],
                'Ingredient_Preparation' => [$master['Ingredient_Preparation'], $link['ingredient_preparation']],
                'Ingredient_Notes' => [$master['Ingredient_Notes'], $link['ingredient_notes']],
                'Cooking_Method_Paragraph' => [$master['Cooking_Method_Paragraph'], $instruction['cooking_method_paragraph'] ?? null],
                'Cooking_Steps_Ordered' => [$master['Cooking_Steps_Ordered'], $instruction['cooking_steps_ordered'] ?? null],
                'Source_ID' => [$master['Source_ID'], $source['source_id'] ?? null],
                'Source_Title' => [$master['Source_Title'], $source['source_title'] ?? null],
                'Source_URL' => [$master['Source_URL'], $source['source_url'] ?? null],
            ];
            foreach ($checks as $column => [$masterValue, $importValue]) {
                if ($importValue === null || (string) $masterValue !== (string) $importValue) {
                    $report['errors'][] = "Master/source mismatch for {$id} in {$column}.";
                }
            }

            $main = strtolower(trim($master['Is_Main_Ingredient']));
            if ($main !== strtolower(trim($link['is_main_ingredient']))) {
                $report['errors'][] = "Master/source mismatch for {$id} in Is_Main_Ingredient.";
            }

            foreach ([
                'Quantity_Derived_Flag' => 'quantity_derived_flag',
                'Quantity_Description_Estimated_Flag' => 'quantity_description_estimated_flag',
                'Quantity_Depends_On_Estimated_Yield_Flag' => 'quantity_depends_on_estimated_yield_flag',
            ] as $masterColumn => $normalizedColumn) {
                if (strtolower(trim($master[$masterColumn])) !== strtolower(trim($link[$normalizedColumn]))) {
                    $report['errors'][] = "Master/source mismatch for {$id} in {$masterColumn}.";
                }
            }

            if (! $this->matchesNormalizedQuantity(
                $master['Quantity_Per_Person_Calculated'],
                $link['quantity_per_person_numeric_min'],
                $link['quantity_per_person_numeric_max'],
                $link['quantity_per_person_description']
            )) {
                $report['errors'][] = "Master/source mismatch for {$id} in Quantity_Per_Person_Calculated.";
            }

            if (stripos($master['Approved_Quantity_Per_Person'], 'pending') !== false) {
                if (stripos($link['approval_status'], 'pending') === false
                    || trim($link['approved_quantity_per_person']) !== ''
                    || trim($link['approved_unit']) !== '') {
                    $report['errors'][] = "Pending expert-validation values were not preserved as nullable for {$id}.";
                }
            }

            foreach ([
                'Preparation_Time_Min' => ['master' => $master['Preparation_Time_Min'], 'source' => $recipe['preparation_time_min'] ?? null],
                'Cooking_Time_Min' => ['master' => $master['Cooking_Time_Min'], 'source' => $recipe['cooking_time_min'] ?? null],
                'Total_Time_Min' => ['master' => $master['Total_Time_Min'], 'source' => $recipe['total_time_min'] ?? null],
                'Source_Yield_People' => ['master' => $master['Source_Yield_People'], 'source' => $recipe['source_yield_people'] ?? null],
            ] as $column => $values) {
                if ($values['source'] === null || ! $this->sameNumericValue($values['master'], $values['source'])) {
                    $report['errors'][] = "Master/source mismatch for {$id} in {$column}.";
                }
            }

            foreach ([
                'Time_Estimated_Flag' => ['master' => $master['Time_Estimated_Flag'], 'source' => $recipe['time_estimated_flag'] ?? null],
                'Yield_Estimated_Flag' => ['master' => $master['Yield_Estimated_Flag'], 'source' => $recipe['yield_estimated_flag'] ?? null],
            ] as $column => $values) {
                if ($values['source'] === null
                    || strtolower(trim($values['master'])) !== strtolower(trim($values['source']))) {
                    $report['errors'][] = "Master/source mismatch for {$id} in {$column}.";
                }
            }

            $anyEstimated = false;
            foreach ([
                $master['Time_Estimated_Flag'],
                $master['Yield_Estimated_Flag'],
                $master['Quantity_Derived_Flag'],
                $master['Quantity_Description_Estimated_Flag'],
                $master['Quantity_Depends_On_Estimated_Yield_Flag'],
            ] as $flag) {
                $anyEstimated = $anyEstimated || strtolower(trim($flag)) === 'true';
            }
            if ($anyEstimated !== (strtolower(trim($master['Any_Estimated_Flag'])) === 'true')) {
                $report['aggregate_flag_inconsistencies'][] = $id;
            }

            if (! isset($recipesByCode[$recipeCode])) {
                $report['missing_foreign_keys'][] = "Master ingredient row {$id} references missing recipe {$recipeCode}.";
                $report['errors'][] = end($report['missing_foreign_keys']);
            }
            if (! isset($ingredientsByKey[$ingredientKey])) {
                $report['missing_foreign_keys'][] = "Master ingredient row {$id} references missing ingredient key {$ingredientKey}.";
                $report['errors'][] = end($report['missing_foreign_keys']);
            }

            $normalizedName = $this->normalizeName($master['Ingredient_Name']);
            $resolvedKey = null;
            foreach ($ingredientsByKey as $key => $ingredient) {
                if ($this->normalizeName($ingredient['ingredient_name']) === $normalizedName) {
                    $resolvedKey = $key;
                    break;
                }
            }
            if ($resolvedKey !== $ingredientKey) {
                $report['errors'][] = "Master ingredient row {$id} resolves to a different ingredient_key.";
            }

            $sourceCategory = trim($master['Ingredient_Category']);
            if (! in_array($sourceCategory, $categoryLabelsByKey[$ingredientKey] ?? [], true)) {
                $report['errors'][] = "Master ingredient row {$id} has a category missing from ingredients_import.csv.";
            }
        }

        if (count($masterRows) !== count($linkRows)) {
            $report['errors'][] = 'master_recipe_ingredients.csv and recipe_ingredients_import.csv have different record counts.';
        }

        foreach ($linkRows as $id => $link) {
            if (! isset($seen[$id])) {
                $report['missing_foreign_keys'][] = "Recipe ingredient row {$id} has no master CSV match.";
                $report['errors'][] = end($report['missing_foreign_keys']);
            }
        }
    }

    private function buildIngredientCategoryLinks(
        array $rows,
        array $ingredientsByKey,
        array $ingredientKeysByName,
        array $categoryLabelsByKey,
        array &$report,
        array &$plan
    ): void {
        if (isset($rows['master_recipe_ingredients.csv'])) {
            $seenLinks = [];
            foreach ($rows['master_recipe_ingredients.csv'] as $index => $master) {
                $nameKey = $this->normalizeName($master['Ingredient_Name']);
                $ingredientKey = $ingredientKeysByName[$nameKey] ?? null;
                $category = trim($master['Ingredient_Category']);
                $recordId = trim($master['Ingredient_ID']);
                $sourceId = trim($master['Source_ID']);

                if ($ingredientKey === null || ! isset($ingredientsByKey[$ingredientKey])) {
                    $report['missing_foreign_keys'][] = "Master category row ".($index + 2)." cannot resolve ingredient {$master['Ingredient_Name']}.";
                    $report['errors'][] = end($report['missing_foreign_keys']);
                    continue;
                }
                if (! in_array($category, $categoryLabelsByKey[$ingredientKey] ?? [], true)) {
                    continue;
                }

                $signature = implode('|', [$ingredientKey, $category, $sourceId, $recordId]);
                if (isset($seenLinks[$signature])) {
                    $report['duplicate_identifiers'][] = "Duplicate source classification {$recordId} / {$category}";
                    $report['errors'][] = "Duplicate ingredient category provenance at master row ".($index + 2).'.';
                    continue;
                }
                $seenLinks[$signature] = true;
                $plan['ingredient_category'][] = [
                    'ingredient_key' => $ingredientKey,
                    'category_name' => $category,
                    'source_identifier' => $sourceId,
                    'source_record_identifier' => $recordId,
                    'source_category_text' => $master['Ingredient_Category'],
                ];
            }

            $actualLabels = [];
            foreach ($plan['ingredient_category'] as $link) {
                $actualLabels[$link['ingredient_key']][$link['category_name']] = true;
            }
            foreach ($categoryLabelsByKey as $key => $labels) {
                $actual = array_keys($actualLabels[$key] ?? []);
                sort($actual);
                $expected = $labels;
                sort($expected);
                if ($actual !== $expected) {
                    $report['errors'][] = "Master category provenance for {$key} does not match ingredients_import.csv category_labels.";
                }
            }

            return;
        }

        foreach ($categoryLabelsByKey as $key => $labels) {
            foreach ($labels as $label) {
                $plan['ingredient_category'][] = [
                    'ingredient_key' => $key,
                    'category_name' => $label,
                    'source_identifier' => 'ingredients_import.csv',
                    'source_record_identifier' => $key,
                    'source_category_text' => $label,
                ];
            }
        }
    }

    private function validateConflicts(
        array $rows,
        array $ingredientsByKey,
        array $categoryLabelsByKey,
        array $conflictKeys,
        array &$report
    ): void {
        $conflictRows = $rows['ingredient_category_conflicts.csv'];
        $seen = [];

        foreach ($conflictRows as $index => $row) {
            $name = $row['ingredient_name'];
            $normalized = $this->normalizeName($name);
            $key = null;
            foreach ($ingredientsByKey as $candidateKey => $ingredient) {
                if ($this->normalizeName($ingredient['ingredient_name']) === $normalized) {
                    $key = $candidateKey;
                    break;
                }
            }

            if ($key === null) {
                $report['errors'][] = "Conflict report row ".($index + 2)." has no matching ingredient.";
                continue;
            }

            $labels = $this->splitCategoryLabels($row['category_labels']);
            $expected = $categoryLabelsByKey[$key] ?? [];
            sort($labels);
            sort($expected);
            if ($labels !== $expected || (int) $row['category_count'] !== count($labels)) {
                $report['errors'][] = "Conflict report for {$name} disagrees with ingredients_import.csv.";
            }
            if (! isset($conflictKeys[$key])) {
                $report['errors'][] = "Conflict report marks {$name}, but ingredients_import.csv does not flag it.";
            }
            $seen[$key] = true;
        }

        if (count($seen) !== count($conflictKeys)) {
            $report['errors'][] = 'ingredient_category_conflicts.csv does not cover every flagged category conflict.';
        }
    }

    private function validateAuditFiles(
        array $rows,
        array $recipesByCode,
        array $ingredientKeysByName,
        array &$report,
        array &$plan
    ): void
    {
        $severities = [];
        foreach ($rows['data_quality_issues.csv'] as $index => $row) {
            $sourceRow = $index + 2;
            $severity = $row['severity'] ?: '(blank)';
            $severities[$severity] = ($severities[$severity] ?? 0) + 1;

            foreach (['severity', 'issue_type', 'record_id', 'details', 'recommended_action'] as $column) {
                if (trim($row[$column]) === '') {
                    $report['errors'][] = "data_quality_issues.csv row {$sourceRow} has a blank {$column}.";
                }
            }

            $issueType = $row['issue_type'];
            if (! isset(self::QUALITY_ISSUE_MAPPINGS[$issueType])) {
                $report['errors'][] = "data_quality_issues.csv row {$sourceRow} has an unmapped issue type: {$issueType}.";

                continue;
            }

            [$entityType, $fieldName] = self::QUALITY_ISSUE_MAPPINGS[$issueType];
            $entityReference = $row['record_id'];
            $recipeCode = null;
            $ingredientKey = null;

            if ($entityType === 'recipe') {
                $recipeCode = trim($entityReference);
                if (! isset($recipesByCode[$recipeCode])) {
                    $report['missing_foreign_keys'][] =
                        "data_quality_issues.csv row {$sourceRow} references missing recipe {$recipeCode}.";
                    $report['errors'][] = end($report['missing_foreign_keys']);

                    continue;
                }
            } else {
                $normalizedName = $this->normalizeName($entityReference);
                $ingredientKey = $ingredientKeysByName[$normalizedName] ?? null;
                if ($ingredientKey === null) {
                    $report['missing_foreign_keys'][] =
                        "data_quality_issues.csv row {$sourceRow} references missing ingredient {$entityReference}.";
                    $report['errors'][] = end($report['missing_foreign_keys']);

                    continue;
                }
            }

            $plan['data_quality_issues'][] = [
                'recipe_code' => $recipeCode,
                'ingredient_key' => $ingredientKey,
                'values' => [
                    'source_file' => 'data_quality_issues.csv',
                    'source_row' => $sourceRow,
                    'entity_type' => $entityType,
                    'entity_reference' => $entityReference,
                    'field_name' => $fieldName,
                    'severity' => $row['severity'],
                    'issue_type' => $issueType,
                    'description' => $row['details'],
                    'recommended_action' => $row['recommended_action'],
                    'resolution_status' => 'unresolved',
                ],
            ];
        }
        $report['quality_issues_by_severity'] = $severities;
    }

    private function validateExistingDatabase(array $plan, array &$report): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $report['errors'][] = "Required database table is missing: {$table}";
            }
        }

        if (Schema::hasTable('recipe_ingredients')) {
            if (! Schema::hasColumn('recipe_ingredients', 'original_requirement_label')) {
                $report['errors'][] = 'Required database column is missing: recipe_ingredients.original_requirement_label';
            }

            if (DB::connection()->getDriverName() === 'pgsql') {
                $schema = DB::scalar('SELECT current_schema()');
                foreach ([
                    'per_person_quantity_min',
                    'per_person_quantity_max',
                    'expert_approved_quantity',
                ] as $column) {
                    $scale = DB::table('information_schema.columns')
                        ->where('table_schema', $schema)
                        ->where('table_name', 'recipe_ingredients')
                        ->where('column_name', $column)
                        ->value('numeric_scale');
                    if ($scale === null || (int) $scale < 5) {
                        $report['errors'][] =
                            "Database column recipe_ingredients.{$column} must support at least five decimal places.";
                    }
                }
            }
        }

        if ($report['errors'] !== []) {
            return;
        }

        $recipeCodes = array_column($plan['recipes'], 'recipe_code');
        $recipeCodeCounts = DB::table('recipes')
            ->whereIn('recipe_code', $recipeCodes)
            ->select('recipe_code')
            ->selectRaw('COUNT(*) AS row_count')
            ->groupBy('recipe_code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('row_count', 'recipe_code');
        foreach ($recipeCodeCounts as $code => $count) {
            $report['duplicate_identifiers'][] = "Existing database has {$count} recipes with recipe_code {$code}";
            $report['errors'][] = "Existing recipe_code is not unique: {$code}";
        }

        $sourceIds = array_column($plan['recipe_ingredients'], 'original_ingredient_id');
        $sourceIdCounts = DB::table('recipe_ingredients')
            ->whereIn('original_ingredient_id', $sourceIds)
            ->select('original_ingredient_id')
            ->selectRaw('COUNT(*) AS row_count')
            ->groupBy('original_ingredient_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('row_count', 'original_ingredient_id');
        foreach ($sourceIdCounts as $id => $count) {
            $report['duplicate_identifiers'][] = "Existing database has {$count} recipe ingredient rows with original_ingredient_id {$id}";
            $report['errors'][] = "Existing original_ingredient_id is not unique: {$id}";
        }

        $qualityIssueRows = array_column(
            array_column($plan['data_quality_issues'], 'values'),
            'source_row'
        );
        $qualityIssueCounts = DB::table('data_quality_issues')
            ->where('source_file', 'data_quality_issues.csv')
            ->whereIn('source_row', $qualityIssueRows)
            ->select('source_row')
            ->selectRaw('COUNT(*) AS row_count')
            ->groupBy('source_row')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('row_count', 'source_row');
        foreach ($qualityIssueCounts as $sourceRow => $count) {
            $report['duplicate_identifiers'][] =
                "Existing database has {$count} data-quality issues for data_quality_issues.csv row {$sourceRow}";
            $report['errors'][] = "Existing data-quality source row is not unique: {$sourceRow}";
        }

        $expectedRecipeIngredients = [];
        foreach ($plan['recipe_ingredients'] as $row) {
            $expectedRecipeIngredients[$row['original_ingredient_id']] = [
                'recipe_code' => $row['recipe_code'],
                'ingredient_key' => $row['ingredient_key'],
            ];
        }
        $existingRecipeIngredients = DB::table('recipe_ingredients')
            ->join('recipes', 'recipes.id', '=', 'recipe_ingredients.recipe_id')
            ->join('ingredients', 'ingredients.id', '=', 'recipe_ingredients.ingredient_id')
            ->whereIn('recipe_ingredients.original_ingredient_id', array_keys($expectedRecipeIngredients))
            ->get([
                'recipe_ingredients.original_ingredient_id',
                'recipes.recipe_code',
                'ingredients.ingredient_key',
            ]);
        foreach ($existingRecipeIngredients as $existing) {
            $expected = $expectedRecipeIngredients[$existing->original_ingredient_id];
            if ($existing->recipe_code !== $expected['recipe_code']
                || $existing->ingredient_key !== $expected['ingredient_key']) {
                $message = "Existing source ingredient ID {$existing->original_ingredient_id} is attached to a different recipe or ingredient.";
                $report['duplicate_identifiers'][] = $message;
                $report['errors'][] = $message;
            }
        }

        $ingredientNamesInDb = DB::table('ingredients')->get(['ingredient_key', 'name']);
        $normalizedNames = [];
        foreach ($ingredientNamesInDb as $existing) {
            $normalized = $this->normalizeName($existing->name);
            if (isset($normalizedNames[$normalized]) && $normalizedNames[$normalized] !== $existing->ingredient_key) {
                $report['duplicate_identifiers'][] = "Existing ingredients normalize to duplicate names: {$existing->name}";
                $report['errors'][] = "Existing normalized ingredient name is not unique: {$existing->name}";
            }
            $normalizedNames[$normalized] = $existing->ingredient_key;
        }
        foreach ($plan['ingredients'] as $ingredient) {
            $normalized = $this->normalizeName($ingredient['values']['name']);
            if (isset($normalizedNames[$normalized])
                && $normalizedNames[$normalized] !== $ingredient['ingredient_key']) {
                $report['duplicate_identifiers'][] = "Ingredient {$ingredient['ingredient_key']} collides with existing normalized name {$ingredient['values']['name']}";
                $report['errors'][] = "Existing ingredient name would conflict with {$ingredient['values']['name']}.";
            }
        }

        $sourcePairs = [];
        foreach ($plan['recipe_sources'] as $source) {
            $sourcePairs[$source['recipe_code'].'|'.$source['source_identifier']] = $source;
        }
        if ($sourcePairs !== []) {
            $existingSources = DB::table('recipe_sources')
                ->join('recipes', 'recipes.id', '=', 'recipe_sources.recipe_id')
                ->whereIn('recipes.recipe_code', array_column($plan['recipe_sources'], 'recipe_code'))
                ->whereIn('recipe_sources.source_identifier', array_column($plan['recipe_sources'], 'source_identifier'))
                ->select('recipes.recipe_code', 'recipe_sources.source_identifier')
                ->selectRaw('COUNT(*) AS row_count')
                ->groupBy('recipes.recipe_code', 'recipe_sources.source_identifier')
                ->havingRaw('COUNT(*) > 1')
                ->get();
            foreach ($existingSources as $existing) {
                if (! isset($sourcePairs[$existing->recipe_code.'|'.$existing->source_identifier])) {
                    continue;
                }
                $message = "Existing source identity {$existing->recipe_code}/{$existing->source_identifier} is duplicated.";
                $report['duplicate_identifiers'][] = $message;
                $report['errors'][] = $message;
            }
        }

        $expectedSteps = [];
        foreach ($plan['recipe_instructions'] as $instruction) {
            $expectedSteps[$instruction['recipe_code']][$instruction['step_number']] = true;
        }
        if ($expectedSteps !== []) {
            $existingInstructions = DB::table('recipe_instructions')
                ->join('recipes', 'recipes.id', '=', 'recipe_instructions.recipe_id')
                ->whereIn('recipes.recipe_code', array_keys($expectedSteps))
                ->get(['recipes.recipe_code', 'recipe_instructions.step_number']);
            foreach ($existingInstructions as $existing) {
                if (! isset($expectedSteps[$existing->recipe_code][$existing->step_number])) {
                    $report['errors'][] = "Existing instruction step {$existing->step_number} for {$existing->recipe_code} is not in this source package; it will not be deleted.";
                }
            }
        }
    }

    private function persistSimple(string $modelClass, array $rows, array $identity): array
    {
        $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $counts[$this->persistModel($modelClass, array_intersect_key($row, array_flip($identity)), $row['values'])]++;
        }

        return $counts;
    }

    private function persistModel(string $modelClass, array $identity, array $values): string
    {
        /** @var Model $model */
        $query = $modelClass::query();
        foreach ($identity as $column => $value) {
            $query->where($column, $value);
        }

        $model = $query->lockForUpdate()->first();
        if ($model === null) {
            $model = new $modelClass();
            $model->fill($identity + $values);
            $model->save();

            return 'imported';
        }

        $model->fill($values);
        if (! $model->isDirty()) {
            return 'skipped';
        }

        $model->save();

        return 'updated';
    }

    private function parseNumber(
        string $raw,
        int $precision,
        int $scale,
        string $file,
        int $record,
        string $column,
        array &$report,
        bool $mustBePositive = false
    ): ?string {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (! $this->isNumericText($value, $precision, $scale)) {
            $this->invalidNumeric($report, $file, $record, $column, $raw);

            return null;
        }

        $numeric = (float) $value;
        if ($numeric < 0 || ($mustBePositive && $numeric <= 0)) {
            $this->invalidNumeric($report, $file, $record, $column, $raw);

            return null;
        }

        if ($scale === 0) {
            return (string) (int) $numeric;
        }

        return $value;
    }

    private function isNumericText(string $value, int $precision, int $scale): bool
    {
        $value = trim($value);
        if (! preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $value)) {
            return false;
        }

        $unsigned = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $wholeDigits = ltrim($whole, '0');
        $fractionDigits = rtrim($fraction, '0');

        if (strlen($wholeDigits) > ($precision - $scale)
            || strlen($fractionDigits) > $scale) {
            return false;
        }

        if (str_starts_with($value, '-')) {
            return (float) $value >= 0;
        }

        return true;
    }

    private function invalidNumeric(array &$report, string $file, int $record, string $column, string $value): void
    {
        $report['invalid_numeric_values'][] = "{$file} record {$record} ({$column}): ".substr($value, 0, 100);
        $report['errors'][] = "Invalid numeric value in {$file} record {$record}, column {$column}.";
        $this->skip($report, $file, "record {$record}");
    }

    private function parseBoolean(string $raw, string $file, int $record, string $column, array &$report): ?bool
    {
        $value = strtolower(trim($raw));
        if ($value === '') {
            return null;
        }
        if (in_array($value, ['true', '1', 'yes', 'y'], true)) {
            return true;
        }
        if (in_array($value, ['false', '0', 'no', 'n'], true)) {
            return false;
        }

        $report['errors'][] = "Invalid boolean value in {$file} record {$record}, column {$column}: {$raw}";
        $this->skip($report, $file, "record {$record}");

        return null;
    }

    private function mapRequired(string $value): ?bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
        if (in_array($normalized, [
            'required',
            'essential',
            'essential in source',
            'required for paste',
            'required for fresh jackfruit',
            'required for roasted-paste version',
        ], true)) {
            return true;
        }
        if (in_array($normalized, [
            'optional',
            'optional/omit for vegetarian',
            'optional/adjustable',
        ], true)) {
            return false;
        }

        return null;
    }

    private function splitCategoryLabels(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $labels = array_map('trim', explode('|', $value));

        return array_values(array_unique(array_filter($labels, static fn (string $label): bool => $label !== '')));
    }

    private function normalizeName(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return function_exists('mb_strtolower') ? mb_strtolower($collapsed, 'UTF-8') : strtolower($collapsed);
    }

    private function sameNumericValue(string $first, string $second): bool
    {
        if (trim($first) === '' || trim($second) === '') {
            return trim($first) === trim($second);
        }

        return is_numeric($first) && is_numeric($second) && (float) $first === (float) $second;
    }

    private function matchesNormalizedQuantity(string $source, string $minimum, string $maximum, string $description): bool
    {
        $source = trim($source);
        $minimum = trim($minimum);
        $maximum = trim($maximum);
        $description = trim($description);

        if (is_numeric($source)) {
            return $minimum !== ''
                && $maximum !== ''
                && round((float) $minimum, 4, PHP_ROUND_HALF_UP) === round((float) $source, 4, PHP_ROUND_HALF_UP)
                && round((float) $maximum, 4, PHP_ROUND_HALF_UP) === round((float) $source, 4, PHP_ROUND_HALF_UP)
                && $description === '';
        }

        if (preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(?:-|–|—)\s*([0-9]+(?:\.[0-9]+)?)\s*$/u', $source, $range) === 1) {
            return $minimum !== ''
                && $maximum !== ''
                && round((float) $minimum, 4, PHP_ROUND_HALF_UP) === round((float) $range[1], 4, PHP_ROUND_HALF_UP)
                && round((float) $maximum, 4, PHP_ROUND_HALF_UP) === round((float) $range[2], 4, PHP_ROUND_HALF_UP)
                && $description === '';
        }

        return $minimum === '' && $maximum === '' && $description === $source;
    }

    private function nullableText(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function skip(array &$report, string $file, string $identifier): void
    {
        $report['skipped_records'][$file] ??= [];
        if (! in_array($identifier, $report['skipped_records'][$file], true)) {
            $report['skipped_records'][$file][] = $identifier;
        }
    }

    private function countTrue(array $rows, string $column): int
    {
        return count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['values'][$column] ?? false) === true
        ));
    }
}
