# Step 8A Human Recognition Provenance Format

## Purpose
Captures human ingredient recognition already performed, without fabricating missing evidence. Supports two methods:
- individual: a specific image was inspected and labeled by a human.
- group: a folder/group/batch of images was inspected as a group.

## Files
- config/human_recognition.schema.json - JSON Schema (Draft 2020-12).
- 	emplates/individual_recognition.template.json - image-level template.
- 	emplates/group_recognition.template.json - group-level template.
- scripts/validate_human_recognition.py - schema/taxonomy/inventory validator.
-
eports/folder_recognition_confirmation_report.csv/json - read-only investigation report; no labels assigned.

## Critical rules
1. Never invent reviewer IDs, dates, recognition decisions, or evidence.
2. Never claim individual inspection when only group/folder inspection occurred.
3. Never treat folder names or machine mappings as human ground truth.
4. Group-level provenance must be preserved; do not silently convert to individually verified image labels.
5. Multi-label supported via ingredient_labels, uncertain_labels, out_of_scope_labels.
6. Preserve uncertainty via erification_status and uncertain arrays; unknown date/reviewer must be null.
7. No image mutation; records reference Step 6 image_id/
elative_path.

## Semantics
- erification_status=confirmed requires provenance.is_human_ground_truth=true and evidence for the stated scope.
- Confirmed **group** record confirms group-level recognition only; it does not imply every image was individually verified.
- Confirmed **individual** record applies only to the referenced image.

## Validation
`ash
python research/phase-13.3/annotation_pipeline_step8a/scripts/validate_human_recognition.py --records <file-or-directory>
`
