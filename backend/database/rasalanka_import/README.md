# RasaLanka – PostgreSQL Import Package

## Contents

- `master_recipe_ingredients.csv` – one row per recipe ingredient; original 30 workbook fields plus category and explicit estimate/provenance flags. This is the primary PostgreSQL staging import.
- `recipes_import.csv` – 20 unique recipe-level records.
- `ingredients_import.csv` – 116 unique ingredient names with stable text keys; category disagreements are not silently resolved.
- `recipe_ingredients_import.csv` – 337 recipe-to-ingredient associations with numeric per-person quantity fields separated from qualitative descriptions.
- `recipe_instructions_import.csv` – one instruction record per recipe.
- `recipe_sources_import.csv` – source metadata per recipe.
- `ingredient_categories_import.csv` – category lookup values.
- `ingredient_category_conflicts.csv` – the seven ingredient names whose category labels differ across workbooks.
- `data_quality_issues.csv` – estimates and review items.
- `audit_summary.csv` – audit metrics.
- `data_dictionary.csv` – column type and handling guide.
- `RasaLanka_Master_Dataset_Audit.xlsx` – review workbook with all records and audit sheets.
- `postgresql_staging_import.sql` – imports the master CSV into a TEMP table only.

## Dataset audit

- Source workbooks: 8
- Categories: 8
- Unique recipes: 20
- Recipe-ingredient records: 337
- Distinct ingredient names: 116
- Blank cells in the consolidated source dataset: 0 (literal values such as `N/A` are retained as text)
- Duplicate `Ingredient_ID` values: 0
- Every recipe has exactly one `Is_Main_Ingredient = TRUE` row.
- All eight workbooks use the same 30-column schema and column order.

## Important findings to review

1. **Estimated serving yields:** `POT-005`, `FIS-003`, `JAC-002`, and `PAR-002` currently use an assumed yield of 4 people. The POT-005 yield had been filled in an earlier completion but its estimate was not explicitly flagged; this package adds the flag.
2. **Estimated preparation/cooking times:** `BRI-001`, `BRI-002`, `BRI-003`, `CHI-001`, `FIS-001`, `FIS-003`, `JAC-002`, `PAR-001`, `PAR-002`, `POT-005`, and `PUM-002`.
3. **Time arithmetic mismatches:** `BRI-003` has 15 + 30 = 45 minutes but `Total_Time_Min` is 30; `FIS-002` has 10 + 20 = 30 minutes but `Total_Time_Min` is 80. The total may include a rest/marinating period, but this needs source confirmation.
4. **Ingredient category conflicts:** Black peppercorns, Ceylon cinnamon, cloves, cumin seeds, fennel seeds, mustard seeds, and raw rice have more than one category label.
5. **Expert-approved quantities:** None of the 337 ingredient rows has an expert-approved quantity. The wide master CSV retains the source workbook's `Pending expert validation` text. In `recipe_ingredients_import.csv`, approved numeric values are intentionally NULL and `approval_status` is `Pending expert validation`. Do not replace these with estimated values or claim expert approval.

## PostgreSQL staging import

1. Extract this package into a folder.
2. Open a terminal in that folder.
3. Run:

   `psql -d YOUR_DATABASE_NAME -f postgresql_staging_import.sql`

4. The script creates a temporary table named `rasalanka_recipe_staging`, loads `master_recipe_ingredients.csv`, and runs integrity queries.
5. The temporary table is automatically removed when the psql session ends. No permanent tables, migrations, constraints, or existing architecture are changed.

The normalized CSVs are prepared as data exports for mapping to the already-approved `recipes`, `ingredients`, `recipe_ingredients`, `recipe_instructions`, and `recipe_sources` tables. Exact INSERT/upsert statements should be generated against the actual Laravel migrations/current PostgreSQL schema, not guessed from workbook headings.
