# Step 8B — B0 Decision and Validation Rules

- **Status:** B0 design deliverable
- **Date:** 2026-11-24
- **Applies to:** Step 8B structured human recognition annotation records (v1-compatible)

## 1. Decision rules

### D-01 — Inventory authority
- **Rule:** `master_image_inventory.csv` is the authoritative image identity source.
- **Decision:** Accept linkage only when `image_id` exists and `relative_path/original_folder/original_filename` match the inventory row.
- **Otherwise:** Blocking error `E-LINK`.

### D-02 — Taxonomy authority
- **Rule:** `approved_taxonomy.json` is the only canonical taxonomy source.
- **Decision:** Labels must be canonical IDs present in in-scope or out-of-scope taxonomy.
- **Otherwise:** Blocking error `E-TAXONOMY`.

### D-03 — Label placement
- **Rule:** Label arrays have semantic meaning.
- **Decision:**
  - `ingredient_labels`: in-scope canonical IDs only for confirmed/canonical path.
  - `out_of_scope_labels`: out-of-scope canonical IDs only.
  - `uncertain_labels`: explicit uncertainty; cannot be treated as canonical positive labels.
- **Otherwise:** `E-TAXONOMY-PLACEMENT` or `X-UNRESOLVED-UNCERTAIN`.

### D-04 — Individual vs group scope
- **Rule:** Method determines evidence scope.
- **Decision:** Individual records may become individual image ground truth only with image-level evidence. Group records provide group-scope evidence only.
- **Otherwise:** `X-GROUP-SCOPE`; never auto-convert group confirmation to individual ground truth.

### D-05 — Provenance and anti-fabrication
- **Rule:** No fabricated reviewer/date/decision/evidence.
- **Decision:** Confirmed records require `is_human_ground_truth=true` and meaningful evidence summary/reference. Unknown reviewer/date remain null.
- **Otherwise:** `E-PROVENANCE` or `W-MISSING-DATE`.

### D-06 — Decision register precedence for mapping
- **Rule:** `label_mapping_decisions.json` governs Step 8A/B0 mapping decisions.
- **Decision:**
  - `approved_alias`: may resolve display label to canonical ID, preserving original display label in audit fields.
  - `pending_canonical_mapping`: preserve label and pending status; block canonical approval/export.
- **Otherwise:** `X-PENDING-CANONICAL` or `E-TAXONOMY`.

### D-07 — Pending canonical mappings (explicit)
- **Rule:** The following remain pending until explicit human resolution evidence is recorded.
  - `Gralic` / `Garlic` / 599 records / candidate `garlic_clove` / canonical `null`.
  - `Paper Corn` / `Pepper Corn` / 216 records / candidates `black_pepper_whole`, `black_pepper_ground` / canonical `null`.
- **Decision:** Exclude from canonical-label approval and canonical export manifests. Include in pending-exclusion coverage reports.
- **Otherwise:** Release/export gate failure.

### D-08 — Conflict handling
- **Rule:** No silent merge of conflicting evidence.
- **Decision:** Conflicting active records require adjudication; adjudication creates a new/superseding record with evidence.
- **Otherwise:** `E-CONFLICT` / `X-CONFLICT`.

### D-09 — Immutability
- **Rule:** Approved/exported records are immutable audit artifacts.
- **Decision:** Corrections are superseding records; prior records retained.
- **Otherwise:** Audit integrity violation (process error).

## 2. Validation catalog

### 2.1 Blocking errors

| Code | Severity | Trigger | Required action |
|---|---|---|---|
| `E-SCHEMA` | error | Invalid JSON, missing required field, bad type/enum/pattern, additional property, method/block mismatch | Fix record structure; revalidate |
| `E-LINK` | error | Unknown `image_id`, path/folder/filename mismatch, group ID missing from inventory | Correct linkage against master inventory |
| `E-GROUP-COUNT` | error | `group.image_count != len(image_ids)` or duplicate group image IDs | Fix group membership/count |
| `E-TAXONOMY` | error | Label not in approved taxonomy | Remove/replace invalid label; do not invent taxonomy |
| `E-TAXONOMY-PLACEMENT` | error | In-scope label in `out_of_scope_labels` or out-of-scope in `ingredient_labels` | Move label to correct array or resolve uncertainty |
| `E-PROVENANCE` | error | Confirmed record missing `is_human_ground_truth=true` or missing evidence summary/reference | Provide evidence or downgrade status |
| `E-DUPLICATE-ID` | error | Duplicate `record_id` | Assign unique immutable record ID |
| `E-CONFLICT` | error | Active conflicting records for same scope without adjudication | Adjudicate before approval/export |
| `E-PENDING-AS-CANONICAL` | error | Pending canonical mapping promoted to canonical approval without resolution record | Revert to pending/unresolved; block export |

### 2.2 Warnings

| Code | Severity | Trigger | Required action |
|---|---|---|---|
| `W-GROUP-CONFIRMED` | warning | Group record is confirmed | Ensure downstream treats as group-scope only |
| `W-MISSING-DATE` | warning | Confirmed historical record has null `recognition_date` | Preserve unknown; add evidence if available |
| `W-GROUP-FOLDER-MISMATCH` | warning | Group image IDs span unexpected folders | Review group scope/evidence |
| `W-PENDING-MAPPING` | warning | Record touches pending canonical mapping | Exclude from canonical export |
| `W-AMBIGUOUS` | warning | Uncertain labels or ambiguous evidence | Route to human review/adjudication |
| `W-DUPLICATE-CONTENT` | warning | Same semantic content repeated | Deduplicate with audit trail |

## 3. Export-blocking conditions

| Code | Block scope | Condition | Disposition |
|---|---|---|---|
| `X-INVALID` | record | Any blocking validation error | Exclude from all canonical exports |
| `X-NOT-CONFIRMED` | record | Status not `confirmed` | Exclude from canonical-approved manifest |
| `X-GROUP-SCOPE` | individual ground-truth export | Group record or group-derived image label without individual evidence | Exclude; may appear in group audit report |
| `X-PENDING-CANONICAL` | canonical export | Garlic/Pepper Corn pending mapping or any unresolved pending mapping | Exclude until explicit resolution |
| `X-UNRESOLVED-UNCERTAIN` | canonical positive export | Uncertain labels unresolved | Exclude or keep non-canonical |
| `X-CONFLICT` | record/image scope | Unadjudicated conflict | Exclude until adjudicated |
| `X-PROVENANCE-INCOMPLETE` | record | Missing evidence/ground-truth requirements | Exclude |

## 4. Decision matrix

| Case | Method | Status | Mapping/label state | Canonical individual export | Report/audit |
|---|---|---|---|---:|---:|
| Confirmed individual, in-scope canonical labels, no conflicts | individual | confirmed | canonical approved | Yes | Yes |
| Confirmed individual, approved alias resolved | individual | confirmed | alias approved | Yes (canonical ID) | Yes, preserve display label |
| Any record in Garlic/Pepper Corn pending mapping | individual/group | any | pending canonical | No | Yes, pending exclusion |
| Confirmed group record | group | confirmed | any | No (group-scope only) | Yes |
| Uncertain record | any | uncertain | unresolved | No | Yes |
| Rejected/not inspected | any | rejected/not_inspected | any | No | Optional/audit |
| Conflicting active records | any | any | any | No | Yes, conflict queue |

## 5. Validation execution order (future implementation)

1. Parse/load JSON and schema-validate (v1 compatibility).
2. Resolve inventory linkage and normalize paths.
3. Validate taxonomy IDs and label-array placement.
4. Apply decision-register rules (approved aliases, pending mappings).
5. Check provenance/anti-fabrication rules.
6. Detect duplicates/conflicts/adjudication state.
7. Compute coverage status and export eligibility.
8. Emit JSON/MD/CSV reports; fail export gate on blocking conditions.

## 6. Auditability requirements

- Every exclusion must carry a reason code and record/image reference.
- Every approved alias resolution must cite decision-register reference and preserve original display label.
- Every pending canonical mapping must be counted and reported, including `Garlic` (599) and `Pepper Corn` (216).
- Every adjudication must preserve prior records and evidence.