# Step 8B B1 — Decision-Aware Validation Engine

## Purpose
Standalone B1 validator for Step 8A-compatible `human-recognition-v1` records with Step 8B B0 decision gates. It validates records, applies decision-register constraints, and emits machine-readable reports. It does **not** modify source records, taxonomy, mapping decisions, inventory, dataset images, or Step 8A artifacts.

## Implementation
- Script: `research/phase-13.3/annotation_pipeline_step8b/scripts/validate_step8b_records.py`
- Tests: `research/phase-13.3/annotation_pipeline_step8b/tests/test_validate_step8b_records.py`
- Default reports: `research/phase-13.3/annotation_pipeline_step8b/reports/validation/`

## Inputs (read-only)
- Records: JSON file or directory (`--records`). Directory scan loads `*.json` recursively, excluding `*.template.json`. Malformed JSON files are reported as `E-LOAD` and do not terminate the batch.
- Schema: `research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json`
- Inventory: `research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv`
- Taxonomy: `research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json`
- Decisions: `research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json`

## B1 Compatibility: `supersedes_record_id`

`supersedes_record_id` is **not** part of the Step 8A `human-recognition-v1` schema. B1 accepts it as a documented compatibility metadata field:
- Removed only from the in-memory schema-validation copy before v1 schema validation (source record never mutated).
- Type/shape checked by B1: when present, must be a non-empty string; invalid type/null/empty => `E-SCHEMA`.
- Reported in per-record result as `b1_compat_fields_ignored_for_schema`.
- Semantic supersession validation is always applied.

No other unknown extra fields are broadly ignored; Step 8A schema `additionalProperties: false` remains enforced.

## Supersession Validation Rules

Supersession references are extracted from `supersedes_record_id` (or `step8b.supersedes_record_id` when present). Invalid relationships are reported as `E-SUPERSESSION` and block canonical export.

| Case | Behavior |
|---|---|
| Nonexistent target record ID | `E-SUPERSESSION` |
| Non-string/empty/null supersedes value | `E-SCHEMA` (compat field type check) |
| Self-referencing supersession | `E-SUPERSESSION` |
| Target record ID exists but is duplicated | `E-SUPERSESSION` (target not unique) |
| Different image scope / partial overlap | `E-SUPERSESSION` (conservative B1 rule) |
| Individual <-> group cross-scope | `E-SUPERSESSION` unless both scopes are identical single-image scope |
| Empty/malformed image scope on either side | `E-SUPERSESSION` (cannot prove safe supersession) |
| Multiple records superseding same target | `E-SUPERSESSION` on each superseding record |
| Circular supersession chain (direct/longer) | `E-SUPERSESSION` on records in cycle |
| Valid supersession target | Target receives `X-SUPERSEDED`; target not canonically exportable |

### B1 scope rule (conservative)

B0 requires preservation of group-vs-individual semantics and prohibits unsafe conversion. Because B0 does not fully specify every cross-scope lifecycle case, B1 applies a conservative rule:

- `individual -> individual`: allowed only when image IDs are identical.
- `group -> group`: allowed only when image ID sets are identical (no partial overlap).
- `individual <-> group`: allowed only when both represent the exact same single-image scope; otherwise rejected as `E-SUPERSESSION`.
- Any partial overlap is rejected.
- Empty/malformed scope on either side is rejected.

This is a deliberate B1 safety choice; schema/version/lifecycle policy should be reviewed before B2.

### Export/blocking safety

- Records participating in invalid supersession relations receive `X-INVALID-SUPERSESSION` and cannot be canonically exported.
- The referring record is invalid for an invalid supersession attempt; the target is **not** penalized merely because another record made an invalid attempt (target remains governed by its own validity/conflicts/supersession state).
- Superseded records are excluded from active individual conflict detection to avoid stale conflicts, but remain export-blocked (`X-SUPERSEDED`) and never become canonical ground truth.
- Supersession chains are allowed only when every link is valid; records in invalid/circular chains are export-blocked.

## Pending-Label Enforcement

Decision register is authoritative for approved aliases and pending canonical mappings. B1 does not invent canonical IDs or approve pending mappings.

### Pending mappings (Garlic/Gralic, Pepper Corn/Paper Corn)
- **ingredient_labels (canonical approval attempt):** observed pending label or candidate canonical ID (`garlic_clove`, `black_pepper_whole`, `black_pepper_ground`) in pending folder context => `E-PENDING-AS-CANONICAL` + `W-PENDING-MAPPING` + `X-PENDING-CANONICAL`. Export blocked.
- **uncertain_labels (valid uncertainty reporting):** pending observed/candidate labels => `W-PENDING-MAPPING` + `X-PENDING-CANONICAL` + `X-UNRESOLVED-UNCERTAIN`. No `E-PENDING-AS-CANONICAL`; record may be valid but **not export-eligible** until resolved.
- **out_of_scope_labels:** pending observed/candidate labels cannot be silently reclassified as out-of-scope => `E-PENDING-AS-OUT-OF-SCOPE` + `W-PENDING-MAPPING` + `X-PENDING-CANONICAL`.
- **Group annotations:** same label rules apply; group records always receive `X-GROUP-SCOPE` and never become individual image ground truth.

### Approved alias
- `Curry Leaf` resolves to canonical `curry_leaves` in validator output (`resolved_ingredient_labels`, `alias_resolutions`) with decision-register reference and `preserve_original_label=true`.
- Source records are not modified. Alias resolution does not bypass confirmation/provenance/export-block rules. Pending mappings never resolve through alias path.

## Validation / Export Rules

### Core checks
- **Schema:** Step 8A v1 schema via Draft 2020-12; errors => `E-SCHEMA`. B1 compat field `supersedes_record_id` is type-checked before schema validation of remaining fields.
- **Load/parse:** malformed JSON => `E-LOAD`, `X-INVALID`; batch continues.
- **Inventory linkage:** `image_id` must exist; `relative_path`/folder/filename must match inventory row; mismatches => `E-LINK`.
- **Taxonomy placement:**
  - `ingredient_labels`: in-scope canonical IDs only.
  - `out_of_scope_labels`: out-of-scope canonical IDs only.
  - `uncertain_labels`: valid taxonomy IDs allowed but unresolved uncertainty blocks canonical export (`X-UNRESOLVED-UNCERTAIN`).
  - Unknown/non-taxonomy label => `E-TAXONOMY`; wrong placement => `E-TAXONOMY-PLACEMENT`.
- **Duplicates/conflicts:**
  - Duplicate `record_id` => `E-DUPLICATE-ID`.
  - Conflicting active individual records for same image (`confirmed|pending_confirmation|uncertain`, not superseded/rejected/not_inspected/invalid-supersession) => `E-CONFLICT` + `X-CONFLICT`.
  - Identical active duplicates => `E-DUPLICATE-CONTENT` + `X-CONFLICT` (adjudication required).
- **Group scope:** `image_count == len(image_ids)`, duplicate image IDs checked, inventory IDs checked, folder mismatch warns (`W-GROUP-FOLDER-MISMATCH`). All groups get `X-GROUP-SCOPE`; confirmed groups also get `W-GROUP-CONFIRMED`.
- **Provenance/confirmation:** confirmed requires `is_human_ground_truth=true`, non-empty `evidence_summary`, non-empty `source_reference`; missing => `E-PROVENANCE` and `X-PROVENANCE-INCOMPLETE`. Missing historical `recognition_date` warns (`W-MISSING-DATE`).

### Export eligibility
A record is `export_eligible_canonical=true` only when:
- no error-severity issues (`valid=true`),
- no export-block codes,
- `verification_status == confirmed`,
- `recognition_method == individual`.

Eligibility is recomputed after cross-record/supersession processing. Any `E-*` error also adds `X-INVALID`. Superseded, group, uncertain, unconfirmed, pending-mapping, provenance-incomplete, conflict, invalid-supersession, or otherwise export-blocked records are not canonically exportable.

## Error / Warning / Export-Block Codes

| Code | Severity | Meaning |
|---|---|---|
| `E-LOAD` | error | Malformed/unreadable JSON record; batch continues |
| `E-SCHEMA` | error | Schema/type/required/extra-field violation |
| `E-LINK` | error | Inventory linkage mismatch/missing |
| `E-TAXONOMY` | error | Unknown/non-taxonomy label |
| `E-TAXONOMY-PLACEMENT` | error | Label in wrong semantic array |
| `E-PENDING-AS-CANONICAL` | error | Pending mapping used as canonical ingredient approval |
| `E-PENDING-AS-OUT-OF-SCOPE` | error | Pending mapping silently classified as out-of-scope |
| `E-GROUP-COUNT` | error | Group image count/ID integrity error |
| `E-PROVENANCE` | error | Confirmed record missing required human provenance |
| `E-DUPLICATE-ID` | error | Duplicate record_id |
| `E-DUPLICATE-CONTENT` | error | Identical active duplicate records; adjudication required |
| `E-CONFLICT` | error | Conflicting active individual records for same image |
| `E-SUPERSESSION` | error | Invalid supersession reference/graph/scope |
| `W-PENDING-MAPPING` | warning | Pending mapping touched; export blocked |
| `W-GROUP-FOLDER-MISMATCH` | warning | Group image folder differs from group folder |
| `W-GROUP-CONFIRMED` | warning | Confirmed group cannot become individual ground truth |
| `W-MISSING-DATE` | warning | Confirmed historical record missing recognition_date |
| `X-INVALID` | export_block | Any error makes record non-exportable |
| `X-NOT-CONFIRMED` | export_block | Non-confirmed status |
| `X-PROVENANCE-INCOMPLETE` | export_block | Confirmed provenance incomplete |
| `X-UNRESOLVED-UNCERTAIN` | export_block | Uncertain labels unresolved |
| `X-GROUP-SCOPE` | export_block | Group scope cannot be individual ground truth |
| `X-PENDING-CANONICAL` | export_block | Pending canonical mapping unresolved |
| `X-CONFLICT` | export_block | Active conflict/duplicate-content adjudication required |
| `X-SUPERSEDED` | export_block | Record superseded by valid newer record |
| `X-INVALID-SUPERSESSION` | export_block | Record participates in invalid supersession relation |

## Reports

Generated in `--out-dir`:
- `step8b_validation_report.json` — summary, per-record results, issues.
- `step8b_validation_issues.csv` — row-level issues.
- `step8b_validation_summary.md` — human-readable summary.

Report ordering is deterministic (independent of input order/temp paths): results sorted by record/method/status/source; issues sorted by severity/code/record/image/field/message/source. `generated_at` is an intentional timestamp and will vary by run.

Report paths are normalized to workspace-relative paths where possible; paths outside the workspace fall back to basename to avoid leaking absolute temp/local directories.

## Execution Commands

Run B1 tests:
```powershell
python -m unittest discover -s research/phase-13.3/annotation_pipeline_step8b/tests -p "test_*.py" -v
```

Run Step 8A regression tests:
```powershell
python -m unittest discover -s research/phase-13.3/annotation_pipeline_step8a/tests -p "test_*.py" -v
```

Validate records manually:
```powershell
python research/phase-13.3/annotation_pipeline_step8b/scripts/validate_step8b_records.py --records <file-or-dir> --out-dir research/phase-13.3/annotation_pipeline_step8b/reports/validation
```

## Remaining Limitations

- `supersedes_record_id` is a B1 compatibility shim, not a formal Step 8A v1 schema field; schema/version policy should be finalized before B2.
- B0 does not fully specify all supersession lifecycle edge cases; B1 applies a conservative scope rule (no partial overlap; cross-method only for identical single-image scope). This must be reviewed before B2.
- B1 validates/reports only; it does not create/repair annotations, provide UI, or perform bulk annotation creation.
- Pending mappings remain unresolved by design; no canonical ID invention/auto-approval.
- `generated_at` is non-deterministic metadata; report content ordering is deterministic.
- No B2 features implemented (annotation workflow/store, adjudication UI, train/val/test split, model training).
