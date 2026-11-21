# Phase 13.3.4 — Pilot Image Annotation Preparation Progress Report

**Date:** 2026-11-21<br>
**Scope:** annotation workspace preparation for the 20 Phase 13.3.3 acquired images. No human annotation result, adjudication result, model training, dataset split, image modification, provenance change, application-code change or database change was produced.

## 1. Outcome summary

| Metric | Result |
|---|---:|
| Acquired image records reviewed at manifest/file level | 20 |
| Pixel-level visual reviews performed by this model | 0 |
| Images still requiring human visual review | 20 |
| Independent annotation templates prepared | 40 |
| Adjudicated-final templates prepared but not completed | 20 |
| Total annotation templates prepared | 60 |
| Human annotations completed | 0 |
| Human annotations pending | 40 |
| Adjudications completed | 0 |
| Adjudications pending | 20 |
| Final train/validation/test splits created | 0 |
| Original acquired images modified | 0 |
| Approved taxonomy, mapping, schema or acquisition records modified | 0 |

The workspace is prepared and validated. The annotation process itself remains pending until two human annotators independently complete their work and a reviewer performs adjudication.

## 2. Materials audited

The following existing research materials were read before preparation and treated as source of truth:

- `research/phase-13.2/ingredient_taxonomy.xlsx`
- `research/phase-13.2/ml_class_to_catalogue.csv`
- `research/phase-13.2/visual_class_feasibility_report.md`
- `research/phase-13.2/visual_class_feasibility.csv`
- `research/phase-13.3/pilot_dataset_collection_protocol.md`
- `research/phase-13.3/pilot_annotation_schema.json`
- `research/phase-13.3/pilot_image_metadata_template.csv`
- `research/phase-13.3/pilot_quality_checklist.md`
- `research/phase-13.3/acquired_image_manifest.csv`
- `research/phase-13.3/acquisition_validation_report.md`

Audit results:

- The approved taxonomy contains 51 distinct visual class IDs.
- The annotation schema contains 43 in-scope pilot class IDs, 20 out-of-scope IDs and 13 processing states.
- The 20 acquired images represent 15 distinct schema-approved target class IDs.
- All 15 represented target class IDs are present in the approved annotation schema.
- No new class ID was introduced.

## 3. Preliminary visual-review findings

A per-image worksheet was created at `research/phase-13.3/pilot_annotations/preliminary_visual_review.csv`. It covers all 20 images and records source-label-based observations, manifest mapping status, packaging visibility, source-group information and human-check fields.

**Important limitation:** this model cannot inspect image pixels directly. Therefore no claim is made that the visible ingredient, suitability, multiple-ingredient status or uncertainty status was visually confirmed. The worksheet marks pixel visual inspection as `not_performed_model_has_no_image_input` and leaves all human visual-check fields as `pending` with blank reviewer/result columns. The findings below are preliminary metadata-based risks, not confirmed human annotation results.

### 3.1 Source and mapping overview

- 10 images are from Fruits-360 original-size GitHub and 10 are from Wikimedia Commons.
- 14 manifest rows have `exact` class mapping status; 6 have `possible_synonym`.
- All 20 rows have `packaging_visibility = none` and `primary_unlabelled` role.
- 15 distinct target ingredient class IDs are represented: `black_pepper_whole`, `brinjal`, `cardamom_pod`, `coriander_seed`, `cumin_seed`, `curry_leaves`, `dried_red_chilli`, `ginger_root`, `green_chilli`, `jackfruit_seed`, `mature_jackfruit`, `onion`, `red_onion`, `tamarind_pulp` and `tomato`.
- Five Fruits-360 source groups contain two related images each: `F360:eggplant_long_1`, `F360:Ginger 1`, `F360:Onion 2`, `F360:Onion Red 2` and `F360:Tomato 1`. Each Wikimedia Commons file has a unique page ID.

### 3.2 Priority checks for human reviewers

- **Brinjal:** `F360-BRINJAL-r0_0` and `F360-BRINJAL-r0_100` use the source folder `eggplant_long_1`. Eggplant is a direct synonym for brinjal, but the long cultivar is not verified as a Sri Lankan local type. Confirm that the visible vegetable is an eggplant/brinjal and decide whether cultivar appearance affects the intended task.
- **Onion:** `F360-ONION-r0_0` and `F360-ONION-r2_100` come from generic `Onion 2`. The manifest already flags skin-colour re-check. Human reviewers must confirm whether these are compatible with the generic/large `onion` class rather than `red_onion` or `small_onion`.
- **Dried red chilli:** `WIKI-DRIED_CHILLI-78362289` is a Byadgi chilli from Karnataka. It is a plausible dried red chilli, but Sri Lankan cultivar equivalence is not established. Confirm visible dried state and distinguish from chilli flake or chilli powder.
- **Mature jackfruit:** `WIKI-MATURE_JACKFRUIT-24423194` is a whole jackfruit photographed in Sri Lanka. Maturity was inferred from appearance and file description; yellow bulbs may not be visible. Confirm whether the image supports `mature_jackfruit` without relying on source text.
- **Curry leaves:** `WIKI-CURRY_LEAVES-114904614` shows curry leaves on the plant. Confirm that the plant context does not introduce extra ingredient labels or misleading prepared-food interpretation.
- **Black pepper vs coriander:** `WIKI-BLACK_PEPPER-151971300` and `WIKI-CORIANDER-151971308` are separate seed/spice classes with visually similar bowl compositions. Prior pHash screening flagged them as compositionally similar at distance 13. Human reviewers should confirm each by intrinsic texture, size and form, not by filename or source text.
- **Other pHash candidate:** `F360-GINGER-r0_0` and `F360-TOMATO-r2_100` were flagged at pHash distance 13. This is a compositional-similarity candidate only and is not an exact or dHash near-duplicate. Human reviewers should confirm that class appearance is unambiguous.
- **Tamarind:** `WIKI-TAMARIND-24046244` is labelled as tamarind fruit pulp. Confirm that the visible state supports `tamarind_pulp` rather than whole fruit or `tamarind_paste`.
- **Jackfruit seeds:** `WIKI-JACKFRUIT_SEED-66850649` must be checked against `caju seed` and chestnut confusion using visual evidence only.

These checks are routed to annotators and adjudication through the instructions and uncertainty fields; they are not pre-filled as annotation results.

## 4. Annotation workspace prepared

Directory: `research/phase-13.3/pilot_annotations/`

Files and records:

- `annotator_a/ANN-000001.json` through `ANN-000020.json`: 20 independent no-label templates for Annotator A role placeholder `ANNTR-001`.
- `annotator_b/ANN-000021.json` through `ANN-000040.json`: 20 independent no-label templates for Annotator B role placeholder `ANNTR-002`.
- `adjudication/ANN-000041.json` through `ANN-000060.json`: 20 adjudicated-final templates, all marked `not_adjudicated`; each preserves the corresponding A and B annotation IDs.
- `adjudication/adjudication_template.csv`: one row per image preserving A decisions, B decisions, disagreement summary, final decision, reason and reviewer fields. All decision/reviewer fields are blank.
- `annotation_instructions.md`: shared independent annotation instructions for both annotators.
- `workspace_readme.md`: distribution and independence rules.
- `annotation_progress_tracker.csv`: coordinator-only tracker; all pass statuses are `pending` and adjudication statuses are `not_adjudicated`.
- `image_id_crosswalk.csv`: coordinator-only mapping from schema PIL IDs `PIL-000001`..`PIL-000020` to acquired image IDs, filenames, paths and intended class.
- `preliminary_visual_review.csv`: coordinator-only metadata review worksheet for all 20 images.
- `annotator_a/image_assignment.csv` and `annotator_b/image_assignment.csv`: coordinator setup aids for locating images; not for annotator distribution because source paths contain class hints.

All JSON templates use the exact field structure and allowed values in `pilot_annotation_schema.json`. No confirmed ingredient label, uncertain label, out-of-scope label, processing-state evidence, unknown ingredient, completion timestamp or adjudication decision was filled in.

No `label_visible` templates were prepared because all acquired images are recorded as `packaging_visibility = none`. The schema retains that option for future packaged supplementary experiments.

## 5. Workflow and independence

1. The coordinator presents images in a neutral viewer ordered by `annotation_image_id`. Filenames, source paths, manifest fields, crosswalk, tracker and source descriptions must not be exposed to annotators.
2. Annotator A completes only `annotator_a/` records. Annotator B completes only `annotator_b/` records.
3. Neither annotator sees or discusses the other's decisions before both passes are locked.
4. After both independent passes are complete, the adjudicator compares records in `adjudication/adjudication_template.csv`, reviews disagreements/uncertainties/unknowns and completes one final record per image.
5. Original independent records must never be overwritten.
6. Agreement statistics must not be computed until both annotators have completed their independent work, and thresholds must be pre-registered before reviewing outcomes.

## 6. Schema and workflow limitations

The approved schema was not changed. The following limitations were handled through coordinator documentation:

1. `image_id` must match `PIL-######`, but acquired image IDs are source IDs. A coordinator-only crosswalk maps the two.
2. `annotator_id` must match `ANNTR-###` or `ADJ-###`. `ANNTR-001`, `ANNTR-002` and `ADJ-001` are reserved role placeholders, not real people. No annotator identity is fabricated.
3. `annotation_timestamp` is required, so templates use `1970-01-01T00:00:00Z` as an explicit not-annotated sentinel, not an annotation time.
4. Boolean fields have no pending value. Their `false` values are neutral template defaults, not annotator decisions.
5. The schema's `adjudication` object cannot store both original decisions, a disagreement summary or a reviewer identity. The separate adjudication CSV preserves those fields. A minimal future schema change could add optional `annotator_a_decisions`, `annotator_b_decisions`, `disagreement_summary` and `reviewer_id` properties to that object. This is a proposal only and has not been applied.
6. Source paths and filenames contain class-derived directory names. A neutral viewer setup is required to prevent context leakage.

## 7. Validation results

Validation was run against the prepared workspace. Results:

| Check | Result |
|---|---:|
| Manifest rows | 20 |
| Manifest distinct target classes | 15 |
| Manifest target classes present in schema | 15 / 15 |
| Crosswalk rows resolving to actual image files | 20 / 20 |
| Crosswalk image SHA-256 values matching manifest | 20 / 20 |
| Independent Annotator A templates | 20 |
| Independent Annotator B templates | 20 |
| Adjudicated-final templates prepared | 20 |
| Annotation IDs unique | 60 / 60 |
| JSON templates conforming to the schema field structure | 60 / 60 |
| Templates with no confirmed label or fabricated result | 60 / 60 |
| Adjudication CSV rows pending | 20 / 20 |
| Progress tracker rows showing both passes pending | 20 / 20 |
| Train/validation/test split files created | 0 |
| Audited source files unchanged | 10 / 10 |
| Validation outcome | PASS |

Full validator output was produced by the preparation script run and reported no failures after the progress report was created.

## 8. Remaining work and limitations

- Two human annotators must be assigned real IDs before distribution if identity tracking is required.
- Human visual review of all 20 images remains pending, including every priority check listed in section 3.2.
- Both independent annotation passes must be completed before adjudication.
- Adjudication, reviewer identity, decision reasons and disagreement summaries must be recorded by a human reviewer.
- Agreement statistics and class-confusion analysis must wait until both passes are complete and thresholds are pre-registered.
- No dataset split may be created until annotation and QC gates are complete.

## 9. Integrity statement

No model was trained. No train/validation/test split was created. No image was modified, cropped, resized, reformatted or augmented. No provenance, licence, taxonomy, mapping or approved schema record was changed. No application code or database was changed. No human annotation or adjudication result was fabricated.
