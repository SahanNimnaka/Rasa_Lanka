# Folder Human Recognition Confirmation Form

- Generated: 2026-11-24T11:31:48.037214+00:00
- Source: `research/phase-13.3/annotation_pipeline_step8a/reports/folder_recognition_confirmation_report.csv`
- Total folders: 35
- Total images: 24356

## Instructions / guardrails

- This document is for **human confirmation only**. No folder is pre-marked as inspected and no ingredient labels are assigned.
- **Do not treat folder names or machine-generated mapping proposals as human ground truth.**
- **Recognition method** must be filled only with evidence-confirmed value: `individual`, `group`, `mixed`, or `not inspected`.
- **Human-confirmed ingredient(s)** must be filled only when confirmed by human evidence; use approved taxonomy IDs where possible. Leave blank if unconfirmed.
- **Confirmation status** should be filled explicitly (e.g., `confirmed`, `pending`, `uncertain`, `rejected`, `not inspected`). Leave blank until confirmed.
- Group-level confirmation must **not** be converted into individually verified image labels without image-level evidence.
- Preserve uncertainty and ambiguous cases; do not force a label.

## Folder confirmation table

| Folder name | Proposed ingredient | Image count | Approved taxonomy match | Mapping status | Exact duplicate information | Near-duplicate involvement | Recognition method (individual/group/mixed/not inspected) | Human-confirmed ingredient(s) | Confirmation status | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Black Pepper | (none) [candidates: black_pepper_whole\|black_pepper_ground] | 620 | ambiguous:black_pepper_whole,black_pepper_ground | ambiguous_pending_review | extra_copies=0; groups=0 | images=489 (78.87%); top_cross_folder=Tamarind:171; Maldive Fish:13; Fish:3; Ginger:2; Cardamom:2 |  |  |  |  |
| Cardamom | cardamom_pod | 615 | in_scope:cardamom_pod | proposed_pending_review | extra_copies=0; groups=0 | images=593 (96.42%); top_cross_folder=Tamarind:2112; Small Onion:1383; Cloves:725; Raw Rice:308; Maldive Fish:235 |  |  |  |  |
| Chiken | chicken | 56 | in_scope:chicken | proposed_pending_review | extra_copies=0; groups=0 | images=34 (60.71%); top_cross_folder=Tamarind:601; Raw Rice:33; Maldive Fish:23; Fish:13; Onion:13 |  |  |  |  |
| Chile Flakes | chilli_flake | 25 | in_scope:chilli_flake | proposed_pending_review | extra_copies=0; groups=0 | images=3 (12.0%); top_cross_folder=Tamarind:8; Maldive Fish:6; Black Pepper:2; Onion:1; Pandan Leaf:1 |  |  |  |  |
| Chili Powder | chilli_powder | 931 | in_scope:chilli_powder | proposed_pending_review | extra_copies=3; groups=3 | images=451 (48.44%); top_cross_folder=Tamarind:21; Maldive Fish:8; Tender Jack:7; Fish:7; Small Onion:3 |  |  |  |  |
| Cinnamon | cinnamon_bark | 402 | in_scope:cinnamon_bark | proposed_pending_review | extra_copies=1; groups=1 | images=380 (94.53%); top_cross_folder=Egg Plant:179; Tamarind:159; Maldive Fish:118; Tender Jack:66; Jack:26 |  |  |  |  |
| Cloves | clove | 303 | in_scope:clove | proposed_pending_review | extra_copies=1; groups=1 | images=303 (100.0%); top_cross_folder=Cardamom:725; Small Onion:112; Tamarind:58; Egg Plant:9; Red Chili:5 |  |  |  |  |
| Coconut milk | coconut_milk | 11 | in_scope:coconut_milk | proposed_pending_review | extra_copies=0; groups=0 | images=3 (27.27%); top_cross_folder=Maldive Fish:7; Lime:3; Fish:3; Pandan Leaf:2; Tender Jack:1 |  |  |  |  |
| Coconut oil | (none) | 18 | out_of_scope:coconut_oil | out_of_scope | extra_copies=0; groups=0 | images=11 (61.11%); top_cross_folder=Tamarind:109; Small Onion:80; Curry Leavf:59; Fish:51; Maldive Fish:41 |  |  |  |  |
| Coriander | (none) [candidates: coriander_seed] | 796 | ambiguous:coriander_seed | ambiguous_pending_review | extra_copies=0; groups=0 | images=65 (8.17%); top_cross_folder=Tamarind:177; Small Onion:33; Maldive Fish:22; Red Chili:6; Fish:5 |  |  |  |  |
| Curry Leavf | curry_leaves | 114 | in_scope:curry_leaves | proposed_pending_review | extra_copies=0; groups=0 | images=83 (72.81%); top_cross_folder=Tamarind:1210; Egg Plant:312; Small Onion:273; Fish:101; Jack:88 |  |  |  |  |
| Egg Plant | brinjal | 1017 | in_scope:brinjal | proposed_pending_review | extra_copies=1; groups=1 | images=946 (93.02%); top_cross_folder=Tamarind:3112; Small Onion:2866; Curry Leavf:312; Tomato:245; Cinnamon:179 |  |  |  |  |
| Fish | fish | 3000 | in_scope:fish | proposed_pending_review | extra_copies=0; groups=0 | images=419 (13.97%); top_cross_folder=Tamarind:1000; Raw Rice:281; Maldive Fish:201; Small Onion:193; Sugar:118 |  |  |  |  |
| Ginger | ginger_root | 178 | in_scope:ginger_root | proposed_pending_review | extra_copies=0; groups=0 | images=22 (12.36%); top_cross_folder=Tamarind:193; Maldive Fish:11; Black Pepper:2; Turmeric:2; Raw Rice:1 |  |  |  |  |
| Gralic | garlic_clove | 599 | in_scope:garlic_clove | proposed_pending_review | extra_copies=67; groups=67 | images=196 (32.72%); top_cross_folder=Tamarind:27; Egg Plant:18; Raw Rice:15; Small Onion:12; Red Chili:10 |  |  |  |  |
| Grated coconut | grated_coconut | 22 | in_scope:grated_coconut | proposed_pending_review | extra_copies=0; groups=0 | images=4 (18.18%); top_cross_folder=Tamarind:5; Lime:3; Turmeric:2; Onion:1; Raw Rice:1 |  |  |  |  |
| Green Chili | green_chilli | 49 | in_scope:green_chilli | proposed_pending_review | extra_copies=0; groups=0 | images=7 (14.29%); top_cross_folder=Tamarind:12; Onion:6; Red Chili:4; Fish:3; Curry Leavf:3 |  |  |  |  |
| Jack | (none) [candidates: mature_jackfruit\|young_jackfruit] | 430 | ambiguous:mature_jackfruit,young_jackfruit | ambiguous_pending_review | extra_copies=0; groups=0 | images=90 (20.93%); top_cross_folder=Tamarind:387; Curry Leavf:88; Egg Plant:77; Tender Jack:71; Fish:56 |  |  |  |  |
| Lime | lime | 969 | in_scope:lime | proposed_pending_review | extra_copies=0; groups=0 | images=969 (100.0%); top_cross_folder=Tamarind:630; Small Onion:135; Egg Plant:102; Raw Rice:79; Curry Leavf:60 |  |  |  |  |
| Maldive Fish | (none) | 1413 | unmapped | unmapped | extra_copies=0; groups=0 | images=1108 (78.41%); top_cross_folder=Tamarind:19285; Tender Jack:663; Small Onion:428; Cardamom:235; Fish:201 |  |  |  |  |
| Onion | onion | 149 | in_scope:onion | proposed_pending_review | extra_copies=0; groups=0 | images=75 (50.34%); top_cross_folder=Tamarind:390; Fish:43; Curry Leavf:36; Maldive Fish:33; Small Onion:29 |  |  |  |  |
| Pandan Leaf | pandan_leaves | 240 | in_scope:pandan_leaves | proposed_pending_review | extra_copies=0; groups=0 | images=102 (42.5%); top_cross_folder=Maldive Fish:83; Tamarind:57; Egg Plant:20; Fish:17; Raw Rice:13 |  |  |  |  |
| Paper Corn | (none) [candidates: black_pepper_whole] | 216 | ambiguous:black_pepper_whole | ambiguous_pending_review | extra_copies=0; groups=0 | images=35 (16.2%); top_cross_folder=Tamarind:31; Maldive Fish:13; Egg Plant:11; Fish:8; Curry Leavf:7 |  |  |  |  |
| Potato | potato | 207 | in_scope:potato | proposed_pending_review | extra_copies=0; groups=0 | images=16 (7.73%); top_cross_folder=Small Onion:65; Fish:7; Maldive Fish:7; Lime:4; Tender Jack:3 |  |  |  |  |
| Pumpkin | pumpkin | 244 | in_scope:pumpkin | proposed_pending_review | extra_copies=0; groups=0 | images=24 (9.84%); top_cross_folder=Tamarind:311; Raw Rice:32; Small Onion:27; Maldive Fish:22; Red Chili:16 |  |  |  |  |
| Raw Rice | (none) | 492 | unmapped | unmapped | extra_copies=0; groups=0 | images=346 (70.33%); top_cross_folder=Sugar:930; Red Chili:646; Small Onion:433; Cardamom:308; Fish:281 |  |  |  |  |
| Red Chili | (none) [candidates: dried_red_chilli] | 1178 | ambiguous:dried_red_chilli | ambiguous_pending_review | extra_copies=31; groups=31 | images=1133 (96.18%); top_cross_folder=Small Onion:1332; Tamarind:1049; Raw Rice:646; Egg Plant:146; Cardamom:101 |  |  |  |  |
| Small Onion | small_onion | 1222 | in_scope:small_onion | proposed_pending_review | extra_copies=0; groups=0 | images=1210 (99.02%); top_cross_folder=Tamarind:5360; Egg Plant:2866; Cardamom:1383; Red Chili:1332; Raw Rice:433 |  |  |  |  |
| Sugar | (none) | 599 | out_of_scope:sugar | out_of_scope | extra_copies=0; groups=0 | images=599 (100.0%); top_cross_folder=Raw Rice:930; Cardamom:151; Fish:118; Red Chili:95; Small Onion:77 |  |  |  |  |
| Table Salt | (none) | 77 | out_of_scope:salt | out_of_scope | extra_copies=0; groups=0 | images=49 (63.64%); top_cross_folder=Raw Rice:112; Sugar:56; Egg Plant:32; Small Onion:17; Fish:13 |  |  |  |  |
| Tamarind | (none) [candidates: tamarind_pulp\|tamarind_paste] | 6733 | ambiguous:tamarind_pulp,tamarind_paste | ambiguous_pending_review | extra_copies=561; groups=561 | images=5311 (78.88%); top_cross_folder=Maldive Fish:19285; Small Onion:5360; Egg Plant:3112; Cardamom:2112; Curry Leavf:1210 |  |  |  |  |
| Tender Jack | young_jackfruit | 706 | in_scope:young_jackfruit | proposed_pending_review | extra_copies=0; groups=0 | images=408 (57.79%); top_cross_folder=Maldive Fish:663; Tamarind:223; Red Chili:97; Small Onion:71; Jack:71 |  |  |  |  |
| Tomato | tomato | 337 | in_scope:tomato | proposed_pending_review | extra_copies=0; groups=0 | images=248 (73.59%); top_cross_folder=Tamarind:689; Egg Plant:245; Small Onion:224; Raw Rice:78; Curry Leavf:64 |  |  |  |  |
| Turmeric | (none) [candidates: turmeric_powder] | 365 | ambiguous:turmeric_powder | ambiguous_pending_review | extra_copies=0; groups=0 | images=152 (41.64%); top_cross_folder=Tamarind:502; Curry Leavf:54; Egg Plant:47; Maldive Fish:36; Small Onion:33 |  |  |  |  |
| dhal | (none) [candidates: red_lentils] | 23 | ambiguous:red_lentils | ambiguous_pending_review | extra_copies=0; groups=0 | images=5 (21.74%); top_cross_folder=Tamarind:1 |  |  |  |  |

## Summary requiring confirmation

- All 35 folders are listed with **blank** recognition/confirmation fields.
- `existing_evidence_of_human_inspection` in the source investigation report is `none_found_in_repository` for all folders; this form does not change that.
- Any filled value must be backed by explicit human evidence/provenance before being used in Step 8.