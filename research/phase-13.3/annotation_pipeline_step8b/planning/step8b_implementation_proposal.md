# Step 8B Implementation Proposal — Structured Human Recognition Annotation

- **Status:** Proposal only (no Step 8B implementation performed)
- **Date:** 2026-11-24
- **Scope:** Inspect existing Step 8A structured human-recognition infrastructure and propose Step 8B implementation plan.
- **Restrictions honored:** No dataset images, master CSV, approved taxonomy, folder mappings, existing schemas/templates, backend/frontend, or git staging/commit/push changes made.

## 1. Inspected files

| File | Purpose |
|---|---|
| `research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json` | Draft 2020-12 JSON Schema for human recognition records (`human-recognition-v1`). |
| `research/phase-13.3/annotation_pipeline_step8a/config/human_recognition_format.md` | Human-readable rules/semantics and validator command. |
| `research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json` | Step 8A decision register for display-label/canonical mapping resolution; includes pending canonical mappings. |
| `research/phase-13.3/annotation_pipeline_step8a/templates/group_recognition.template.json` | Template for group/folder/batch recognition record. |
| `research/phase-13.3/annotation_pipeline_step8a/templates/individual_recognition.template.json` | Template for image-level recognition record. |
| `research/phase-13.3/annotation_pipeline_step8a/scripts/validate_human_recognition.py` | Schema + inventory + taxonomy validator; read-only w.r.t. images/existing outputs. |
| `research/phase-13.3/annotation_pipeline_step8a/tests/test_human_recognition_validation.py` | Unit tests for schema constraints and validator behavior. |
| `research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json` | Approved in-scope/out-of-scope canonical class IDs and processing states. |
| `research/phase-13.3/annotation_pipeline/config/folder_class_mapping.json` | Folder-to-class proposal mapping; explicitly not ground truth. |

## 2. Existing schema/template findings

### 2.1 `human_recognition.schema.json` (`human-recognition-v1`)

**Purpose:** Define a provenance-aware record for human ingredient recognition already performed, supporting both individual image inspection and group/folder inspection, with explicit uncertainty and anti-fabrication guardrails.

**Top-level required fields:**
- `schema_version` (const `human-recognition-v1`)
- `record_id` (string, minLength 1)
- `recognition_method` (`individual` | `group`)
- `verification_status` (`confirmed` | `pending_confirmation` | `uncertain` | `rejected` | `not_inspected`)
- `ingredient_labels` (array of unique strings)
- `uncertain_labels` (array of unique strings)
- `out_of_scope_labels` (array of unique strings)
- `reviewer_id` (string or null)
- `recognition_date` (string or null; ISO 8601 date/datetime if known; never fabricated)
- `notes` (string)
- `provenance` (object)

**Provenance required fields:**
- `source_type`: `historical_individual_inspection` | `historical_group_inspection` | `folder_confirmation_report` | `unknown`
- `source_reference`: string
- `evidence_summary`: string
- `is_human_ground_truth`: boolean

**Conditional structure (`allOf`):**
- If `recognition_method=individual`: requires `individual`, forbids `group`.
- If `recognition_method=group`: requires `group`, forbids `individual`.
- If `verification_status=confirmed`: requires `provenance.is_human_ground_truth=true`.

**Individual block (required when individual):**
- `image_id` (`^IMG-[0-9]{6}$`)
- `relative_path` (string)
- `original_folder` (string)
- `original_filename` (string)

**Group block (required when group):**
- `group_id` (string)
- `original_folder` (string)
- `proposed_ingredient` (string or null)
- `image_ids` (array of unique `IMG-xxxxxx`, minItems 1)
- `image_count` (integer >=1)

**Optional fields:** There are no optional top-level fields beyond the required set; `individual`/`group` are conditionally required/forbidden. Arrays/nullable fields may be empty/null but must be present. `additionalProperties=false` at top-level, provenance, individual, and group prevents silent schema drift.

### 2.2 Templates

**`individual_recognition.template.json`**
- Pre-filled as `pending_confirmation`, `reviewer_id=null`, `recognition_date=null`, `is_human_ground_truth=false`.
- Empty label arrays; placeholders for evidence and inventory fields.
- Notes explicitly warn against assumed values.

**`group_recognition.template.json`**
- Pre-filled as `pending_confirmation`, group provenance, `is_human_ground_truth=false`.
- Includes `proposed_ingredient=null`, placeholder `group_id/original_folder/image_ids`.
- Notes explicitly warn group confirmation must not be converted to individually verified image labels without image-level evidence.

### 2.3 `human_recognition_format.md`

Reinforces critical rules: no fabricated reviewer/date/decision/evidence; no individual claim for group-only inspection; folder names/machine mappings are not ground truth; preserve group provenance; multi-label via three label arrays; preserve uncertainty; no image mutation; records reference Step 6 `image_id/relative_path`. Confirms confirmed group means group-level recognition only.

## 3. Group-level vs individual-level recognition

| Aspect | Individual | Group |
|---|---|---|
| Unit of evidence | One specific image | Folder/group/batch of images inspected together |
| Required block | `individual` (`image_id`, `relative_path`, `original_folder`, `original_filename`) | `group` (`group_id`, `original_folder`, `proposed_ingredient`, `image_ids`, `image_count`) |
| Ground truth scope | Applies only to referenced image | Applies to group-level recognition only; **not** per-image individual verification unless image-level evidence exists |
| Conversion rule | N/A | Must not be silently converted into individually verified labels |
| Current validator warning | None specific | Warns when group record is `confirmed`: "must not be converted to individually verified labels without image-level evidence" |
| Use case | Historical image-level inspection, future per-image human annotation | Historical folder/group review, batch confirmation where image-level evidence is unavailable |

## 4. Canonical taxonomy ID validation

**Source:** `research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json` (`pilot-annotation-v1`).
- In-scope: 43 canonical IDs (e.g., `curry_leaves`, `garlic_clove`, `black_pepper_whole`, `black_pepper_ground`).
- Out-of-scope: 20 canonical IDs (e.g., `salt`, `sugar`, `coconut_oil`, `cooked_rice`).
- `processing_states` listed but not currently validated by `validate_human_recognition.py`.

**Current validator behavior (`validate_human_recognition.py`):**
- Loads taxonomy and builds `allowed = in_scope_classes ∪ out_of_scope_classes`.
- For every label in `ingredient_labels`, `uncertain_labels`, `out_of_scope_labels`, errors if label not in `allowed`.
- Checks inventory refs against `master_image_inventory.csv` by `image_id` and `relative_path`; verifies `image_id/path` match.
- Group checks: `image_count == len(image_ids)`, each `image_id` exists in inventory.
- Confirmed record requires `provenance.is_human_ground_truth=true` (schema + script).
- Warns on confirmed historical record missing `recognition_date`; warns on confirmed group record.

**Gap:** Validator accepts any allowed taxonomy ID regardless of semantic placement. It does not enforce that `out_of_scope_labels` contain only out-of-scope IDs, or that `ingredient_labels` contain only in-scope IDs. It also does not consult `label_mapping_decisions.json`, so it cannot block pending canonical mappings or accept approved display-name aliases.

## 5. Image and annotation record linkage

**Authoritative image inventory:** `research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv` (Step 6). Key fields: `image_id`, `batch_id`, `original_folder`, `original_filename`, `relative_path`, `sha256`, mapping/provenance columns.

**Individual linkage:**
- Record contains `individual.image_id`, `relative_path`, `original_folder`, `original_filename`.
- Validator requires `image_id` exists in inventory and `relative_path` exists in inventory; also checks `image_id` resolves to same `relative_path`.
- Recommended linkage key: `image_id` (stable) with `relative_path` as consistency check; do not rely on folder names.

**Group linkage:**
- Record contains `group.image_ids[]`, `group.image_count`, `group.original_folder`, optional `group.proposed_ingredient`.
- Validator checks ID existence and count equality; does not verify all IDs belong to `original_folder` or that `image_ids` cover the intended group exhaustively.

**Current gaps:** No reverse index (image -> records), no duplicate/conflicting record detection for same image, no coverage report, no check that group image IDs are from the declared folder, no file-on-disk existence check, no record-ID uniqueness check across files.

## 6. Validation limitations and missing functionality

| Area | Current state | Limitation / missing for Step 8B |
|---|---|---|
| Taxonomy placement | Any allowed ID accepted in any label array | No enforcement: in-scope IDs in `ingredient_labels`, out-of-scope IDs in `out_of_scope_labels` |
| Display-name aliases | Not supported | Cannot resolve approved alias `Curry Leaf` -> `curry_leaves`; Step 8A master CSV uses display labels |
| Mapping decisions | Not consumed | `label_mapping_decisions.json` pending/approved decisions not enforced |
| Pending canonical mappings | Not blocked | A record could incorrectly assign `garlic_clove` or `black_pepper_whole` without evidence |
| Group-folder integrity | Partial | No check that group `image_ids` belong to `original_folder` or expected inventory paths |
| Duplicate/conflict detection | None | Same `record_id`, same `image_id` with conflicting labels/statuses, or overlapping group records undetected |
| Coverage/reporting | Stdout counts only | No machine-readable validation report, no coverage by folder/image/status/method |
| Provenance quality | Minimal | Confirmed records can have empty evidence strings; no minimum evidence requirements beyond schema types |
| Label completeness | Minimal | Confirmed individual record may have empty `ingredient_labels` and no uncertain/out-of-scope explanation; needs policy |
| Group-to-individual conversion guard | Warning only | Confirmed group warns, but no structured block preventing downstream export as individual ground truth |
| Multi-ingredient spatial data | Not supported | No bbox/polygon/instance/region annotations; presence-only multi-label |
| Image existence | Not checked | Validator checks inventory CSV, not actual file on disk |
| Record ingestion format | JSON file/dir/list | No CSV/JSONL batch ingest, no resumable annotation workflow, no reviewer UI |
| Tests | 11 tests | No tests for mapping-decision enforcement, alias handling, duplicate detection, coverage, or pending-canonical blocking |

## 7. Pending canonical mappings (815) — exclusion from canonical-label approval

**Source:** `research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json`

| Folder | Label | Records | Decision | Canonical | Rule |
|---|---:|---:|---|---|---|
| Gralic | Garlic | 599 | `pending_canonical_mapping` | `null`; candidate `garlic_clove` | Preserve `Garlic`; do **not** auto-map to `garlic_clove`; form not established |
| Paper Corn | Pepper Corn | 216 | `pending_canonical_mapping` | `null`; candidates `black_pepper_whole`, `black_pepper_ground` | Preserve `Pepper Corn`; do **not** split/map; ambiguity unresolved |
| **Total** | | **815** | pending | unresolved | Exclude from canonical-label approval until resolved |

**Required Step 8B handling:**
1. **No canonical assignment without evidence.** Records/images in these pending groups must not receive `ingredient_labels` containing `garlic_clone`, `black_pepper_whole`, or `black_pepper_ground` solely because of folder/mapping/decision-register candidates, unless an explicit human-reviewed image-level or approved-resolution record provides evidence.
2. **Preserve original labels.** Any export/report must retain original display labels (`Garlic`, `Pepper Corn`) and mark `canonical_mapping_pending=true`.
3. **Separate buckets.** Validation and reporting must partition records into: `approved_canonical`, `approved_alias`, `pending_canonical_mapping`, `out_of_scope`, `unresolved/invalid`. Pending-canonical records must be excluded from training-label manifests and canonical approval counts.
4. **Validator enforcement.** Step 8B validator should load `label_mapping_decisions.json` and fail/warn (configurable severity, default fail for canonical-export path) when a pending-canonical image/label is promoted to canonical without a resolution record.
5. **Resolution path.** Only an explicit human decision record (with provenance, reviewer/date or documented historical evidence, and scope) may move a pending mapping to approved. For Paper Corn, per-image review is required before assigning `black_pepper_whole`/`black_pepper_ground`; for Garlic, form confirmation required before `garlic_clove`.
6. **Do not rewrite existing artifacts.** Step 8A master CSV and decision register remain unchanged; Step 8B creates additive records/reports.

## 8. Suitability for multi-ingredient image recognition objective

**Supports:**
- Multi-label presence via `ingredient_labels` (unique array), plus `uncertain_labels` and `out_of_scope_labels`.
- Provenance and uncertainty modeling, preventing false ground-truth claims.
- Stable image linkage through Step 6 `image_id` / inventory.
- Group vs individual distinction, important for historical evidence.

**Partially supports / needs extension:**
- **Multi-ingredient in a single image:** Schema can represent multiple canonical labels per image, which is sufficient for multi-label classification. It does **not** represent spatial localization (bounding boxes, masks, polygons), instance counts, per-ingredient regions, occlusion, or ingredient relationships. If the FYP objective includes detection/segmentation/localization, Step 8B needs an additive schema extension; if objective is multi-label recognition/presence classification, current structure is a workable base.
- **Negative/absent labels:** No explicit negative-label field; absence is implied, which is risky for multi-ingredient evaluation.
- **Confidence/calibration:** No human confidence field; only status/uncertain arrays.
- **Annotation workflow:** No annotator assignment, adjudication, inter-annotator agreement, or review-state machine beyond `verification_status`.

**Conclusion:** Current infrastructure is a **good provenance-aware foundation for multi-label ingredient recognition**, but **not sufficient as-is** for production Step 8B annotation at scale. It needs validation hardening, decision-register enforcement, coverage/conflict reporting, and a clear decision on whether Step 8B targets multi-label presence only or spatial multi-ingredient annotation.

## 9. Proposed Step 8B workflow

### Phase B0 — Planning & guardrails (this proposal)
- Freeze scope: multi-label presence annotation only unless user approves spatial extension.
- Treat Step 8A artifacts as read-only inputs.

### Phase B1 — Schema/validator extension design (additive, versioned)
- Create `human-recognition-v1.1` or separate Step 8B schema only if needed; do **not** modify v1 files.
- Add optional fields for: `annotation_batch_id`, `annotator_confidence` (controlled), `evidence_artifacts`, `label_source` (human_direct | alias_resolved | pending_unresolved), `canonical_mapping_status`, `adjudication`.
- Add explicit rule: `ingredient_labels` must be in-scope canonical IDs unless `label_source=pending_unresolved` with decision-register reference.
- Add record-level `image_sha256`/inventory snapshot hash for integrity.

### Phase B2 — Decision-register-aware validation
- Load `label_mapping_decisions.json`.
- Enforce approved alias (`Curry Leaf` display only in reporting; canonical record uses `curry_leaves`).
- Block pending canonical mappings from canonical approval/export.
- Produce machine-readable validation report (JSON/CSV) with counts by status, method, folder, decision type.

### Phase B3 — Annotation record generation workflow
- Build annotation batches from `master_image_inventory.csv`, excluding or quarantining pending-canonical folders unless explicitly reviewed.
- For historical group-confirmed evidence, generate group records only.
- For new human image-level annotations, generate individual records with required evidence.
- Store records under `research/phase-13.3/annotation_pipeline_step8b/records/` (append-only, immutable record IDs).

### Phase B4 — Coverage/conflict QA
- Detect duplicate record IDs, conflicting labels for same image, group/individual overlaps, missing inventory refs, folder-ID mismatches.
- Generate coverage matrix: per image/folder, has individual confirmed? has group confirmed? pending? unresolved?

### Phase B5 — Canonical export gate (no training split yet)
- Export only records passing canonical gate: confirmed individual or explicitly approved group-derived policy (default: individual only).
- Exclude all pending canonical mappings (815) and any unresolved/ambiguous labels.
- Produce manifest with provenance fields; do not create train/val/test split until user approves.

## 10. Recommended directory structure

```text
research/phase-13.3/annotation_pipeline_step8b/
  planning/
    step8b_implementation_proposal.md        # this document
  config/
    human_recognition_v1_1.schema.json       # additive, if extension approved; v1 untouched
    step8b_validation_policy.json            # severity rules, pending-mapping gates, alias policy refs
    annotation_batches.json                  # batch definitions / sampling metadata
  records/
    individual/                              # immutable individual JSON records
    group/                                   # immutable group JSON records
    adjudicated/                             # optional adjudication records
  reports/
    validation/
      step8b_validation_report.json
      step8b_validation_report.md
      step8b_record_conflicts.csv
    coverage/
      step8b_image_coverage.csv
      step8b_folder_coverage.csv
      step8b_pending_canonical_exclusions.csv
    exports/
      step8b_canonical_manifest.csv          # only after gate passes and user approves export
      step8b_excluded_records.csv
  scripts/
    validate_step8b_records.py
    build_step8b_coverage_report.py
    export_step8b_canonical_manifest.py      # gate-enforced, no split
  tests/
    test_step8b_validation.py
    test_step8b_pending_mapping_gate.py
    test_step8b_coverage.py
```

## 11. Annotation data structure (proposed)

### Record envelope (compatible with v1)
- Keep all v1 required fields.
- Additive optional metadata under `step8b` object to avoid breaking v1:
  - `batch_id`, `annotation_round`, `label_source`, `canonical_mapping_status`, `alias_resolution`, `reviewer_confidence`, `adjudication_status`, `supersedes_record_id`.
- `label_source` enum: `human_direct_canonical`, `approved_alias_resolved`, `pending_canonical_unresolved`, `out_of_scope_direct`, `uncertain`.
- `canonical_mapping_status` enum: `canonical_approved`, `alias_approved`, `pending_canonical_mapping`, `out_of_scope`, `rejected`.

### Label policy
- `ingredient_labels`: canonical in-scope IDs only for canonical-approved records.
- `out_of_scope_labels`: canonical out-of-scope IDs only.
- `uncertain_labels`: canonical IDs allowed but record cannot be used as confirmed positive training label without resolution.
- Display labels never stored as canonical IDs; if captured, store in `notes`/`alias_resolution.display_label`.

## 12. Validation requirements

1. **Schema validation:** Draft 2020-12 against selected schema version; reject unknown fields.
2. **Inventory integrity:** `image_id`, `relative_path`, folder/filename match Step 6 master inventory; optional disk existence check in read-only mode.
3. **Taxonomy integrity:** all labels belong to approved taxonomy; enforce array placement by in-scope/out-of-scope.
4. **Decision-register enforcement:** load `label_mapping_decisions.json`; block pending canonical mappings from canonical export; require approved alias mapping for alias-resolved records.
5. **Provenance/evidence:** confirmed records require `is_human_ground_truth=true`, non-empty evidence summary/reference, and no fabricated dates/reviewers.
6. **Group/individual semantics:** confirmed group cannot be exported as individual ground truth; group `image_ids` must belong to declared folder (or explicit documented exception).
7. **Conflict detection:** unique `record_id`; detect conflicting active records for same image; detect duplicate image IDs in group with inconsistent folder.
8. **Coverage gates:** report images with no records, pending-only records, unresolved canonical mappings, or ambiguous labels.
9. **Export gate:** canonical manifest export fails if any selected record is pending canonical, uncertain-unresolved, schema-invalid, or provenance-incomplete.

## 13. Testing strategy

| Test layer | Cases |
|---|---|
| Schema unit tests | v1 compatibility; v1.1 additive fields; confirmed requires ground truth; group/individual exclusivity; pending status allows null reviewer/date. |
| Validator unit tests | Unknown image/path mismatch; unknown taxonomy ID; in-scope/out-of-scope misplacement; duplicate record ID; conflicting image records. |
| Decision-register tests | Curry Leaf alias approved resolves to `curry_leaves` for reporting but preserves display label; Garlic/Pepper Corn pending mappings blocked from canonical export; no auto-map to candidates. |
| Group semantics tests | Confirmed group warns/fails export-as-individual; group image IDs outside folder flagged; image_count mismatch fails. |
| Coverage/report tests | Deterministic counts for approved_alias=114, pending_canonical_mapping=815, unresolved=0 on Step 8A baseline; machine-readable report schema. |
| Integration/smoke | Run validator against fixture records; ensure no writes to collected_dataset/master CSV/taxonomy/folder mapping; idempotent outputs. |
| Regression | Existing `test_human_recognition_validation.py` remains green; v1 records remain valid unless explicitly migrated with approval. |

## 14. Expected outputs (after implementation, if approved)

- Step 8B planning/proposal (this file) — created now.
- Versioned validation policy and additive schema/config (no v1 mutation).
- Validation reports: JSON/MD/CSV with pass/fail, conflicts, coverage, pending exclusions.
- Coverage reports: per-image/per-folder status, including 815 pending-canonical exclusions.
- Canonical export manifest (only canonical-approved records), with provenance and decision-register traceability.
- Test results for Step 8B validator/gates.
- No train/val/test split; no model training; no backend/frontend changes.

## 15. Risks and limitations

| Risk | Impact | Mitigation |
|---|---|---|
| Pending canonical mappings (815) accidentally promoted | Invalid training labels / taxonomy leakage | Hard gate on `label_mapping_decisions.json`; exclude from canonical exports until explicit human resolution |
| Group records misused as individual ground truth | False per-image labels | Keep group semantics explicit; export gate defaults to individual-confirmed only |
| Multi-ingredient spatial needs exceed schema | Annotation rework if detection/segmentation needed later | Confirm objective now; add additive spatial schema only if approved |
| Historical evidence incomplete | Records remain pending/unconfirmed | Preserve null reviewer/date; do not fabricate; track as pending evidence debt |
| Duplicate/conflicting annotations | Unreliable dataset | Add record-ID uniqueness, image conflict detection, adjudication workflow |
| Display-name/canonical drift | Validation false positives/negatives | Centralize alias resolution in decision register; never rewrite master CSV silently |
| Large-file audit/report churn | Noisy diffs | Separate immutable records from generated reports; define reproducible report generation |
| Scope creep into Step 8C/splitting | Premature dataset split | Explicit gate: no split/training before canonical-approved manifest reviewed |

## 16. Recommendation / approval gate

1. **Approve Step 8B scope:** multi-label presence annotation first; defer spatial bbox/mask unless explicitly required.
2. **Approve additive schema strategy:** keep `human-recognition-v1` immutable; introduce v1.1/additive Step 8B policy only if needed.
3. **Approve pending-mapping gate:** 815 Garlic/Pepper Corn records remain excluded from canonical approval/export until human evidence resolves them.
4. **Approve implementation order:** validator/policy + tests → record ingestion/coverage → canonical export gate → only then consider split planning.

*No implementation started. Awaiting approval.*