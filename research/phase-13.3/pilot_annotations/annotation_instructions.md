# Phase 13.3.4 — Independent Annotation Instructions

These instructions apply to both Annotator A and Annotator B. Work independently. Do not look at, copy, discuss or infer the other annotator's decisions before both passes are complete and locked.

## 1. What to annotate

Each image has one image-level, no-label annotation record in JSON. Annotate only what is directly visible in the image. Do not use recipe context, filenames, source paths, source descriptions, the manifest intended class, the progress tracker, the crosswalk or any other annotator's work as evidence.

Use only the 43 in-scope class IDs in `pilot_annotation_schema.json` for `ingredient_labels`. Each distinct visible in-scope class is labelled once, regardless of how many pieces appear. Quantity, count, mass and volume are not annotated.

## 2. How to identify visible ingredients

1. Look at the whole image before deciding.
2. Name the ingredient from intrinsic visual evidence: shape, skin, cut surface, texture, colour and standard kitchen context.
3. Record one in-scope class ID per distinct visible ingredient in `ingredient_labels`.
4. Record visible processing state (`whole`, `dried`, `powder`, `paste`, `sliced`, and so on) in `processing_state_evidence`. Processing states are attributes, not new classes.
5. If an excluded class or non-catalogue negative is visible, put its ID in `out_of_scope_labels`, never in `ingredient_labels`.
6. Set `visibility_flags` truthfully: partial visibility, overlap, multiple ingredients and package-label use. For these 20 no-label records, `package_label_used` must remain `false`.

## 3. Uncertainty, unknown and out-of-scope

- If a visible ingredient may belong to more than one in-scope class and you cannot resolve it, do not force a final label. Put the candidate IDs in `uncertain_labels` and explain in `notes`.
- If an ingredient-like item is present but cannot be assigned an in-scope or named out-of-scope label, set `unknown_ingredient_present` to `true` and describe it in `unknown_description`. Never invent a catalogue ID for it.
- If the ingredient is one of the excluded reference negatives, use `out_of_scope_labels`. Excluded classes are never model targets in this pilot.
- If you are uncertain whether a class is applicable, prefer `uncertain_labels` over a guessed `ingredient_labels` entry.

## 4. Multiple ingredients and misleading context

Label every distinct in-scope ingredient that remains individually identifiable. Mark `multi_ingredient_scene=true` when more than one ingredient is visible. Mark `overlap_present=true` when ingredients obscure one another. Do not label a hidden ingredient merely because it is expected.

Context can mislead: curry leaves photographed on a plant are still the leaf ingredient, not a prepared dish; a whole jackfruit may not show the yellow bulbs that define the taxonomy example; bowls are serving containers, not product packaging. Judge the ingredient itself.

## 5. Why packaging text must not determine a no-label annotation

All 20 templates are `no_label` records. Package text, labels and source descriptions must not be used. If a package label is visible, ignore it for this pass and keep `package_label_used=false`. A separate `label_visible` pass exists in the schema for future packaged images, but none is prepared here because all acquired images are recorded as having no product packaging.

## 6. Independence

Annotator A and Annotator B must complete their records independently. Do not share screens, files, label choices, uncertainty lists or notes. Do not use the other person's completed results to influence your own. The adjudication step exists precisely to compare independent decisions after both are locked.

## 7. Recording difficult examples

For any difficult, ambiguous or uncertain image:

- use `uncertain_labels` rather than guessing;
- set the relevant `visibility_flags`;
- add a short factual note describing the visible evidence and the reason for uncertainty;
- never add the intended manifest class to your record.

## 8. How to fill the template

Each JSON file already satisfies the schema structure. Replace the sentinel timestamp `1970-01-01T00:00:00Z` with the actual completion time. Replace neutral boolean defaults with your own decisions. Empty arrays mean no label selected yet; fill them only from your own visual judgement. Do not change `schema_version`, `annotation_id`, `image_id`, `record_role`, `annotator_id` or `annotation_pass`.

## 9. Known schema/workflow limitations (coordinator note; no schema change applied)

1. The schema requires `image_id` to match `PIL-######`, while acquisition IDs are source IDs. `image_id_crosswalk.csv` maps `PIL-000001`..`PIL-000020` to the 20 acquired images. Use the PIL ID in annotation records.
2. The schema requires `annotator_id` to match `ANNTR-###` or `ADJ-###`. `ANNTR-001`, `ANNTR-002` and `ADJ-001` are reserved role placeholders, not real identities. The coordinator must replace them with actually assigned IDs before distribution if identities are required; no person is asserted here.
3. `annotation_timestamp` is required. The epoch value is a sentinel meaning not yet annotated, not an annotation time.
4. Boolean fields have no `pending` value in the schema. Their current `false` values are neutral template defaults and are not human decisions.
5. Acquired image paths and filenames contain source class hints. Do not expose them to annotators. The coordinator must present images in a neutral viewer ordered by `annotation_image_id`; coordinator-only assignment files are not for annotator distribution.
6. The schema's adjudication object has no fields for both original decisions, disagreement summary or reviewer identity. The separate `adjudication/adjudication_template.csv` preserves those fields. A minimal future schema change could add optional `annotator_a_decisions`, `annotator_b_decisions`, `disagreement_summary` and `reviewer_id` properties to the adjudication object; this change is proposed only and has not been applied.
