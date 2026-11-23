# RasaLanka Phase 13.3.6 — Dataset Quality Report

**Audit date:** 2026-11-23
**Status:** Automated screening + provenance/document review. This report does not claim full visual correctness. Human review is required for label correctness, packaging dependence, multi-ingredient suitability, and ambiguous near-duplicates.

## 1. Automated integrity checks

| Check | Result | Notes |
| --- | --- | --- |
| Recursive inventory | 24,367 files; 35 folders | All files under `research/phase-13.3/collected_dataset/<folder>/*.jpg`; no nested dirs. |
| Formats | JPEG only | Pillow format JPEG, mode RGB for all readable files. |
| Readability/corruption | 0 unreadable/corrupt | Every file opened via Pillow verify/load and pixel read. |
| Empty folders | 0 | All 35 folders contain images. |
| Unexpected files | 0 | No non-image files found. |
| Exact duplicates (SHA-256) | 665 groups / 1,330 files / 665 redundant copies | All exact-duplicate groups are within the same folder; no cross-folder exact SHA-256 duplicates. |
| Perceptual near-duplicates (pHash d<=10) | 41,567 pairs | 6,259 pairs at d<=5; 35,308 at d6-10. Screening only; not ground truth. |
| Cross-folder near-duplicate pairs | 6,232 | Highest: Small Onion\|Tamaribd 2370, Cardamom\|Cloves 698, Egg Plant\|Tamaribd 664. Manual review required. |
| Low resolution | 841 files min side <224; 90 files min side <128 | Greeb Chilli is entirely 32x32 (49); Ginger has 41 at 32x32; Egg Plant has 663 below 224. |
| Blur heuristic | 3,875 Laplacian variance <100; 1,570 <50 | Heuristic only; can flag flat backgrounds or smooth textures. Worst concentrations: Small Onion, Tamaribd, Cinnamon, Red Chilli. |
| Text detection heuristic | Not completed | A full-corpus RapidOCR text-box pass was started but did not finish within the available compute budget; no counts are claimed. Watermark, packaging-text and text-overlay screening therefore remains a manual human-review task. The coverage CSV records text_positive=0 as 'not screened', not as 'no text found'. |

## 2. Exact duplicates

- Exact duplicate groups: **665**. Files in duplicate groups: **1330**. Redundant copies: **665**.
- All exact duplicate groups are same-folder; no exact cross-folder duplicates were detected.
- Top folders by exact-duplicate file count: Tamaribd (1122), Gralic (134), Red Chilli (62), Chili Powder (6), Egg Plant (2), Cloves (2), Cinnamon (2).

## 3. Near-duplicate screening

- Method: 64-bit DCT pHash on grayscale 32x32; Hamming distance 1-10 reported. Candidates generated via 16-bit block bucketing; all candidates verified by exact Hamming distance.
- Pairs by distance: d<=5 = 6,259; d6-10 = 35,308; total = 41,567.
- Cross-folder pairs: **6,232**. Same-folder pairs: **35,335**.
- Clusters at d<=5 (connected components): 1803; largest cluster sizes: 62, 61, 60, 54, 46, 39, 38, 30, 27, 25, 25, 23, 22, 22, 22, 21, 19, 18, 18, 17.
- Interpretation: many same-stem Roboflow export variants exist (17,288 files in 5,718 same-stem groups before extension hash). These should be treated as near-duplicates/augmentation artifacts for split design, not independent evidence.

### Top cross-folder near-duplicate pairs

| Folder pair | Pairs (d<=10) | Action |
| --- | --- | --- |
| Small Onion \| Tamaribd | 2370 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Cardamom \| Cloves | 698 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Egg Plant \| Tamaribd | 664 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Cardamom \| Small Onion | 444 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Cardamom \| Tamaribd | 318 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Maldive Fish \| Tamaribd | 187 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Egg Plant \| Small Onion | 127 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Red Chilli \| Tamaribd | 125 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Cinnamon \| Egg Plant | 118 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Cinnamon \| Tamaribd | 102 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Cloves \| Small Onion | 98 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Fish \| Tamaribd | 78 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Curry Leaves \| Small Onion | 74 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Curry Leaves \| Tamaribd | 70 | Human review required - possible mislabel, shared source, or benign visual similarity |
| Cinnamon \| Maldive Fish | 68 | Human review required - possible mislabel, shared source, or benign visual similarity |

## 4. Quality flags by folder

| Folder | Files | Min<224 | Min<128 | Blur<100 | Exact dup files | Cross-folder near-dup incidences | Text-screened | Recommended QA |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Black Pepper | 620 | 0 | 0 | 1 | 0 | 1 | not screened | sample review |
| Cardamom | 615 | 0 | 0 | 48 | 0 | 1563 | not screened | cross-folder near-dup review |
| Chiken | 56 | 0 | 0 | 4 | 0 | 11 | not screened | sample review |
| Chile Flakes | 25 | 0 | 0 | 0 | 0 | 0 | not screened | sample review |
| Chili Powder | 931 | 0 | 0 | 0 | 6 | 1 | not screened | manual text/packaging review; dedupe exact copies |
| Cinnamon | 402 | 0 | 0 | 145 | 2 | 301 | not screened | blur review sample; cross-folder near-dup review; dedupe exact copies |
| Cloves | 303 | 0 | 0 | 184 | 2 | 835 | not screened | blur review sample; cross-folder near-dup review; dedupe exact copies |
| Coconut milk | 11 | 0 | 0 | 0 | 0 | 0 | not screened | manual text/packaging review |
| Coconut oil | 18 | 0 | 0 | 0 | 0 | 46 | not screened | manual text/packaging review |
| Coriander | 796 | 0 | 0 | 0 | 0 | 10 | not screened | sample review |
| Curry Leaves | 114 | 0 | 0 | 1 | 0 | 176 | not screened | cross-folder near-dup review |
| Egg Plant | 1017 | 663 | 0 | 175 | 2 | 1009 | not screened | review low-resolution images; blur review sample; cross-folder near-dup review; dedupe exact copies |
| Fish | 3000 | 0 | 0 | 99 | 0 | 136 | not screened | blur review sample; cross-folder near-dup review |
| Ginger | 185 | 41 | 41 | 0 | 0 | 0 | not screened | remove/replace tiny images; review low-resolution images; high priority replacement |
| Gralic | 599 | 0 | 0 | 97 | 134 | 20 | not screened | blur review sample; dedupe exact copies |
| Grated coconut | 22 | 0 | 0 | 1 | 0 | 0 | not screened | manual text/packaging review |
| Greeb Chilli | 49 | 49 | 49 | 0 | 0 | 5 | not screened | remove/replace tiny images; review low-resolution images; high priority replacement |
| Jack Fruit | 430 | 88 | 0 | 0 | 0 | 21 | not screened | review low-resolution images; manual text/packaging review |
| Lime | 969 | 0 | 0 | 60 | 0 | 199 | not screened | blur review sample; cross-folder near-dup review |
| Maldive Fish | 1413 | 0 | 0 | 5 | 0 | 366 | not screened | cross-folder near-dup review |
| Onion | 149 | 0 | 0 | 25 | 0 | 26 | not screened | sample review |
| Pandan Leaf | 240 | 0 | 0 | 2 | 0 | 9 | not screened | sample review |
| Paper Corn | 216 | 0 | 0 | 0 | 0 | 11 | not screened | sample review |
| Polos | 706 | 0 | 0 | 0 | 0 | 59 | not screened | sample review |
| Potatos | 210 | 0 | 0 | 0 | 0 | 3 | not screened | sample review |
| Pumpkin | 245 | 0 | 0 | 10 | 0 | 12 | not screened | sample review |
| Red Chilli | 1178 | 0 | 0 | 199 | 62 | 168 | not screened | blur review sample; cross-folder near-dup review; dedupe exact copies |
| Rice | 492 | 0 | 0 | 171 | 0 | 0 | not screened | blur review sample; manual text/packaging review |
| Salt | 77 | 0 | 0 | 0 | 0 | 1 | not screened | manual text/packaging review |
| Small Onion | 1222 | 0 | 0 | 1029 | 0 | 3266 | not screened | blur review sample; cross-folder near-dup review |
| Sugar | 599 | 0 | 0 | 0 | 0 | 6 | not screened | manual text/packaging review |
| Tamaribd | 6733 | 0 | 0 | 1618 | 1122 | 4136 | not screened | blur review sample; cross-folder near-dup review; dedupe exact copies |
| Tomato | 337 | 0 | 0 | 0 | 0 | 48 | not screened | sample review |
| Turmeric | 365 | 0 | 0 | 1 | 0 | 19 | not screened | sample review |
| dhal | 23 | 0 | 0 | 0 | 0 | 0 | not screened | manual text/packaging review |

## 5. Visual-semantic checks (automation limits)

| Check | Automation status | Finding / required human action |
| --- | --- | --- |
| Watermarks/logos/text overlays | Not automated | No completed full-corpus text detector was available. Manually inspect for watermarks, logos, packaging labels and text overlays before training or publication. Automated text screening is explicitly out of scope in this audit; no counts are claimed. |
| Packaging-only dependence | Not automated | No completed text detector was available. Manually check oils, milks, vinegars, powders and bagged pulses for label-only dependence; mark as unusable for a visual class unless the ingredient itself is visible. |
| Multi-ingredient scenes | Not automated | Small Onion, Tamaribd, Chili Powder, Cardamom and other folders have high near-duplicate/scene complexity risk; manually inspect representative samples and all cross-folder near-duplicate candidates. |
| Processed/cooked vs raw state | Not automated | dhal, Red Chilli, Turmeric, Jack Fruit, Rice, and Tamaribd folder labels do not encode processing state. Confirm state matches approved class definition. |
| Tiny ingredient visibility | Partial | Low-resolution and blur heuristics flag risk; tiny classes (mustard/fenugreek/cumin/fennel/cardamom/cloves) need close-up scale references. Greeb Chilli 32x32 set is not usable. |
| Wrong/misleading labels | Not automated | No label was visually verified. Treat all folder labels as unverified. Prioritize ambiguous folders: Coriander, Red Chilli, Jack Fruit, Turmeric, dhal, Paper Corn, Black Pepper, Tamaribd. |

## 6. Provenance and rights

- `collected_dataset` has **no manifest, no licence file, no source URLs, no author/creator records, no attribution text, no acquisition dates, and no per-image rights metadata**. The 24,367 filenames carry no rights evidence; `.rf.<hex>` is an opaque export identifier, not provenance.
- Research docs explicitly state: platform/licence/policy-level statements are not per-image proof; per-file checks are required; private research use is not redistribution permission; Open Food Facts packaging may have third-party rights. The user requirement applies: **public availability does not imply reuse rights**.
- Verified provenance exists only for the separate 20-image `research/phase-13.3/acquired_images` manifest and 6 credited frontend Commons thumbnails. Those records do **not** cover collected_dataset.
- Therefore every collected_dataset image must be labelled **rights unverified / not cleared for redistribution or model training** until per-image provenance and licence evidence is captured.
