-- RasaLanka: temporary staging import only.
-- This script does NOT create or alter any permanent database table.
-- Run from the extracted package directory using psql:
--   psql -d YOUR_DATABASE_NAME -f postgresql_staging_import.sql
-- The temporary table disappears when this database session ends.

BEGIN;

CREATE TEMP TABLE rasalanka_recipe_staging (
    category TEXT,
    recipe_id TEXT,
    recipe_name TEXT,
    main_ingredient TEXT,
    recipe_type TEXT,
    cooking_variation TEXT,
    region TEXT,
    recipe_description TEXT,
    preparation_time_min INTEGER,
    cooking_time_min INTEGER,
    total_time_min INTEGER,
    source_yield_people NUMERIC(8,2),
    cooking_method_paragraph TEXT,
    cooking_steps_ordered TEXT,
    ingredient_id TEXT,
    ingredient_name TEXT,
    ingredient_category TEXT,
    is_main_ingredient BOOLEAN,
    original_quantity TEXT,
    original_unit TEXT,
    quantity_per_person_calculated TEXT,
    per_person_unit TEXT,
    approved_quantity_per_person TEXT,
    approved_unit TEXT,
    ingredient_preparation TEXT,
    required_or_optional TEXT,
    ingredient_notes TEXT,
    source_id TEXT,
    source_title TEXT,
    source_url TEXT,
    data_quality_status TEXT,
    time_estimated_flag BOOLEAN,
    yield_estimated_flag BOOLEAN,
    quantity_derived_flag BOOLEAN,
    quantity_description_estimated_flag BOOLEAN,
    quantity_depends_on_estimated_yield_flag BOOLEAN,
    any_estimated_flag BOOLEAN
);

\copy rasalanka_recipe_staging FROM 'master_recipe_ingredients.csv' WITH (FORMAT csv, HEADER true, ENCODING 'UTF8');

-- Basic import checks
SELECT COUNT(*) AS imported_ingredient_rows FROM rasalanka_recipe_staging;
SELECT COUNT(DISTINCT recipe_id) AS unique_recipes FROM rasalanka_recipe_staging;
SELECT COUNT(DISTINCT ingredient_id) AS unique_recipe_ingredient_ids FROM rasalanka_recipe_staging;
SELECT COUNT(*) AS rows_with_estimate_flags
FROM rasalanka_recipe_staging
WHERE any_estimated_flag = TRUE;

-- Any output here indicates a recipe does not have exactly one main ingredient row.
SELECT recipe_id,
       COUNT(*) FILTER (WHERE is_main_ingredient = TRUE) AS main_ingredient_rows
FROM rasalanka_recipe_staging
GROUP BY recipe_id
HAVING COUNT(*) FILTER (WHERE is_main_ingredient = TRUE) <> 1;

-- Time arithmetic exceptions are intentionally reported, not auto-corrected.
SELECT DISTINCT recipe_id, preparation_time_min, cooking_time_min, total_time_min
FROM rasalanka_recipe_staging
WHERE total_time_min <> preparation_time_min + cooking_time_min
ORDER BY recipe_id;

COMMIT;
