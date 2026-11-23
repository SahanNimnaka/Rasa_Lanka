# RasaLanka Phase 13.3.6 — Complete Ingredient Image Dataset Coverage Audit

**Audit date:** 2026-11-23
**Status:** Research audit only. No images were modified, renamed, moved, deleted, augmented, or used for training. No taxonomy IDs were invented and no classes were silently merged.

## 1. Scope and evidence

- Audited dataset: `research/phase-13.3/collected_dataset` (read-only).
- Taxonomy sources: `research/phase-13.2/ingredient_taxonomy.xlsx`, `research/phase-13.2/ml_class_to_catalogue.csv`, `research/phase-13.2/visual_class_feasibility_report.md`, `research/phase-13.3/pilot_annotation_schema.json`.
- Recipe/catalogue sources: `backend/database/rasalanka_import/recipes_import.csv`, `recipe_ingredients_import.csv`, `ingredients_import.csv`, and taxonomy Recipe Usage sheet.
- Provenance sources: all text documents under `research/phase-13.2` and `research/phase-13.3`, `frontend/public/images/*/CREDITS.md`, and `research/phase-13.3.zip` listing.
- Automated checks completed: recursive file inventory; format/dimension/readability; SHA-256 exact duplicates; 64-bit DCT pHash near-duplicate screening (Hamming distance 1-10); Laplacian variance blur heuristic on 256px grayscale. A full-corpus OCR text-detection pass was attempted but did not complete; no text/watermark counts are claimed. Visual semantic correctness was **not** verifiable by automation.

## 2. Dataset inventory

- Top-level class folders: **35**
- Total files: **24,367**
- Total bytes: **1,122,888,856** (1070.87 MiB)
- File formats: **.jpg only** (Pillow format: JPEG x24,367)
- Readability/corruption: **0 unreadable/corrupted** out of 24,367
- Nested directories: **none** (max depth 2: folder/file.jpg)
- Empty folders: **none**
- Unexpected non-image files: **none**
- Filename pattern: **24,203/24,367** files use Roboflow-style `..._jpg.rf.<32-hex>.jpg` names; 164 use plain camera/numeric names. Same-stem groups are a leakage/duplication risk, reported in the quality report; exact-byte duplicates are reported by SHA-256.

### Folder inventory

| Folder | Files | MiB | Top width:count | Min side | Low<224 | Low<128 | Blur<100 | SHA dup files | pHash near pairs | Text-screened |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Black Pepper | 620 | 36.5 | 640:620 | 640-640 | 0 | 0 | 1 | 0 | 273 | not screened |
| Cardamom | 615 | 46.48 | 640:615 | 640-640 | 0 | 0 | 48 | 0 | 7669 | not screened |
| Chiken | 56 | 1.89 | 640:56 | 640-640 | 0 | 0 | 4 | 0 | 15 | not screened |
| Chile Flakes | 25 | 1.4 | 640:25 | 640-640 | 0 | 0 | 0 | 0 | 0 | not screened |
| Chili Powder | 931 | 94.02 | 622:931 | 622-622 | 0 | 0 | 0 | 6 | 383 | not screened |
| Cinnamon | 402 | 1.71 | 224:402 | 224-224 | 0 | 0 | 145 | 2 | 5943 | not screened |
| Cloves | 303 | 11.48 | 640:303 | 640-640 | 0 | 0 | 184 | 2 | 3585 | not screened |
| Coconut milk | 11 | 0.25 | 554:3 | 225-678 | 0 | 0 | 0 | 0 | 0 | not screened |
| Coconut oil | 18 | 0.39 | 447:7 | 369-738 | 0 | 0 | 0 | 0 | 48 | not screened |
| Coriander | 796 | 57.46 | 640:796 | 640-640 | 0 | 0 | 0 | 0 | 28 | not screened |
| Curry Leaves | 114 | 6.08 | 640:114 | 640-640 | 0 | 0 | 1 | 0 | 226 | not screened |
| Egg Plant | 1017 | 6.87 | 176:582 | 142-312 | 663 | 0 | 175 | 2 | 6577 | not screened |
| Fish | 3000 | 156.42 | 640:3000 | 640-640 | 0 | 0 | 99 | 0 | 230 | not screened |
| Ginger | 185 | 11.83 | 640:144 | 32-640 | 41 | 41 | 0 | 0 | 12 | not screened |
| Gralic | 599 | 17.01 | 480:535 | 480-640 | 0 | 0 | 97 | 134 | 36 | not screened |
| Grated coconut | 22 | 0.54 | 447:5 | 225-678 | 0 | 0 | 1 | 0 | 0 | not screened |
| Greeb Chilli | 49 | 0.07 | 32:49 | 32-32 | 49 | 49 | 0 | 0 | 5 | not screened |
| Jack Fruit | 430 | 35.73 | 224:164 | 159-2048 | 88 | 0 | 0 | 0 | 47 | not screened |
| Lime | 969 | 40.73 | 640:969 | 640-640 | 0 | 0 | 60 | 0 | 1415 | not screened |
| Maldive Fish | 1413 | 58.76 | 640:1413 | 640-640 | 0 | 0 | 5 | 0 | 1574 | not screened |
| Onion | 149 | 4.36 | 640:149 | 640-640 | 0 | 0 | 25 | 0 | 34 | not screened |
| Pandan Leaf | 240 | 8.01 | 640:240 | 640-640 | 0 | 0 | 2 | 0 | 53 | not screened |
| Paper Corn | 216 | 15.7 | 640:216 | 640-640 | 0 | 0 | 0 | 0 | 25 | not screened |
| Polos | 706 | 32.08 | 640:706 | 640-640 | 0 | 0 | 0 | 0 | 387 | not screened |
| Potatos | 210 | 7.83 | 672:210 | 672-672 | 0 | 0 | 0 | 0 | 3 | not screened |
| Pumpkin | 245 | 11.6 | 640:245 | 640-640 | 0 | 0 | 10 | 0 | 16 | not screened |
| Red Chilli | 1178 | 28.43 | 640:1178 | 640-640 | 0 | 0 | 199 | 62 | 4134 | not screened |
| Rice | 492 | 27.81 | 640:492 | 640-640 | 0 | 0 | 171 | 0 | 42 | not screened |
| Salt | 77 | 2.43 | 640:77 | 640-640 | 0 | 0 | 0 | 0 | 3 | not screened |
| Small Onion | 1222 | 21.45 | 640:1222 | 640-640 | 0 | 0 | 1029 | 0 | 18238 | not screened |
| Sugar | 599 | 41.72 | 640:599 | 640-640 | 0 | 0 | 0 | 0 | 708 | not screened |
| Tamaribd | 6733 | 220.08 | 640:6733 | 640-640 | 0 | 0 | 1618 | 1122 | 31016 | not screened |
| Tomato | 337 | 13.19 | 640:337 | 640-640 | 0 | 0 | 0 | 0 | 368 | not screened |
| Turmeric | 365 | 13.14 | 512:365 | 512-512 | 0 | 0 | 1 | 0 | 33 | not screened |
| dhal | 23 | 37.44 | 4080:15 | 900-4080 | 0 | 0 | 0 | 0 | 8 | not screened |

## 3. Folder-name and mapping investigation

Folder labels are **candidate labels only**. They were not treated as verified ground truth. No folder was renamed or merged.

| Collected folder | Observed count | Proposed approved class(es) | Status | Rationale / action |
| --- | --- | --- | --- | --- |
| Black Pepper | 620 | black_pepper_whole, black_pepper_ground (ambiguous) | ambiguous | Generic label does not distinguish whole peppercorns from ground pepper; taxonomy has separate classes and this folder may contain either/both. |
| Cardamom | 615 | cardamom_pod | direct | Direct name match; verify pods not seeds/ground cardamom. |
| Chiken | 56 | chicken | spelling | Misspelling of Chicken. Proposed mapping to chicken; visual confirmation required. |
| Chile Flakes | 25 | chilli_flake | spelling | Spelling variant of chilli flakes; direct class candidate. Keep separate from whole dried chillies and powder. |
| Chili Powder | 931 | chilli_powder | spelling | Spelling variant of chilli powder; roasted chilli powder is a variant of the same parent class but roast state is not image-verifiable. |
| Cinnamon | 402 | cinnamon_bark | direct | Direct label; Ceylon cinnamon (catalogue ID 7) is a taxonomy variant but cannot be visually separated without packaging. |
| Cloves | 303 | clove | direct | Direct name match to clove class. |
| Coconut milk | 11 | coconut_milk | direct | Direct match; thick/thin/mixture IDs are retained as taxonomy variants but not visually separable in unlabelled liquid. |
| Coconut oil | 18 | coconut_oil (conditional) | conditional | Oil type is generally not visible without label or solid-state evidence; confusable with generic cooking oil. |
| Coriander | 796 | coriander_seed (proposed, ambiguous) | ambiguous | Folder name omits seed/leaf. Taxonomy only has coriander_seed; coriander leaves are not an approved catalogue class. Human review required. |
| Curry Leaves | 114 | curry_leaves | direct | Direct name match; distinguish from pandan by pinnate shape. |
| Egg Plant | 1017 | brinjal | naming | Naming variant of brinjal/eggplant. |
| Fish | 3000 | fish | direct | Generic fish class. Sailfish/tuna are taxonomy variants; species rarely verifiable from image. |
| Ginger | 185 | ginger_root | direct | Direct match to fresh ginger root; 41 tiny 32x32 images need exclusion/replacement review. |
| Gralic | 599 | garlic_clove | spelling | Misspelling of Garlic. Proposed mapping to garlic_clove. |
| Grated coconut | 22 | grated_coconut | direct | Direct match; fresh/desiccated not reliably visible after grating. |
| Greeb Chilli | 49 | green_chilli | spelling | Misspelling of Green Chilli. Proposed mapping to green_chilli; all 49 images are 32x32 and need replacement/review. |
| Jack Fruit | 430 | mature_jackfruit (proposed; seeds not evidenced) | ambiguous | Generic fruit-folder label. Taxonomy has mature_jackfruit, young_jackfruit and jackfruit_seed as separate classes. Folder name is not evidence of jackfruit_seed; no seed-specific folder exists in collected_dataset. Human review required. |
| Lime | 969 | lime | direct | Direct match to whole lime fruit class. |
| Maldive Fish | 1413 | maldive_fish | direct | Direct match; flakes and chunks are same class. |
| Onion | 149 | onion (generic/large) | direct | Direct match to generic onion class; red-skinned samples should be re-labelled to red_onion during annotation. |
| Pandan Leaf | 240 | pandan_leaves | direct | Direct name match. |
| Paper Corn | 216 | black_pepper_whole (proposed, uncertain) | ambiguous | Likely misspelling of Pepper Corn/peppercorn, but name is ambiguous; could be mislabelled. Do not merge into Black Pepper without human visual review. |
| Polos | 706 | young_jackfruit | naming | Polos is Sri Lankan name for young green jackfruit; verify maturity state visually. |
| Potatos | 210 | potato | spelling | Misspelling of Potatoes. |
| Pumpkin | 245 | pumpkin | direct | Direct name match. |
| Red Chilli | 1178 | dried_red_chilli (proposed, ambiguous) | ambiguous | Folder name does not specify fresh vs dried. Taxonomy has dried_red_chilli only for whole red chillies; fresh red chilli is not an approved class. Visual review required. |
| Rice | 492 | raw_rice (conditional) | conditional | Approved class is raw rice; cooked rice is out of scope in pilot schema. Folder content unverified. |
| Salt | 77 | salt (conditional) | conditional | Salt is mapped but out-of-scope in pilot schema and confusable with sugar; requires packaging/context evidence. |
| Small Onion | 1222 | small_onion | direct | Direct match to small onion/shallot class. |
| Sugar | 599 | sugar (conditional) | conditional | Mapped but out-of-scope in pilot schema; confusable with salt/MSG without context. |
| Tamaribd | 6733 | tamarind_pulp and/or tamarind_paste (ambiguous) | spelling | Misspelling of Tamarind. Taxonomy separates tamarind_pulp and tamarind_paste; folder label cannot resolve processing state. |
| Tomato | 337 | tomato | direct | Direct name match. |
| Turmeric | 365 | turmeric_powder (proposed) | ambiguous | Label omits form. Taxonomy has turmeric_powder and no fresh turmeric-root class; verify whether images are powder, root, or mixed. |
| dhal | 23 | red_lentils (proposed) | ambiguous | Lowercase local name commonly refers to parippu/red lentils in recipe context, but folder may contain cooked dhal or other pulses; no visual verification. |

### Explicitly requested ambiguous cases

| Case | Observed folders | Proposed handling | Why not merge now |
| --- | --- | --- | --- |
| Chile Flakes / Chilli Flakes | Chile Flakes (25) | Propose mapping Chile Flakes -> `chilli_flake` after visual review. | Only Chile Flakes exists; Chilli Flakes is not present. Spelling normalization must follow visual confirmation. |
| Chili Powder / Chilli Powder | Chili Powder (931) | Propose mapping Chili Powder -> `chilli_powder`; keep roasted-chilli-powder catalogue variant separate only as metadata. | Only Chili Powder exists. Roast level is not visually reliable. |
| Egg Plant / Brinjal | Egg Plant (1017) | Propose mapping Egg Plant -> `brinjal`. | Naming variant only; confirm images are brinjal/eggplant, not other produce. |
| Gralic / Garlic | Gralic (599) | Propose mapping Gralic -> `garlic_clove`. | Misspelling; confirm cloves/bulbs, not garlic paste or processed forms. |
| Greeb Chilli / Green Chilli | Greeb Chilli (49) | Propose mapping Greeb Chilli -> `green_chilli`, but treat folder as low-quality candidate. | Misspelling and all files are 32x32; unsuitable for training without replacement/review. |
| Paper Corn / Pepper Corn | Paper Corn (216) | Propose likely mapping to `black_pepper_whole` only after human review; keep unresolved until then. | Name is ambiguous/misspelled; could mean peppercorn or be incorrect. No merge into Black Pepper. |
| Tamaribd / Tamarind | Tamaribd (6733) | Propose mapping to `tamarind_pulp` and/or `tamarind_paste` after state review. | Misspelling and processing state is unresolved. Taxonomy keeps pulp and paste separate. |
| dhal / Red Lentils | dhal (23) | Propose candidate mapping to `red_lentils` after visual review. | dhal can mean cooked lentil dish or raw pulse; verify uncooked red lentils/parippu. |

### Unexpected folders investigated

- `images po` / `images_po`: **not found** in repository filesystem, not found in research text, and not present in `research/phase-13.3.zip`. No evidence of purpose. Treat as absent/unresolved reference.
- `ingredients`: exists as frontend UI asset/code directories (`frontend/public/images/ingredients`, `frontend/dist/images/ingredients`, `frontend/src/components/ingredients`, `frontend/src/pages/ingredients`) and **not** as a collected-dataset class folder. Frontend ingredient images are 3 credited Wikimedia thumbnails documented in `frontend/public/images/ingredients/CREDITS.md`; they are separate from collected_dataset and must not be counted as training evidence.
- `phase-13.3.zip`: contains 20 acquired images and selected Phase 13.3 docs, but **omits** `collected_dataset/` and `pilot_annotations/`. It is not provenance for collected_dataset.

## 4. Coverage against approved taxonomy

- Catalogue rows audited: **116**.
- Mapped rows: **106**; unmapped rows: **10**.
- In-scope pilot class IDs present in coverage rows: **43**.
- In-scope classes with candidate collected-dataset folders: **32**.
- In-scope classes with no candidate collected-dataset folder: **11** (black_mustard_seed, cooking_oil, cumin_seed, curry_powder, fennel_seed, fenugreek_seed, ginger_garlic_paste, goraka, jackfruit_seed, mustard_powder, mustard_seed).
- Candidate-folder images whose only proposed classes are pilot in-scope: **21,768**. The remaining **2,599** are in folders proposed only for out-of-scope or absent pilot classes: Coconut oil 18 (explicit out-of-scope), Maldive Fish 1,413 (absent from pilot label sets), Rice 492 (absent), Salt 77 (explicit out-of-scope), Sugar 599 (explicit out-of-scope). These are **candidate pools, not verified labels**.
- Verified image count across all catalogue rows: **0** (no visual label verification has been performed).

### Missing in-scope classes (no collected_dataset candidate folder)

| ML class | Label | Recipe usage | Recipe codes | Acquisition need |
| --- | --- | --- | --- | --- |
| black_mustard_seed | Black mustard seed | 1 | POT-004 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |
| cooking_oil | Cooking oil (generic) | 10 | BRI-001; BRI-002; BRI-003; FIS-002; FIS-003; JAC-001; POT-004; POT-005 | Acquire a rights-documented, labelled set with the diversity and capture conditions in the approved class description; none present in collected_dataset. |
| cumin_seed | Cumin seed | 5 | CHI-002; FIS-003; JAC-002; PAR-002; PUM-001 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |
| curry_powder | Curry powder blend | 13 | BRI-001; BRI-002; BRI-003; CHI-001; FIS-002; FIS-003; JAC-001; JAC-002; POL-001; POT-001; POT-002; PUM-001; PUM-002 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |
| fennel_seed | Fennel seed | 2 | CHI-002; JAC-002 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |
| fenugreek_seed | Fenugreek seed | 12 | BRI-001; BRI-002; CHI-001; FIS-001; FIS-003; JAC-001; JAC-002; PAR-002; POT-001; POT-002; PUM-001; PUM-002 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |
| ginger_garlic_paste | Ginger-garlic paste | 1 | BRI-002 | Acquire a rights-documented, labelled set with the diversity and capture conditions in the approved class description; none present in collected_dataset. |
| goraka | Goraka / gamboge | 2 | FIS-001; FIS-002 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |
| jackfruit_seed | Jackfruit seeds | 1 | JAC-002 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |
| mustard_powder | Ground mustard | 1 | BRI-003 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |
| mustard_seed | Mustard seed | 11 | BRI-001; BRI-002; CHI-001; CHI-002; FIS-002; FIS-003; JAC-002; PAR-001; PAR-002; PUM-001; PUM-002 | Acquire small, clearly labelled, rights-documented set with visible processing state and hard negatives; no collected_dataset candidate folder exists. |

### Unmapped / non-photographable catalogue rows

| ID | Ingredient | Status | Usage | Reason |
| --- | --- | --- | --- | --- |
| 16 | Coconut oil / neutral oil | unmapped_ambiguous | 1 | Disjunctive recipe choice, not a single physical target; no stable image class without label. |
| 17 | Coconut oil or neutral oil | unmapped_ambiguous | 1 | Disjunctive recipe choice; visible target cannot be determined from the image alone. |
| 27 | Curry liquids and remaining aromatics | excluded_not_photographable | 1 | Abstract remainder/category, not a single photographable ingredient. |
| 69 | Oil or coconut oil | unmapped_ambiguous | 1 | Disjunctive choice between two visually similar oils; no single target. |
| 73 | Pandan and curry leaves for paste | unmapped_requires_research | 1 | Composite of two classes; single-label mapping would discard information. |
| 74 | Pandan and curry leaves for tempering | unmapped_requires_research | 1 | Composite of two classes; single-label mapping would discard information. |
| 103 | Thin coconut milk or water | unmapped_ambiguous | 2 | Disjunctive choice between coconut milk and water; the visible target cannot be assumed from the ingredient name alone. |
| 111 | Vinegar or lime juice | excluded_not_photographable | 1 | Disjunctive choice between two visually similar liquids; not a single class. |
| 112 | Water | excluded_not_photographable | 7 | Transparent liquid with no distinctive visual identity in ingredient photos. |
| 113 | Water for parboiling | excluded_not_photographable | 1 | Usage qualifier for water; still not visually identifiable. |

### Recipe-level comparison (all 20 recipes)

| Recipe ID | Recipe | Main ingredient | Ingredient rows | Pilot in-scope rows | In-scope with candidate folder | In-scope with no folder | Not in pilot / unmapped |
| --- | --- | --- | --- | --- | --- | --- | --- |
| BRI-001 | Wambatu Curry – Fried Brinjal with Coconut Milk | Brinjal | 17 | 15 | 10 | 5 | 2 |
| BRI-002 | Wambatu Curry / Brinjal Stew | Brinjal | 17 | 15 | 10 | 5 | 2 |
| BRI-003 | Batu Moju | Brinjal | 20 | 16 | 12 | 4 | 4 |
| CHI-001 | Sri Lankan Kukul Mas Curry – Embassy Recipe | Chicken | 21 | 18 | 15 | 3 | 3 |
| CHI-002 | Kukul Mas Curry – Roasted Spice Version | Chicken | 19 | 14 | 11 | 3 | 5 |
| FIS-001 | Maalu Kirata – White Fish Curry with Coconut Milk | Fish | 14 | 12 | 10 | 2 | 2 |
| FIS-002 | Ambul Thiyal – Sour Fish Curry | Fish | 14 | 11 | 7 | 4 | 3 |
| FIS-003 | Kiri Malu Curry – Sailfish Coconut Milk Curry | Fish | 19 | 17 | 12 | 5 | 2 |
| JAC-001 | Kiri Kos Maluwa – Mature Jackfruit Curry | Jackfruit | 19 | 15 | 12 | 3 | 4 |
| JAC-002 | Kos Ata Kalu Pol Maluwa – Jackfruit Seed Curry | Jackfruit | 27 | 21 | 15 | 6 | 6 |
| PAR-001 | Sri Lankan Parippu Curry – Coconut Milk and Tempering | Parippu | 15 | 12 | 11 | 1 | 3 |
| PAR-002 | Parippu Curry – Tempered Coconut Dhal | Parippu | 18 | 15 | 12 | 3 | 3 |
| POL-001 | Polos Curry – Young Jackfruit Curry | Polos | 15 | 12 | 11 | 1 | 3 |
| POT-001 | Ala Kiri Hodi (Creamy Coconut-Milk Potato Curry) | Potato | 17 | 14 | 12 | 2 | 3 |
| POT-002 | Ala Hodi (Everyday Potato Curry) | Potato | 12 | 11 | 9 | 2 | 1 |
| POT-003 | Ala Thel Dala (Sri Lankan Potato Fry) | Potato | 13 | 9 | 9 | 0 | 4 |
| POT-004 | Devilled Potatoes (Ala Thel Dala – SBS Version) | Potato | 13 | 10 | 8 | 2 | 3 |
| POT-005 | Potato Tempered (Pulse Recipe) | Potato | 11 | 9 | 8 | 1 | 2 |
| PUM-001 | Wattakka Maluwa – Coconut Milk and Roasted Coconut Paste | Pumpkin | 20 | 15 | 11 | 4 | 5 |
| PUM-002 | Wattakka Kalu Pol Maluwa | Pumpkin | 16 | 13 | 10 | 3 | 3 |

Nineteen of 20 recipes have at least one pilot in-scope ingredient with no collected_dataset candidate folder (POT-003 is the exception). No recipe has complete *verified* visual coverage: all folder labels are unverified, rights are uncleared, and 4 or more ingredient rows per recipe are not in the pilot label set or are unmapped. Counts are candidate-mapping counts, not verified images.

## 5. Explicitly required ingredient status

| Ingredient | Class/ID | Recommendation | Recipe usage | Recipe codes | Candidate folders | Mapping status | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Jackfruit seeds | jackfruit_seed | Conditional include | 1 | JAC-002 | None | mapped | Approved distinct class; only one acquired pilot seed image exists. `Jack Fruit` folder is generic fruit, not seed evidence. |
| Black mustard seeds | black_mustard_seed | Conditional include | 1 | POT-004 | None | mapped | Approved tiny-seed class; no collected_dataset candidate folder. Needs scale reference and separation from mustard_seed. |
| Cumin seeds | cumin_seed | Conditional include | 5 | CHI-002; FIS-003; JAC-002; PAR-002; PUM-001 | None | mapped | Approved class; no candidate folder. One acquired pilot whole-seed image exists. |
| Curry powder | curry_powder | Conditional include | 13 | BRI-001; BRI-002; BRI-003; CHI-001; FIS-002; FIS-003; JAC-001; JAC-002; POL-001; POT-001; POT-002; PUM-001; PUM-002 | None | mapped | Approved blend class; no candidate folder. Regional/roast variants are not visually separable without context. |
| Fennel seeds | fennel_seed | Conditional include | 2 | CHI-002; JAC-002 | None | mapped | Approved class; no candidate folder. |
| Fenugreek seeds | fenugreek_seed | Conditional include | 12 | BRI-001; BRI-002; CHI-001; FIS-001; FIS-003; JAC-001; JAC-002; PAR-002; POT-001; POT-002; PUM-001; PUM-002 | None | mapped | Approved class; no candidate folder; generic fenugreek maps to seed form in this corpus. |
| Mustard seeds | mustard_seed | Conditional include | 11 | BRI-001; BRI-002; CHI-001; CHI-002; FIS-002; FIS-003; JAC-002; PAR-001; PAR-002; PUM-001; PUM-002 | None | mapped | Approved class; no candidate folder; keep separate from black_mustard_seed and mustard_powder. |
| Mustard powder | mustard_powder | Conditional include | 1 | BRI-003 | None | mapped | ML class exists; catalogue literal is ID 62 `mustard` with ground preparation. No candidate folder; packaging/texture evidence required. |
| White pepper | white_pepper | Exclude from initial pilot | 1 | FIS-002 | None | mapped | Mapped in taxonomy but out-of-scope in pilot schema; visually confusable with other pale powders and no candidate folder. |
| Roasted chilli powder | chilli_powder | Conditional include (as parent class metadata) | 14 | BRI-001; BRI-002; BRI-003; CHI-001; CHI-002; FIS-003; JAC-001; JAC-002; PAR-001; POL-001; POT-001; PUM-001; PUM-002 | Chili Powder | mapped | Catalogue ID 85 is variant of `chilli_powder`; roast state not reliably image-visible. Chili Powder folder cannot validate roast. |
| Roasted curry powder | curry_powder | Conditional include (as parent class metadata) | 13 | BRI-001; BRI-002; BRI-003; CHI-001; FIS-002; FIS-003; JAC-001; JAC-002; POL-001; POT-001; POT-002; PUM-001; PUM-002 | None | mapped | Catalogue ID 86 is variant of `curry_powder`; roast state not reliably image-visible. No curry powder folder. |
| Goraka | goraka | Conditional include | 2 | FIS-001; FIS-002 | None | mapped | Approved souring class; no candidate folder. Paste and dried forms need careful review; confusable with tamarind. |
| Tamarind paste | tamarind_paste | Conditional include | 1 | POL-001 | Tamaribd | mapped | Approved separate class from tamarind_pulp; Tamaribd folder is misspelled and state unresolved. |
| Vinegar | vinegar | Exclude from initial pilot | 3 | BRI-003; CHI-001; CHI-002 | None | mapped | Mapped in taxonomy but out-of-scope in pilot schema; clear liquids are highly confusable and label-dependent. |

## 6. Risks and required follow-up

- **No visual label verification performed.** All class assignments above are candidate mappings from folder names/taxonomy; human review is required before training.
- **Provenance missing for collected_dataset.** 24,367 files have no manifest, licence, author, source URL, or acquisition record. Public availability must not be treated as reuse rights.
- **High duplication/redundancy.** 665 exact duplicate groups (1,330 files) and 41,567 pHash near-duplicate pairs (1-10) indicate augmentation/export redundancy; 6,232 cross-folder near-duplicate pairs require manual inspection for label noise/leakage.
- **Quality outliers.** 841 images have min side <224 (including Greeb Chilli all 32x32 and 41 Ginger 32x32); blur heuristic flagged 3,875 images at Laplacian variance <100 (screening only).
- **Ambiguous folder labels.** Coriander, Red Chilli, Jack Fruit, Turmeric, dhal, Black Pepper, Paper Corn, Tamaribd require human disambiguation.
- **Pilot schema mismatch.** Six taxonomy-mapped classes are explicitly out-of-scope in the pilot label set (white_pepper, lime_juice, vinegar, salt, sugar, coconut_oil); maldive_fish and raw_rice are absent from both pilot label sets. Do not train or annotate them without a schema decision.
