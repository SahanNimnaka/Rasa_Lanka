# Step 8B — B0 Annotation Lifecycle Diagram

- **Status:** B0 design deliverable
- **Date:** 2026-11-24

## 1. End-to-end lifecycle

```mermaid
flowchart TD
    A[Image Selection\nfrom Step 6 master inventory] --> B{Scope}
    B -->|single image evidence| C[Create Individual Record\nv1-compatible template]
    B -->|folder/group evidence only| D[Create Group Record\nv1-compatible template]

    C --> E[Human Review / Annotation]
    D --> E

    E --> F[Submit Record]
    F --> G[Validation]
    G -->|Blocking errors| H[Validation Failed\nfix record]
    H --> E

    G -->|Warnings only / pass| I{Conflict or ambiguity?}
    I -->|yes| J[Adjudication]
    J -->|rework| E
    J -->|resolved with evidence| K[Approval Decision]

    I -->|no| K
    K -->|rejected with evidence| R[Rejected\nretained for audit]
    K -->|approved| L{Canonical Export Gate}

    L -->|group-scope only| X1[Export Blocked\nX-GROUP-SCOPE]
    L -->|pending canonical mapping\nGarlic 599 / Pepper Corn 216| X2[Export Blocked\nX-PENDING-CANONICAL]
    L -->|uncertain/unresolved/conflicted/invalid| X3[Export Blocked\nX-UNRESOLVED/INVALID/CONFLICT]
    L -->|confirmed individual + all gates pass| M[Canonical Manifest Export]

    M --> N[Coverage/Audit Reports]
    X1 --> N
    X2 --> N
    X3 --> N
    R --> N
```

## 2. Record state model

```mermaid
stateDiagram-v2
    [*] --> selected
    selected --> drafted
    drafted --> submitted
    submitted --> validation_failed
    validation_failed --> drafted
    submitted --> validated
    validated --> needs_adjudication
    needs_adjudication --> drafted
    needs_adjudication --> approved
    validated --> approved
    validated --> rejected
    approved --> export_blocked
    approved --> export_eligible
    export_eligible --> exported
    exported --> superseded: corrected by new record
    export_blocked --> superseded: resolved by new record
    rejected --> [*]
    superseded --> [*]
```

## 3. Group vs individual semantics

```mermaid
flowchart LR
    G[Confirmed Group Record] --> GS[Group-scope evidence only]
    GS --> GR[Group audit/report output]
    GS -. never automatic .-> IG[Individual image ground truth]

    I[Confirmed Individual Record] --> IS[Image-scope evidence]
    IS --> CG{Canonical gates pass?}
    CG -->|yes| CE[Canonical eligible]
    CG -->|no| CB[Blocked/excluded]
```

## 4. Pending canonical mapping gate

```mermaid
flowchart TD
    A[Label/record under review] --> B{Decision register match?}
    B -->|approved_alias: Curry Leaf -> curry_leaves| C[Resolve to canonical ID\npreserve display label]
    B -->|pending_canonical_mapping: Garlic/Gralic 599| D[Preserve pending\nno canonical approval]
    B -->|pending_canonical_mapping: Pepper Corn/Paper Corn 216| E[Preserve pending\nno canonical approval]
    B -->|no pending/alias rule; canonical taxonomy valid| F[Normal canonical validation]

    C --> G{Other gates pass?}
    F --> G
    D --> H[Export blocked / excluded]
    E --> H
    G -->|yes| I[Canonical eligible]
    G -->|no| H
```

## 5. Lifecycle gate summary

| Gate | Pass condition | Fail effect |
|---|---|---|
| Schema/inventory/taxonomy | Valid v1-compatible record, known inventory IDs, valid taxonomy IDs | Validation failed |
| Provenance | Confirmed records have human ground truth and evidence | Validation failed / export blocked |
| Conflict | No unresolved active conflicts for same image scope | Needs adjudication / export blocked |
| Pending mapping | No pending canonical mapping in canonical approval path | Export blocked, reported as pending |
| Scope | Individual image evidence exists for individual ground truth | Group records excluded from individual export |
| Final export | All gates pass | Canonical manifest row emitted |