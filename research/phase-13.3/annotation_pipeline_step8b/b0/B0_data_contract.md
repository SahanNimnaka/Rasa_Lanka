# Step 8B — B0 Data Contract

- **Status:** B0 design deliverable
- **Date:** 2026-11-24
- **Compatibility:** Backward compatible with Step 8A `human-recognition-v1`
- **Implementation:** None (contract only)

## 1. Contract scope

This contract defines entities, fields, identifiers, linkage rules, and file formats for structured multi-ingredient human recognition annotations. It is additive to Step 8A; v1 records remain valid.

## 2. Core entities

| Entity | Source of truth | Description |
|---|---|---|
| Image | `annotation_pipeline/exports/master_image_inventory.csv` | Canonical image identity and file metadata. |
| Taxonomy Class | `annotation_pipeline/config/approved_taxonomy.json` | Approved in-scope/out-of-scope canonical class IDs. |
| Mapping Decision | `annotation_pipeline_step8a/config/label_mapping_decisions.json` | Approved alias and pending canonical mapping decisions. |
| Annotation Record | Step 8B record store (future) | Human recognition record, v1-compatible. |
| Batch | Step 8B batch config (future) | Planned annotation/review unit. |
| Validation Result | Step 8B validation report (future) | Errors/warnings/gates per record/image. |
| Coverage Row | Step 8B coverage report (future) | Per-image/folder coverage state. |
| Export Manifest Row | Step 8B export (future) | Canonical-eligible annotation output. |

## 3. Image entity (authoritative linkage)

| Field | Type | Required | Rule |
|---|---:|---:|---|
| `image_id` | string `IMG-\d{6}` | yes | Stable primary key; never reassigned. |
| `relative_path` | string | yes | Canonical inventory path; normalize separators for comparison. |
| `original_folder` | string | yes | Folder as observed in collected dataset; not ground truth. |
| `original_filename` | string | yes | Filename in collected dataset. |
| `sha256` | string | recommended | Integrity/adjudication aid; read-only from inventory. |
| `batch_id` | string | inventory field | Provenance from Step 6. |

**Contract rule:** All annotation records must resolve to an existing inventory row. Inventory is read-only.

## 4. Annotation record contract (v1-compatible)

### 4.1 Common required fields

| Field | Type | Required | Notes |
|---|---:|---:|---|
| `schema_version` | const `human-recognition-v1` | yes | Step 8A compatibility. Future additive v1.1 requires separate approved schema file. |
| `record_id` | string | yes | Unique, immutable identifier. |
| `recognition_method` | enum `individual|group` | yes | Determines scope and required block. |
| `verification_status` | enum `confirmed|pending_confirmation|uncertain|rejected|not_inspected` | yes | Lifecycle/eligibility state. |
| `ingredient_labels` | array[string], unique | yes | Canonical label IDs; semantic placement rules apply. |
| `uncertain_labels` | array[string], unique | yes | Unresolved/ambiguous labels. |
| `out_of_scope_labels` | array[string], unique | yes | Out-of-scope canonical IDs. |
| `reviewer_id` | string|null | yes | Null if unknown; never fabricated. |
| `recognition_date` | string|null | yes | ISO 8601 date/datetime if known; null if unknown. |
| `notes` | string | yes | Human/context notes; no fabricated evidence. |
| `provenance` | object | yes | Evidence and source metadata. |

### 4.2 Provenance object

| Field | Type | Required | Rule |
|---|---:|---:|---|
| `source_type` | enum `historical_individual_inspection|historical_group_inspection|folder_confirmation_report|unknown` | yes | Must match method/evidence. |
| `source_reference` | string | yes | Document/session/report reference; may be empty only for non-confirmed drafts by policy, but confirmed records require meaningful reference. |
| `evidence_summary` | string | yes | What was inspected and why decision is supported. |
| `is_human_ground_truth` | boolean | yes | Must be `true` for `verification_status=confirmed`. |

### 4.3 Individual block

Required when `recognition_method=individual`; forbidden for group.

| Field | Type | Required | Rule |
|---|---:|---:|---|
| `image_id` | string `IMG-\d{6}` | yes | Must exist in inventory. |
| `relative_path` | string | yes | Must match inventory row for `image_id`. |
| `original_folder` | string | yes | Must match inventory row. |
| `original_filename` | string | yes | Must match inventory row. |

### 4.4 Group block

Required when `recognition_method=group`; forbidden for individual.

| Field | Type | Required | Rule |
|---|---:|---:|---|
| `group_id` | string | yes | Unique within batch/context. |
| `original_folder` | string | yes | Declared folder/group name. |
| `proposed_ingredient` | string|null | yes | Advisory; never ground truth. |
| `image_ids` | array[`IMG-\d{6}`], unique, minItems 1 | yes | All IDs must exist in inventory. |
| `image_count` | integer >=1 | yes | Must equal `len(image_ids)`. |

## 5. Additive v1.1 extension (future, not implemented in B0)

To preserve backward compatibility, v1.1 should be a separate schema file. Existing v1 files remain unchanged.

| Optional field | Type | Purpose |
|---|---:|---|
| `step8b.batch_id` | string | Batch traceability. |
| `step8b.label_source` | enum | `human_direct_canonical|approved_alias_resolved|pending_canonical_unresolved|out_of_scope_direct|uncertain`. |
| `step8b.canonical_mapping_status` | enum | `canonical_approved|alias_approved|pending_canonical_mapping|out_of_scope|rejected|unresolved`. |
| `step8b.alias_resolution` | object | `display_label`, `canonical_class_id`, `decision_register_ref`. |
| `step8b.adjudication` | object | adjudicator, decision, superseded records, rationale. |
| `step8b.supersedes_record_id` | string|null | Immutable correction chain. |
| `step8b.inventory_sha256` | string|null | Optional integrity check against inventory row. |

## 6. Label contract

### 6.1 Taxonomy placement

| Array | Allowed values | Disallowed |
|---|---|---|
| `ingredient_labels` | In-scope canonical taxonomy IDs eligible for confirmation | Out-of-scope IDs; pending unresolved mappings as canonical approvals |
| `uncertain_labels` | Canonical IDs (in/out scope) when human uncertainty is explicit | Silent omission of uncertainty |
| `out_of_scope_labels` | Out-of-scope canonical taxonomy IDs | In-scope IDs |

### 6.2 Mapping decision contract

| Decision type | Canonical export | Notes |
|---|---|---|
| `approved_alias` | Allowed after resolution to canonical ID | Preserve display label in audit trail. |
| `pending_canonical_mapping` | Blocked | Preserve original label; no auto-map to candidates. |
| `approved_canonical` (future) | Allowed with evidence | Requires explicit decision-register update and evidence. |

**Current pending mappings (must remain pending):**
- `Gralic` / `Garlic` / 599 records / candidates `[garlic_clove]` / canonical `null`.
- `Paper Corn` / `Pepper Corn` / 216 records / candidates `[black_pepper_whole, black_pepper_ground]` / canonical `null`.

## 7. Record identity and immutability

| Rule | Contract |
|---|---|
| RID-01 | `record_id` is globally unique across Step 8B record store. |
| RID-02 | Approved/exported records are immutable; corrections create superseding records. |
| RID-03 | No destructive overwrite of historical evidence. |
| RID-04 | Validation/export reports must reference `record_id` and source path/hash. |

## 8. Coverage contract

Coverage reports must be reproducible and keyed by `image_id`.

| Field | Type | Description |
|---|---:|---|
| `image_id` | string | Inventory key. |
| `relative_path` | string | Inventory path. |
| `folder` | string | Original folder. |
| `coverage_status` | enum | `uncovered|group_only|individual_pending|individual_confirmed_canonical|pending_canonical_mapping|out_of_scope|conflicted`. |
| `active_record_ids` | array[string] | Records contributing to status. |
| `canonical_label_ids` | array[string] | Only for eligible canonical records. |
| `pending_mapping` | boolean | True for Garlic/Pepper Corn pending cases. |
| `exclusion_reason` | string|null | Export block reason. |

## 9. File formats

| Artifact | Format | Notes |
|---|---|---|
| Annotation record | JSON UTF-8 | One record per file preferred for immutability. |
| Batch manifest | JSON/CSV | Batch scope, selection criteria, assigned reviewer. |
| Validation report | JSON + MD + CSV | Machine-readable + human-readable + row-level issues. |
| Coverage report | CSV + JSON summary | Per-image/folder counts and exclusions. |
| Export manifest | CSV + JSON | Only canonical-eligible rows; includes exclusion companion file. |
| Diagrams | Mermaid in Markdown | Text-diff friendly. |

## 10. Compatibility matrix

| Artifact | Step 8A | Step 8B B0 contract |
|---|---|---|
| `human-recognition-v1` records | Native | Fully compatible/read-only input |
| v1 schema/templates | Native | Unchanged; referenced only |
| Decision register | Step 8A finalization artifact | Read-only enforcement input |
| Master CSV | Step 8A audit artifact | Read-only; no silent rewrite |
| Taxonomy/folder mapping | Existing | Read-only; no modification |