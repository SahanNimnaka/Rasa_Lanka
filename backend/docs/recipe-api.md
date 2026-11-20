# RasaLanka Recipe & Ingredient API

This document describes the public, read-only recipe and ingredient endpoints under `/api/v1`.

All endpoints use the existing API envelope from `App\Http\Controllers\Api\V1\Controller`. No authentication is required. No CSRF bootstrap is needed for these `GET` endpoints.

## Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/recipe-categories` | List recipe categories. |
| `GET` | `/api/v1/ingredient-categories` | List ingredient categories. |
| `GET` | `/api/v1/ingredients` | List ingredients. |
| `GET` | `/api/v1/ingredients/{id}` | Show one ingredient. |
| `GET` | `/api/v1/recipes` | List recipe summaries. |
| `GET` | `/api/v1/recipes/{id}` | Show one full recipe. |

## Common response envelope

Success:

```json
{
  "success": true,
  "data": {},
  "meta": {
    "api_version": "v1"
  }
}
```

Paginated success:

```json
{
  "success": true,
  "data": [],
  "links": {
    "first": "http://127.0.0.1:8000/api/v1/recipes?page=1",
    "last": "http://127.0.0.1:8000/api/v1/recipes?page=2",
    "prev": null,
    "next": "http://127.0.0.1:8000/api/v1/recipes?page=2"
  },
  "meta": {
    "api_version": "v1",
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 20,
      "last_page": 2,
      "from": 1,
      "to": 15
    }
  }
}
```

Error:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The given data was invalid.",
    "details": {
      "sort": ["The selected sort is invalid."]
    }
  },
  "meta": {
    "api_version": "v1"
  }
}
```

Unknown IDs return `404`:

```json
{
  "success": false,
  "error": {
    "code": "NOT_FOUND",
    "message": "The requested API resource was not found."
  },
  "meta": {
    "api_version": "v1"
  }
}
```

## Pagination

All list endpoints accept:

| Parameter | Rules | Default |
|---|---|---|
| `page` | integer, minimum `1` | `1` |
| `per_page` | integer, `1`–`100` | `15` |

Example:

```text
GET /api/v1/recipes?page=2&per_page=10
```

## `GET /api/v1/recipe-categories`

Additional parameters:

| Parameter | Rules | Description |
|---|---|---|
| `search` | string, max 120 | Case-insensitive search over `name` and `description`. |
| `include` | allowlist: `recipes_count` | Accepts comma-separated values. Adds `recipes_count`. |

Ordering is stable: `name` ascending, then `id` ascending.

Example:

```text
GET /api/v1/recipe-categories?search=curry&include=recipes_count
```

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Curry",
      "description": "Warm curries",
      "recipes_count": 5
    }
  ],
  "meta": {
    "api_version": "v1"
  }
}
```

## `GET /api/v1/ingredient-categories`

Additional parameters:

| Parameter | Rules | Description |
|---|---|---|
| `search` | string, max 120 | Case-insensitive search over `name` and `description`. |
| `include` | allowlist: `ingredients_count` | Accepts comma-separated values. Adds `ingredients_count`. |

Ordering is stable: `name` ascending, then `id` ascending.

Example:

```text
GET /api/v1/ingredient-categories?search=spice&include=ingredients_count
```

## `GET /api/v1/ingredients`

Additional parameters:

| Parameter | Rules | Description |
|---|---|---|
| `search` | string, max 200 | Case-insensitive search over `name`, `ingredient_key`, and `description`. |
| `ingredient_category_id` | existing `ingredient_categories.id` | Filters through the ingredient-category relationship. |
| `include` | allowlist: `categories`, `recipes_count` | Accepts comma-separated values. |

- `categories` includes each provenance row from `ingredient_category`. Provenance rows are not deduplicated by category pair.
- `recipes_count` is a distinct recipe count and does not load recipe records.
- Ordering is stable: `name` ascending, then `id` ascending.

Example:

```text
GET /api/v1/ingredients?search=pepper&ingredient_category_id=3&include=categories,recipes_count
```

```json
{
  "success": true,
  "data": [
    {
      "id": 42,
      "name": "Black Pepper",
      "ingredient_key": "black_pepper",
      "description": "Aromatic spice",
      "categories": [
        {
          "id": 12,
          "name": "Spice",
          "description": "Aromatic spices",
          "provenance": {
            "source_identifier": "FIS-001",
            "source_record_identifier": "FIS-001-ING-004",
            "source_category_text": "Spice"
          }
        }
      ],
      "recipes_count": 7
    }
  ],
  "meta": {
    "api_version": "v1"
  }
}
```

## `GET /api/v1/ingredients/{id}`

Additional parameter:

| Parameter | Rules | Description |
|---|---|---|
| `include` | allowlist: `categories`, `recipe_ingredients`, `data_quality_issues` | Accepts comma-separated values. |

Ingredient categories and recipe-ingredient records are loaded by default. `data_quality_issues` is included when requested. Recipe identity is included with each recipe-ingredient record.

Example:

```text
GET /api/v1/ingredients/42?include=categories,recipe_ingredients,data_quality_issues
```

## `GET /api/v1/recipes`

Additional parameters:

| Parameter | Rules | Description |
|---|---|---|
| `search` | string, max 255 | Case-insensitive search over `name`, `recipe_code`, `main_ingredient`, `description`, `recipe_type`, and `region`. |
| `recipe_category_id` | existing `recipe_categories.id` | Exact category filter. |
| `ingredient_id` | existing `ingredients.id` | Filters recipes through `recipe_ingredients`. |
| `recipe_type` | string, max 120 | Exact filter. |
| `region` | string, max 160 | Exact filter. |
| `sort` | allowlist: `name`, `created_at`, `id` | Ascending sort. Defaults to `id`. Secondary ordering is always `id`. |

The list returns recipe summaries, category, and counts only. It does not include full recipe ingredients, instructions, or sources. The `include` parameter is not accepted on recipe endpoints; supplying it returns `422 VALIDATION_FAILED`.

Example:

```text
GET /api/v1/recipes?search=curry&recipe_category_id=1&ingredient_id=42&sort=name
```

```json
{
  "success": true,
  "data": [
    {
      "id": 7,
      "recipe_category_id": 1,
      "recipe_code": "FIS-001",
      "name": "Fish Curry",
      "recipe_type": "Wet curry",
      "region": "Sri Lankan",
      "time_is_estimated": false,
      "yield_is_estimated": false,
      "time_arithmetic_status": "MATCH",
      "recipe_category": {
        "id": 1,
        "name": "Fish"
      },
      "recipe_ingredients_count": 14,
      "recipe_instructions_count": 8,
      "recipe_sources_count": 1,
      "data_quality_issues_count": 0
    }
  ],
  "meta": {
    "api_version": "v1"
  }
}
```

## `GET /api/v1/recipes/{id}`

Returns the full recipe with:

- recipe category
- recipe ingredients and ingredient identity
- original and per-person quantity fields
- approval and estimation flags
- method summary (`step_number = 0`)
- numbered cooking instructions (`step_number >= 1`)
- recipe sources
- data-quality issues

The `include` parameter is not accepted on this endpoint; supplying it returns `422 VALIDATION_FAILED`.

Example:

```text
GET /api/v1/recipes/7
```

```json
{
  "success": true,
  "data": {
    "id": 7,
    "name": "Fish Curry",
    "method_summary": {
      "step_number": 0,
      "is_method_summary": true,
      "instruction_text": null
    },
    "instructions": [
      {
        "step_number": 1,
        "is_method_summary": false,
        "instruction_text": "First step"
      }
    ],
    "recipe_ingredients": [
      {
        "ingredient": {
          "id": 42,
          "name": "Black Pepper",
          "ingredient_key": "black_pepper"
        },
        "original_quantity_text": "2 teaspoons",
        "original_unit": "teaspoon",
        "per_person_quantity_min": "0.28571",
        "per_person_quantity_max": "0.28571",
        "per_person_unit": "teaspoon",
        "approval_status": "Pending expert validation",
        "expert_approved_quantity": null,
        "expert_approved_unit": null,
        "quantity_is_derived": true
      }
    ],
    "recipe_sources": [],
    "data_quality_issues": []
  },
  "meta": {
    "api_version": "v1"
  }
}
```

## Quantity approval semantics

- All current recipe-ingredient rows have `approval_status = "Pending expert validation"`.
- `expert_approved_quantity` and `expert_approved_unit` are returned as `null` until an expert actually approves them.
- Derived and estimated values are never presented as expert-approved values.
- No unit conversion or quantity recalculation occurs during serialization.
- Decimal database values are returned as strings to preserve five-decimal precision.

## Read-only guarantees

- Only `GET` endpoints are exposed for recipes, ingredients, and categories.
- No write endpoints, migrations, importer changes, or schema changes are part of this API surface.
- Ingredient-category provenance rows are preserved, including repeated ingredient/category pairs with different source records.
- Step 0 is preserved as the method summary; numbered steps are not renumbered.
- Data-quality issues are included in recipe detail responses and are not silently discarded.
- Repeated `(recipe_id, ingredient_id)` rows are preserved; no deduplication is applied.
