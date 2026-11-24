# Step 8A — Folder/Ingredient Mapping Warning Investigation Report

- **Generated:** 2026-11-24 (read-only investigation)
- **Scope:** 929 remaining `folder_or_ingredient_mapping_inconsistency` warnings from `human_verification_master_audit_report.md` / `human_verification_master_audit_issues.csv`
- **Status:** Investigation only. **No data modified** (master CSV, taxonomy, folder mapping, dataset images, backend unchanged).

## Files inspected

- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master.csv` (corrected working master, 24,356 rows, 6-column schema)
- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master_audit_report.md`
- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master_audit_issues.csv`
- `research/phase-13.3/annotation_pipeline_step8a/scripts/audit_human_verification_master.py`
- `research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json`
- `research/phase-13.3/annotation_pipeline/config/folder_class_mapping.json`
- `research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json`
- `research/phase-13.3/annotation_pipeline_step8a/config/human_recognition_format.md`
- `research/phase-13.3/annotation_pipeline_step8a/scripts/validate_human_recognition.py`

## How the 929 warnings were produced

The audit script (`audit_human_verification_master.py`) flags `folder_or_ingredient_mapping_inconsistency` when:

- folder is missing in `folder_class_mapping.json`; or
- `Expected ingredient` normalizes differently from folder name, proposed class ID, and candidate class IDs; or
- `Verified ingredients` differs from `Expected ingredient`; or
- `Verified ingredients`, `Result`, or `Status` is blank.

For all 929 rows, the detail is `expected_differs_from_folder_or_mapping(...)`. There are **no** blank verification fields and **no** expected-vs-verified differences among these 929:

| Folder | Records | Expected ingredient | Verified ingredients | Result | Status | Audit reason |
|---|---:|---|---|---|---|---|
| Curry Leavf | 114 | Curry Leaf | Curry Leaf | correct | reviewed | expected differs from folder/proposal |
| Gralic | 599 | Garlic | Garlic | correct | reviewed |
| Paper Corn | 216 | Pepper Corn | Pepper Corn | correct | reviewed |
| **Total** | **929** | | | | | |

## Taxonomy / mapping evidence

### Approved taxonomy (`approved_taxonomy.json`, pilot-annotation-v1)

Relevant canonical IDs present:
- `curry_leaves` — **in-scope** (`curry_leaf` not present)
- `garlic_clove` — **in-scope** (`garlic` not present)
- `black_pepper_whole` — **in-scope**
- `black_pepper_ground` — **in-scope**
- `pepper_corn` / `peppercorn` — **not present**

### Folder mapping (`folder_class_mapping.json`, folder-class-mapping-v1)

| Folder | image_count | proposed_class_id | candidate_class_ids | mapping_status | Rationale (existing) |
|---|---:|---|---|---|---|
| Curry Leavf | 114 | `curry_leaves` | [] | `proposed_pending_review` | Folder name typo ('Leavf') but strongly indicates curry leaves. Spelling-only proposal; visual confirmation required. |
| Gralic | 599 | `garlic_clove` | [] | `proposed_pending_review` | Misspelling of Garlic. Spelling-based proposal only; confirm cloves/bulbs rather than paste. |
| Paper Corn | 216 | `null` | [`black_pepper_whole`] | `ambiguous_pending_review` | Likely misspelling of Pepper Corn, but name is ambiguous. Do not merge into Black Pepper without visual review. |

## Case-by-case assessment

### 1) Curry Leavf / "Curry Leaf" (114 records)

- **Observed:** Folder name `Curry Leavf` (typo); master CSV Expected/Verified = `Curry Leaf`; folder mapping proposes canonical `curry_leaves`.
- **Taxonomy:** `curry_leaves` exists; `curry_leaf` does not.
- **Warning cause:** **Legitimate display-name vs canonical-name difference**, plus folder-name typo already captured by mapping. The audit compares normalized expected value against folder/proposal/candidate strings; `curry_leaf` (display) does not normalize to the same token as `curry_leaves` (canonical ID) under the script's comparison.
- **Not an incorrect ingredient label by itself:** "Curry Leaf" is a plausible human display name for `curry_leaves`. However, it is not the canonical taxonomy ID.
- **Not an incorrect folder mapping:** Existing mapping to `curry_leaves` is plausible and documented as spelling-only/proposal pending visual confirmation.
- **Ambiguity:** Low for naming; visual confirmation still required by existing policy (folder names/mappings not ground truth).

**Recommended resolution (pending approval):**
- Treat as **display-name/canonical-name normalization issue** in the audit/validation layer, not as bad human verification.
- Add an explicit alias/display-name map for Step 8A master-CSV validation (e.g., `Curry Leaf` -> `curry_leaves`) **or** adjust audit comparison to accept approved display aliases. Do not rename dataset folders/images.
- Keep folder mapping as `proposed_pending_review` unless/until human visual confirmation evidence is recorded in provenance-aware format.
- Before any training-label export, map display labels to canonical taxonomy IDs through an explicit, auditable normalization table.

### 2) Gralic / "Garlic" (599 records)

- **Observed:** Folder name `Gralic` (typo); master CSV Expected/Verified = `Garlic`; folder mapping proposes canonical `garlic_clove`.
- **Taxonomy:** `garlic_clove` exists; `garlic` does not. Other garlic form `ginger_garlic_paste` exists, but the mapping rationale already notes "confirm cloves/bulbs rather than paste".
- **Warning cause:** **Display-name vs canonical-name difference**, plus folder-name typo. "Garlic" is a general human label; canonical taxonomy distinguishes form (`garlic_clove`).
- **Potential ambiguity:** Moderate. "Garlic" could be interpreted broadly by humans, while taxonomy requires `garlic_clove`. The folder mapping already constrains the proposal to `garlic_clove` pending visual confirmation. The master CSV currently carries "Garlic" for all 599 records as reviewed/correct.
- **Not an incorrect folder mapping by current evidence:** Mapping to `garlic_clove` is the closest approved class; it remains proposal pending visual confirmation.
- **Not clearly an incorrect label, but not canonical:** Needs normalization rule with caution.

**Recommended resolution (pending approval):**
- Treat as **display-name to canonical-ID normalization**, but document the semantic narrowing: `Garlic` (display) -> `garlic_clove` (canonical) only when supported by visual confirmation/provenance; otherwise retain uncertainty.
- Add explicit alias rule in validation (`Garlic` -> `garlic_clove`) only for this folder/context if approved, and flag for provenance-aware confirmation because taxonomy is form-specific.
- Do **not** silently convert to final training label without confirmation that images are cloves/bulbs, not paste/processed forms.

### 3) Paper Corn / "Pepper Corn" (216 records)

- **Observed:** Folder name `Paper Corn` (typo/ambiguous); master CSV Expected/Verified = `Pepper Corn`; folder mapping has `proposed_class_id=null`, `candidate_class_ids=["black_pepper_whole"]`, `mapping_status=ambiguous_pending_review`.
- **Taxonomy:** `black_pepper_whole` and `black_pepper_ground` exist; `pepper_corn`/`peppercorn` do not. There is no generic approved "pepper corn" class.
- **Warning cause:** **Ambiguous ingredient / label normalization gap**, not a simple safe display-name alias. The human-entered value "Pepper Corn" is semantically close to whole peppercorns, but taxonomy requires distinguishing `black_pepper_whole` vs `black_pepper_ground`. The folder mapping intentionally keeps this ambiguous.
- **Incorrect folder mapping?** Current mapping is conservative (ambiguous, null proposed class) and is consistent with taxonomy constraints. It should **not** be auto-upgraded to `black_pepper_whole` without review.
- **Incorrect ingredient label?** "Pepper Corn" is not a canonical taxonomy ID; it may also be insufficiently specific if any ground pepper images exist. The master CSV marks all 216 as reviewed/correct, but that conflicts with the mapping's ambiguity and taxonomy granularity.
- **Ambiguity:** **High / requires manual review**.

**Recommended resolution (pending approval):**
- **Do not auto-correct.** Flag `Paper Corn` / "Pepper Corn" for **manual human review**.
- Review a representative sample (or all 216 if feasible) to confirm whether images are `black_pepper_whole`, `black_pepper_ground`, mixed, or uncertain.
- If confirmed whole peppercorns, record explicit provenance-aware decision and map to canonical `black_pepper_whole`; if mixed, preserve multi-label/uncertainty or split by image-level evidence.
- Keep folder mapping `ambiguous_pending_review` until evidence supports a specific canonical label. Do not merge into `Black Pepper` folder without review.

## Validation/schema observations (relevant to these warnings)

- `validate_human_recognition.py` validates JSON human-recognition records against `human_recognition.schema.json` and checks labels against taxonomy IDs (`in_scope_classes` + `out_of_scope_classes`). It expects canonical taxonomy IDs; it does not define display-name aliases.
- The 6-column `human_verification_master.csv` is a separate human verification artifact; its `Expected ingredient` / `Verified ingredients` fields currently contain human-readable display names, not canonical taxonomy IDs. The audit script therefore reports naming mismatches as mapping inconsistencies.
- There is no dedicated `human_verification_master` schema file found in Step 8A; schema/formalization exists for JSON human-recognition records.

## Overall recommendation

| Case | Classification | Auto-fix now? | Recommended action |
|---|---|---:|---|
| Curry Leavf / Curry Leaf | Display-name vs canonical-name difference; folder typo already documented | No (needs approval) | Add auditable alias/normalization rule `Curry Leaf` <-> `curry_leaves`; keep mapping proposal pending visual confirmation |
| Gralic / Garlic | Display-name vs canonical-name difference; taxonomy is form-specific | No (needs approval) | Add auditable alias/normalization rule `Garlic` -> `garlic_clove` only with provenance/visual-confirmation caveat; do not infer paste/other forms |
| Paper Corn / Pepper Corn | Ambiguous ingredient requiring manual review; not a safe canonical alias | No | Manual review required before mapping to `black_pepper_whole`/`black_pepper_ground`; keep ambiguous until evidence recorded |

## No-change statement

- No master CSV, taxonomy, folder mapping, dataset images, or backend files were modified.
- This report is investigative only and requires approval before any correction is applied.