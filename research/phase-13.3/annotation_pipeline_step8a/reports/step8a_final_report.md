# Step 8A Final Report — Dataset Consistency & Mapping Finalization

- Generated: 2026-11-24T16:58:34.021622+00:00
- Status: **Finalized for Step 8A (pending user review); Step 8B not started**

## Scope completed

- Audited `human_verification_master.csv` against `collected_dataset`.
- Resolved missing-file records by preserving original backup and excluding only 5 confirmed-missing records (no image changes).
- Investigated remaining 929 mapping warnings and applied explicit decision-register handling in audit/reporting logic.
- Preserved master CSV labels, taxonomy, folder mappings, and dataset images.

## Final consistency audit result

- CSV rows: **24,356**
- Actual image files: **24,356**
- CSV records missing image file: **0**
- Image files missing from CSV: **0**
- Duplicate CSV records: **0**
- Duplicate folder+filename keys: **0**
- Duplicate filenames across folders: **0**
- Invalid/unsupported image formats: **0**
- Filename inconsistency records: **0**
- Unresolved mapping inconsistencies: **0**
- Approved alias records: **114**
- Pending canonical mapping records: **815**

## Mapping decisions finalized

| Folder | Label | Records | Decision | Canonical | Notes |
|---|---|---:|---|---|---|
| Curry Leavf | Curry Leaf | 114 | approved_alias | curry_leaves | Human verification uses display name 'Curry Leaf'; approved taxonomy canonical ID is 'curry_leaves'. This is a display-name/canonical-ID alias. Folder name typo ('Curry Leavf') is already documented in folder_class_mapping.json. |
| Gralic | Garlic | 599 | pending_canonical_mapping | (pending) | Preserve existing 'Garlic' label. Do not automatically map to 'garlic_clove' because the display label does not establish exact garlic form (clove/bulb vs processed/paste). Canonical mapping remains pending visual/provenance confirmation. |
| Paper Corn | Pepper Corn | 216 | pending_canonical_mapping | (pending) | Preserve existing 'Pepper Corn' label. Do not split into whole or ground pepper and do not force a canonical taxonomy mapping. Folder mapping is ambiguous_pending_review; manual review required before any canonical assignment. |

## Artifacts

- `research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json` — machine-readable decision register
- `research/phase-13.3/annotation_pipeline_step8a/reports/mapping_decision_register.md` — human-readable decision register
- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master_audit_report.md` — latest audit report
- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master_audit_issues.csv` — latest audit issues (approved_alias/pending_canonical_mapping)
- `research/phase-13.3/annotation_pipeline_step8a/reports/missing_file_correction_report.md` — 5 missing-file exclusions with backup reference
- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master.pre_missing_file_correction_backup.csv` — preserved original master
- `research/phase-13.3/annotation_pipeline_step8a/reports/mapping_warning_investigation_report.md` — investigation report
- `research/phase-13.3/annotation_pipeline_step8a/reports/paper_corn_manual_review/` — manual review workflow for 216 Paper Corn images
- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master_audit_report.pre_step8a_finalization_20261124T222123Z.md` — pre-finalization audit backup
- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master_audit_issues.pre_step8a_finalization_20261124T222123Z.csv` — pre-finalization issues backup

## No-change compliance

- No dataset images modified/renamed/moved/deleted.
- No master CSV labels silently rewritten (only 5 confirmed-missing records excluded; original backup preserved).
- Approved taxonomy not changed.
- Folder mapping config not changed.
- No Step 8B/model training/backend changes.
- No files staged/committed/pushed.

## Recommendation

- Proceed to Step 8B only after user reviews and approves this decision register, especially pending canonical mappings for Garlic (form-specific) and Pepper Corn (ambiguous whole vs ground).