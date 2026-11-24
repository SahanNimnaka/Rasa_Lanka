# Human Verification Master CSV - Dataset Consistency Audit

- Generated: 2026-11-24T16:21:11.790518+00:00
- Master CSV: `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master.csv`
- Dataset: `research/phase-13.3/collected_dataset`
- Audit mode: **read-only** (no images/CSV modified)

## Summary

- **csv_rows**: 24356
- **csv_folders**: 35
- **actual_files**: 24356
- **actual_folders**: 35
- **csv_records_missing_image_file**: 0
- **image_files_missing_from_csv**: 0
- **duplicate_csv_records**: 0
- **duplicate_folder_filename_keys**: 0
- **duplicate_filenames_across_folders**: 0
- **invalid_or_unsupported_image_formats**: 0
- **filename_inconsistency_records**: 0
- **folder_or_ingredient_mapping_inconsistency_records**: 929

## Per-folder coverage

| Folder | CSV records | Actual files | Delta (actual-csv) | Missing from CSV | CSV missing file |
|---|---:|---:|---:|---:|---:|
| Black Pepper | 620 | 620 | 0 | 0 | 0 |
| Cardamom | 615 | 615 | 0 | 0 | 0 |
| Chiken | 56 | 56 | 0 | 0 | 0 |
| Chile Flakes | 25 | 25 | 0 | 0 | 0 |
| Chili Powder | 931 | 931 | 0 | 0 | 0 |
| Cinnamon | 402 | 402 | 0 | 0 | 0 |
| Cloves | 303 | 303 | 0 | 0 | 0 |
| Coconut milk | 11 | 11 | 0 | 0 | 0 |
| Coconut oil | 18 | 18 | 0 | 0 | 0 |
| Coriander | 796 | 796 | 0 | 0 | 0 |
| Curry Leavf | 114 | 114 | 0 | 0 | 0 |
| Egg Plant | 1017 | 1017 | 0 | 0 | 0 |
| Fish | 3000 | 3000 | 0 | 0 | 0 |
| Ginger | 178 | 178 | 0 | 0 | 0 |
| Gralic | 599 | 599 | 0 | 0 | 0 |
| Grated coconut | 22 | 22 | 0 | 0 | 0 |
| Green Chili | 49 | 49 | 0 | 0 | 0 |
| Jack | 430 | 430 | 0 | 0 | 0 |
| Lime | 969 | 969 | 0 | 0 | 0 |
| Maldive Fish | 1413 | 1413 | 0 | 0 | 0 |
| Onion | 149 | 149 | 0 | 0 | 0 |
| Pandan Leaf | 240 | 240 | 0 | 0 | 0 |
| Paper Corn | 216 | 216 | 0 | 0 | 0 |
| Potato | 207 | 207 | 0 | 0 | 0 |
| Pumpkin | 244 | 244 | 0 | 0 | 0 |
| Raw Rice | 492 | 492 | 0 | 0 | 0 |
| Red Chili | 1178 | 1178 | 0 | 0 | 0 |
| Small Onion | 1222 | 1222 | 0 | 0 | 0 |
| Sugar | 599 | 599 | 0 | 0 | 0 |
| Table Salt | 77 | 77 | 0 | 0 | 0 |
| Tamarind | 6733 | 6733 | 0 | 0 | 0 |
| Tender Jack | 706 | 706 | 0 | 0 | 0 |
| Tomato | 337 | 337 | 0 | 0 | 0 |
| Turmeric | 365 | 365 | 0 | 0 | 0 |
| dhal | 23 | 23 | 0 | 0 | 0 |

## Duplicate filenames across folders

None.

## Notes

- This audit is read-only. No dataset images were modified, renamed, moved, or deleted. `human_verification_master.csv` was not modified.
- `blank_verified_ingredients/result/status` are reported as mapping/verification inconsistencies because the 6-column schema expects them; they require human confirmation, not automated filling.
- Expected/verified ingredient differences are reported for review; folder names and machine mapping proposals are not treated as ground truth.

## Generated files

- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master_audit_issues.csv`
- `research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master_audit_report.md`
