# Step 8A Mapping Decision Register

- Generated: 2026-11-24T16:57:44.281622+00:00
- Scope: Remaining 929 mapping warnings from Step 8A human verification master audit
- Master CSV labels preserved: **yes** (no silent rewrites)
- Approved taxonomy modified: **no**
- Folder mapping modified: **no**
- Dataset images modified: **no**

## Decisions

| Folder | Observed label | Records | Decision type | Canonical class | Canonical mapping pending | Alias approved | Original label preserved | Rationale |
|---|---|---:|---|---|---:|---:|---:|---|
| Curry Leavf | Curry Leaf | 114 | approved_alias | curry_leaves | false | true | true | Human verification uses display name 'Curry Leaf'; approved taxonomy canonical ID is 'curry_leaves'. This is a display-name/canonical-ID alias. Folder name typo ('Curry Leavf') is already documented in folder_class_mapping.json. |
| Gralic | Garlic | 599 | pending_canonical_mapping |  | true | false | true | Preserve existing 'Garlic' label. Do not automatically map to 'garlic_clove' because the display label does not establish exact garlic form (clove/bulb vs processed/paste). Canonical mapping remains pending visual/provenance confirmation. |
| Paper Corn | Pepper Corn | 216 | pending_canonical_mapping |  | true | false | true | Preserve existing 'Pepper Corn' label. Do not split into whole or ground pepper and do not force a canonical taxonomy mapping. Folder mapping is ambiguous_pending_review; manual review required before any canonical assignment. |

## Evidence references

### Curry Leavf / Curry Leaf
- research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json contains in-scope class 'curry_leaves'
- research/phase-13.3/annotation_pipeline/config/folder_class_mapping.json proposes 'curry_leaves' for folder 'Curry Leavf' (proposed_pending_review)
- human_verification_master.csv has 114 reviewed records with Expected/Verified='Curry Leaf'

### Gralic / Garlic
- research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json contains 'garlic_clove' but no generic 'garlic'
- research/phase-13.3/annotation_pipeline/config/folder_class_mapping.json proposes 'garlic_clove' for folder 'Gralic' with rationale to confirm cloves/bulbs rather than paste
- human_verification_master.csv has 599 reviewed records with Expected/Verified='Garlic'

### Paper Corn / Pepper Corn
- research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json contains 'black_pepper_whole' and 'black_pepper_ground' but no generic peppercorn class
- research/phase-13.3/annotation_pipeline/config/folder_class_mapping.json marks 'Paper Corn' ambiguous_pending_review with candidate ['black_pepper_whole'] and requires visual review
- human_verification_master.csv has 216 reviewed records with Expected/Verified='Pepper Corn'

## Notes

- `approved_alias`: display name is accepted as an alias for a canonical taxonomy ID for reporting/validation; original master CSV display label remains unchanged.
- `pending_canonical_mapping`: label preserved, no canonical mapping forced; requires confirmation/review before any training-label export uses a canonical ID.
