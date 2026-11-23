# RasaLanka Phase 13.3.6 — Missing Ingredient Acquisition Plan

**Audit date:** 2026-11-23
**Status:** Planning only. No images were acquired. No external download was performed. This plan does not grant reuse rights; every candidate image requires source, licence, attribution, and visual-class verification before use.

## 1. Current coverage snapshot

- Catalogue ingredients: 116; mapped: 106; unmapped: 10.
- In-scope pilot classes with no collected_dataset candidate folder: **11**.
- In-scope pilot classes with candidate folders but no visual verification: **32**.
- All collected_dataset labels are unverified; candidate counts are not approved training counts.

## 2. Priority acquisition / remediation targets

| Priority | Ingredient / class | Why | Minimum evidence before use | Suggested capture/acquisition notes |
| --- | --- | --- | --- | --- |
| P1 | black_mustard_seed | No candidate folder; recipe-used class; visually confusable with mustard/fenugreek/cumin. | Source URL/page, creator, licence, attribution, SHA-256, visual review. | Macro spoon/bowl shots; include scale; negatives with mustard_seed, cumin_seed, fenugreek_seed. |
| P1 | cumin_seed | No candidate folder; frequent spice; small-seed confusion risk. | Per-image licence and provenance. | Whole seeds, dry-roasted, spoon and pile; avoid packaging-only images. |
| P1 | fennel_seed | No candidate folder; approved class with confusion risk. | Per-image licence and provenance. | Capture ridges/elongated shape; negatives with cumin_seed and coriander_seed. |
| P1 | fenugreek_seed | No candidate folder; used in 12 recipe rows. | Per-image licence and provenance. | Rectangular yellow-brown seeds; include roasted and unroasted; no leaves. |
| P1 | mustard_seed | No candidate folder; frequent class; distinct from black_mustard_seed. | Per-image licence and provenance. | Capture colour/size variation; separate from black mustard and powder. |
| P1 | curry_powder / roasted_curry_powder | No curry powder folder; blend and roast states not visually separable. | Per-image licence and provenance; label-visible pass only if package used. | Photograph powder in bowl/spoon and labelled package separately; do not create roast subclasses without evidence. |
| P1 | goraka | No candidate folder; souring ingredient with paste/pulp confusion. | Per-image licence and provenance. | Dried pieces and paste only with visible texture/label evidence; negatives with tamarind. |
| P1 | tamarind_paste | Tamaribd folder unresolved pulp/paste; class requires texture evidence. | Per-image licence and provenance. | Acquire labelled paste/jar and spoon texture shots; keep separate from tamarind_pulp. |
| P1 | jackfruit_seed | No seed-specific folder; only 1 acquired pilot image. | Per-image licence and provenance. | Boiled/raw/peeled seeds in bowl and cut views; negatives with chestnut/chickpea-like items. |
| P1 | ginger_garlic_paste | No candidate folder; paste class is packaging/texture dependent. | Per-image licence and provenance. | Label-visible and texture-visible captures; reject if identity only inferred from recipe. |
| P2 | mustard_powder | Mapped as ML class (catalogue ID 62 Mustard, ground). No candidate folder. | Per-image licence and provenance. | Powder in spoon/bowl; label-visible evidence if packaged; negatives with turmeric/curry/chilli powders. |
| P2 | white_pepper (defer) | Out-of-scope in pilot schema; no candidate folder. | Schema decision + per-image rights. | Only collect after pilot schema review; store separately from active training set. |
| P2 | vinegar (defer) | Out-of-scope in pilot schema; clear liquid confusable. | Schema decision + label evidence + rights. | Collect only labelled bottles/containers after schema decision; never infer from clear liquid. |
| P2 | cooking_oil | No candidate folder; oil type usually not visible. | Per-image rights; label/solid-state evidence. | Prefer labelled bottles or solid coconut-oil state comparison; do not treat clear oil as class evidence. |

## 3. Folder remediation before model use

| Folder | Issue | Action |
| --- | --- | --- |
| Greeb Chilli | 49 files, all 32x32; misspelled green chilli. | Do not use for training. Replace with rights-cleared >=224px images after visual confirmation. |
| Ginger | 41/185 files are 32x32; otherwise plausible. | Quarantine 32x32 files; human-review remaining images for ginger_root vs processed ginger. |
| Egg Plant | 663 files below 224px min side. | Review low-res subset; keep only clear brinjal examples. |
| Tamaribd | 6733 files; misspelled; pulp/paste ambiguous; high duplication/near-dup. | Split only after human review into tamarind_pulp/tamarind_paste/reject/unknown. No automatic merge. |
| dhal | 23 files; label may mean cooked dish or raw lentils. | Human review; keep only raw red lentils/parippu matching class definition. |
| Jack Fruit | 430 files; generic fruit label, ambiguous maturity/part; not evidence of jackfruit_seed. | Human review into mature_jackfruit/young_jackfruit/reject; do not assign by folder name and do not use as jackfruit_seed evidence. |
| Red Chilli | 1178 files; dried vs fresh ambiguous. | Human review against dried_red_chilli definition; exclude fresh red chillies from this class. |
| Coriander | 796 files; seed vs leaf ambiguous. | Human review; keep only coriander_seed examples for approved class. |
| Black Pepper / Paper Corn | Potential whole-vs-ground and misspelling ambiguity. | Review separately; do not merge. Map only confirmed whole peppercorns to black_pepper_whole. |
| Salt / Sugar / Coconut oil | Explicitly out-of-scope in pilot schema. | Keep out of initial pilot training unless schema/label policy is updated and rights are verified. |
| Rice / Maldive Fish | Absent from both pilot in-scope and out-of-scope label sets. | Do not annotate under the current schema; resolve label-set scope before any use. |

## 4. Acquisition workflow (required before any new image enters training)

1. **Source capture** - Prefer project-captured images or authoritative sources with per-image licences. Record creator, date, device/capture context, and consent where applicable.
2. **Licence verification** - Record source URL, licence name/URL, attribution text, and whether redistribution/derivatives/model training are permitted. If unclear, mark unverified and exclude.
3. **Dedup/leakage check** - Compute SHA-256 and pHash before adding. Reject exact duplicates; review near-duplicates against all splits.
4. **Class verification** - Human review against approved taxonomy definition. Do not infer class from filename, folder, or recipe context alone.
5. **Quality gate** - Reject unreadable/corrupt, severe blur, tiny/unrecognizable subject, watermark-dominant, packaging-only where label is required but absent, or ambiguous multi-ingredient images unless multi-label policy applies.
6. **Manifest entry** - Add row to acquisition manifest with source, rights, hash, dimensions, proposed class, reviewer, status, and restrictions. Keep raw originals immutable.

## 5. Do-not-assume rules

- Do not treat public web availability, search-index visibility, or social-media presence as reuse permission.
- Do not merge visually similar classes without taxonomy approval.
- Do not use `collected_dataset` as approved training data until rights and labels are verified.
- Do not count candidate folder images as verified class coverage.