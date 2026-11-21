# Phase 13.3.4 — Annotation Workspace

This directory contains preparation templates only. No human annotation or adjudication has been performed.

## Distribution

Give each annotator only their own `annotator_a/` or `annotator_b/` JSON files plus `annotation_instructions.md`. Do not give annotators `image_id_crosswalk.csv`, `annotation_progress_tracker.csv`, `preliminary_visual_review.csv`, `adjudication/`, the acquired image manifest, or source paths. Source paths contain class hints; use a neutral viewer ordered by `annotation_image_id`.

The `image_assignment.csv` files inside `annotator_a/` and `annotator_b/` are coordinator setup aids and must not be shown to annotators.

## Contents

- `annotator_a/`: 20 independent no-label templates, IDs `ANN-000001`..`ANN-000020`.
- `annotator_b/`: 20 independent no-label templates, IDs `ANN-000021`..`ANN-000040`.
- `adjudication/`: 20 adjudicated-final templates, IDs `ANN-000041`..`ANN-000060`, plus `adjudication_template.csv`.
- `annotation_instructions.md`: shared independent-annotation instructions.
- `annotation_progress_tracker.csv`: coordinator-only status tracker.
- `image_id_crosswalk.csv`: coordinator-only mapping from schema PIL IDs to acquired image IDs.
- `preliminary_visual_review.csv`: coordinator-only metadata review worksheet; pixel-level human review is still pending.

No train/validation/test split fields or files are created. No original image, provenance record or approved schema is modified.
