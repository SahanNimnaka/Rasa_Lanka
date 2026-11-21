# Phase 13.3.3 — External Image Acquisition Validation Report

**Date:** 2026-11-21<br>
**Git HEAD:** `228ff001e2aba4ecbbad57b85127ae2c3da1f73b`<br>
**Scope:** small, traceable pilot collection only. No model training, no application code, no PostgreSQL changes, no package installs, no commits and no pushes.

## 1. Outcome

| Metric | Result |
|---|---:|
| Manifest rows | 20 |
| Successfully acquired original image files | 20 |
| Fruits-360 original-size GitHub images | 10 |
| Wikimedia Commons original files | 10 |
| Open Food Facts packaged-product images | 0 |
| Primary unlabelled images | 20 |
| Supplementary packaged images | 0 |
| Final train/validation/test splits assigned | 0 |
| Exact duplicate groups | 0 |
| Corrupt / unreadable files | 0 |
| Pre-existing research files verified byte-identical | 15 / 15 |

All acquired bytes are original files. No file was cropped, resized, reformatted, augmented or otherwise altered. The three required deliverables are:

- `research/phase-13.3/acquired_images/`
- `research/phase-13.3/acquired_image_manifest.csv`
- `research/phase-13.3/acquisition_validation_report.md` (this file)

## 2. Source and licence verification before acquisition

### Fruits-360 original-size GitHub

- Repository: `https://github.com/fruits-360/fruits-360-original-size`
- Evidence: `LICENSE` and `README.md` on branch `main`, version `2026.10.26.0`.
- Licence: **CC BY-SA 4.0** — `https://creativecommons.org/licenses/by-sa/4.0/`.
- Creator/copyright holder recorded: **Mihai Oltean**.
- Reuse conditions recorded in the manifest: commercial use allowed, attribution required, ShareAlike applies to adaptations, no endorsement, original bytes unchanged.
- Only folders actually present in the original-size branch were used. No assumption was made from the Mendeley v7 description.

### Wikimedia Commons

Each of the ten files was checked through the MediaWiki API for file page, page ID, creator, exact licence, licence URL, original file URL, MIME type, byte size and dimensions before download. Licence deeds for CC BY-SA 4.0, CC BY-SA 3.0 and CC0 1.0 were also reviewed.

| Licence | Commons files | Conditions recorded |
|---|---:|---|
| CC BY-SA 3.0 | 3 | Attribution and ShareAlike required |
| CC BY-SA 4.0 | 4 | Attribution and ShareAlike required |
| CC0 1.0 | 3 | Attribution not required but recorded; no endorsement implied |

The ten Commons SHA-1 values returned by the API were compared with SHA-1 computed from the downloaded bytes; **all ten matched**, confirming that the original files were acquired. API evidence: `https://commons.wikimedia.org/w/api.php?action=query&format=json&prop=imageinfo&iiprop=url%7Csize%7Csha1%7Cmime`.

### Sources not acquired

| Source | Outcome |
|---|---|
| Open Food Facts | API returned HTTP 503 during discovery; zero packaged images acquired. No unverified substitute used. |
| Mendeley Data v7 | Not acquired: metadata says CC BY 4.0 while dataset description says CC BY-SA 4.0. |
| Openverse | Discovery only; official documentation does not guarantee licence verification and terms prohibit catalog scraping. |
| COCO / Food-101 | Not approved for the initial collection. |

## 3. Acquisition notes and access failures

- The first Fruits-360 download attempt used a malformed `raw.githubusercontent.com` path and returned 404. The path was corrected; all ten verified original-size files then downloaded successfully. No substitute image was used.
- Wikimedia API discovery was rate-limited with HTTP 429 in one batch call. Metadata verification succeeded through a subsequent single batched API request.
- Direct Python downloads of Commons original files were repeatedly rate-limited with HTTP 429. The verified original file URLs were then opened in the in-app browser and downloaded through normal browser download actions. This did not bypass authentication or licence controls, and no thumbnail, resampled or unverified substitute was accepted. Browser response headers confirmed `image/jpeg` and the same byte lengths as the API.
- Download timestamps in the manifest are UTC: Fruits-360 from the download script; Commons from the browser download file modification time.

## 4. Acquired image inventory

Full provenance, attribution, licence evidence, local path, byte count, SHA-256, source group and restriction fields are in `acquired_image_manifest.csv`. The abbreviated inventory is:

| Image ID | Source | Target class | Original identifier | Licence | Size | SHA-256 (12) | Source group | Mapping |
|---|---|---|---|---|---|---|---|---|
| F360-BRINJAL-r0_0 | FRUITS360_ORIGINAL_GITHUB | brinjal | Training/eggplant_long_1/r0_0.jpg | CC BY-SA 4.0 | 143x644 | `daa1aebaa2b0` | F360:eggplant_long_1 | possible_synonym |
| F360-BRINJAL-r0_100 | FRUITS360_ORIGINAL_GITHUB | brinjal | Training/eggplant_long_1/r0_100.jpg | CC BY-SA 4.0 | 192x666 | `473bd13b7f10` | F360:eggplant_long_1 | possible_synonym |
| F360-GINGER-r0_0 | FRUITS360_ORIGINAL_GITHUB | ginger_root | Training/Ginger%201/r0_0.jpg | CC BY-SA 4.0 | 417x423 | `2563a0bf53ec` | F360:Ginger 1 | exact |
| F360-GINGER-r1_100 | FRUITS360_ORIGINAL_GITHUB | ginger_root | Training/Ginger%201/r1_100.jpg | CC BY-SA 4.0 | 537x291 | `95baae2aa5df` | F360:Ginger 1 | exact |
| F360-ONION-r0_0 | FRUITS360_ORIGINAL_GITHUB | onion | Training/Onion%202/r0_0.jpg | CC BY-SA 4.0 | 206x282 | `9b660b8cd2d1` | F360:Onion 2 | possible_synonym |
| F360-ONION-r2_100 | FRUITS360_ORIGINAL_GITHUB | onion | Training/Onion%202/r2_100.jpg | CC BY-SA 4.0 | 219x181 | `039925fa1c56` | F360:Onion 2 | possible_synonym |
| F360-RED_ONION-r0_0 | FRUITS360_ORIGINAL_GITHUB | red_onion | Training/Onion%20Red%202/r0_0.jpg | CC BY-SA 4.0 | 399x645 | `8faff3f9568f` | F360:Onion Red 2 | exact |
| F360-RED_ONION-r2_100 | FRUITS360_ORIGINAL_GITHUB | red_onion | Training/Onion%20Red%202/r2_100.jpg | CC BY-SA 4.0 | 411x415 | `c8af394edf85` | F360:Onion Red 2 | exact |
| F360-TOMATO-r0_0 | FRUITS360_ORIGINAL_GITHUB | tomato | Training/Tomato%201/r0_0.jpg | CC BY-SA 4.0 | 481x475 | `2761c1c9c6fb` | F360:Tomato 1 | exact |
| F360-TOMATO-r2_100 | FRUITS360_ORIGINAL_GITHUB | tomato | Training/Tomato%201/r2_100.jpg | CC BY-SA 4.0 | 471x399 | `3ce63416de60` | F360:Tomato 1 | exact |
| WIKI-CARDAMOM-75328298 | WIKIMEDIA_COMMONS | cardamom_pod | 100 Cardamom pods.jpg | CC BY-SA 3.0 | 3000x2000 | `18c99286d662` | WIKI:75328298 | exact |
| WIKI-BLACK_PEPPER-151971300 | WIKIMEDIA_COMMONS | black_pepper_whole | Tellicherry Black Peppercorns.jpg | CC0 | 3024x3024 | `902b5813c405` | WIKI:151971300 | exact |
| WIKI-CORIANDER-151971308 | WIKIMEDIA_COMMONS | coriander_seed | Whole Coriander.jpg | CC0 | 3024x3024 | `e04f2817da4c` | WIKI:151971308 | exact |
| WIKI-CUMIN-151826144 | WIKIMEDIA_COMMONS | cumin_seed | Dried cumin seeds.jpg | CC0 | 3000x3000 | `444e7ed43264` | WIKI:151826144 | exact |
| WIKI-CURRY_LEAVES-114904614 | WIKIMEDIA_COMMONS | curry_leaves | Curry plant (curry leaves).jpg | CC BY-SA 4.0 | 4128x2322 | `3cb3c87b0c48` | WIKI:114904614 | exact |
| WIKI-TAMARIND-24046244 | WIKIMEDIA_COMMONS | tamarind_pulp | Tamarind fruit pulp.JPG | CC BY-SA 3.0 | 1600x1200 | `fa8e60c8b854` | WIKI:24046244 | exact |
| WIKI-DRIED_CHILLI-78362289 | WIKIMEDIA_COMMONS | dried_red_chilli | Byadgi Chilli.jpg | CC BY-SA 4.0 | 3264x2448 | `cb6a12044640` | WIKI:78362289 | possible_synonym |
| WIKI-GREEN_CHILLI-202992840 | WIKIMEDIA_COMMONS | green_chilli | Green chilli 2.jpg | CC BY-SA 4.0 | 4608x2592 | `8ca6fe4d2323` | WIKI:202992840 | exact |
| WIKI-JACKFRUIT_SEED-66850649 | WIKIMEDIA_COMMONS | jackfruit_seed | Jackfruit seeds.jpg | CC BY-SA 4.0 | 3024x4032 | `4fc712598682` | WIKI:66850649 | exact |
| WIKI-MATURE_JACKFRUIT-24423194 | WIKIMEDIA_COMMONS | mature_jackfruit | Jak fruit-Sri Lanka (6).jpg | CC BY-SA 3.0 | 4320x3240 | `85bb5e27f54a` | WIKI:24423194 | possible_synonym |

## 5. Quality checks

### 5.1 File integrity and readability

- All 20 files were opened with Pillow `verify()` and then fully loaded with `load()`.
- No empty, truncated or corrupt files were found.
- Recorded dimensions match the re-read dimensions for all 20 files.
- Wikimedia dimensions match the MediaWiki API for all ten Commons files.
- SHA-256 was computed from raw bytes at acquisition and re-computed from disk; all 20 matched.
- Commons SHA-1 values matched the MediaWiki API; all ten matched.

### 5.2 Exact and near duplicates

- Exact duplicates by SHA-256: **0**.
- Difference hash (`dHash`, 64 bits) distance ≤ 10: **0 pairs**.
- Additional perceptual hash screening found two cross-class compositionally similar candidate pairs at pHash distance 13:
  - `F360-GINGER-r0_0` vs `F360-TOMATO-r2_100`
  - `WIKI-BLACK_PEPPER-151971300` vs `WIKI-CORIANDER-151971308` (same creator, CC0, similar bowl/spice presentation)
- No near-duplicate threshold has been established. These are flagged as candidates for human visual review, not as confirmed duplicates.

### 5.3 Class mapping

- All 15 distinct target ingredient class IDs represented by the 20 acquired image rows are present in `pilot_annotation_schema.json`.
- Mapping status: **14 exact**, **6 possible_synonym**.
- `possible_synonym` cases are `brinjal` (long eggplant), generic `onion`, Byadgi dried red chilli, and whole mature jackfruit; these require confirmation at annotation.
- Mandatory non-equivalence rules were respected: no bell pepper → chilli mappings, no sweet potato → potato, no `Cocos` → coconut preparations, no `Caju seed`/`Chestnut` → jackfruit seed, and no cabbage/zucchini → jackfruit mappings.
- No excluded visual class was used as a target.

### 5.4 Packaging contamination

- All rows record `packaging_visibility = none` and `dataset_role = primary_unlabelled`.
- Fruits-360 source folders are isolated raw ingredients.
- Commons source descriptions show no retail packaging. The bowls in the black pepper and coriander images are serving containers, not product packaging.
- Human visual confirmation of packaging is still required (see limitations).

### 5.5 Source-group relationships

| Source group | Images | Relationship |
|---|---:|---|
| F360:eggplant_long_1 | 2 | Same source class, different object index |
| F360:Ginger 1 | 2 | Same source class, different rotation axis / object index |
| F360:Onion 2 | 2 | Same source class, different rotation axis / object index |
| F360:Onion Red 2 | 2 | Same source class, different rotation axis / object index |
| F360:Tomato 1 | 2 | Same source class, different rotation axis / object index |

Each Commons file has a unique page ID and source group. No exact duplicates were found within any source group. Source-group IDs are recorded so these related images can stay together during future split design.

## 6. Limitations and pending human review

- This model could not inspect image pixels directly because image input is unavailable. A 20-image contact sheet was generated at `C:\Users\USER\AppData\Local\Temp\phase1333_contact_sheet.jpg` and previewed for human review. Class-mapping and packaging-contamination checks above are therefore based on verified source metadata/descriptions and automated similarity screening; visual confirmation remains pending.
- Manifest `qc_status` is `automated_pass_visual_review_pending` for this reason.
- No target image count, performance score, annotation agreement threshold or near-duplicate distance threshold has been set.
- Deferred/excluded pilot classes remain deferred or excluded; absence from this collection is not a catalogue change.
- No train/validation/test split was assigned.

## 7. Preservation and repository state

- All 15 pre-existing research files were compared with the baseline in `C:\Users\USER\AppData\Local\Temp\rasalanka_phase1333_hashes_before.json`; **15 / 15 unchanged**.
- HEAD remains `228ff001e2aba4ecbbad57b85127ae2c3da1f73b`.
- Tracked tree remains clean. Only untracked research files, including the three new Phase 13.3.3 deliverables, are present.
- No commit or push was performed.
