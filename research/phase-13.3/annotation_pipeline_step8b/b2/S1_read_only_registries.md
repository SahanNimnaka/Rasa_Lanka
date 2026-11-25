# B2-S1 Read-only Research Registries - Implementation

Backend-only read-only registry APIs. No CRUD/review/export/frontend/B1 semantic/Step8A/8B changes. Source registries unchanged.

## Endpoints (auth:sanctum + can:annotation.access)
- GET /api/v1/annotation-registries/taxonomy
- GET /api/v1/annotation-registries/label-mapping-decisions
- GET /api/v1/annotation-registries/image-inventory (paginated, filters: image_id, relative_path, original_folder, mapping_status, scan_status; default 25, max 100)
- GET /api/v1/annotation-registries/image-id-lookup (exactly one of image_id or relative_path)
- GET /api/v1/annotation-registries/summary

## Config
Reused B1 paths in backend/config/annotation.php; added registry_taxonomy_path, registry_inventory_path, registry_decisions_path, registry_image_id_registry_path, cache/pagination settings. .env.example has ANNOTATION_REGISTRY_* overrides. No absolute paths hardcoded.

## Deployment
Required read-only artifacts: approved_taxonomy.json, image_id_registry.json, label_mapping_decisions.json, master_image_inventory.csv (canonical; do not use master_image_inventory.json), human_recognition.schema.json for B1. Missing/invalid/symlink/unreadable/inconsistent => 503 fail closed. Metadata only.

## Security
Sanctum + existing `annotation.access` only. GET-only. No user-supplied file paths accepted by registry endpoints. Strict lookup validation; traversal/backslash/absolute forms rejected by request validation where applicable. Registry consistency checked before lookup; no absolute paths/internal traces in responses.

### Path resolution and symlink/junction limitations
- `RegistryPathResolver` normalizes configured paths, rejects `.`/`..` path segments during symlink-segment checks, checks segments with `is_link()` before and after `realpath()`, and requires a readable regular file.
- **Linux/POSIX symlinks**: final-component and symlinked-parent-directory rejection were verified in WSL Ubuntu 26.04.1 with PHP 8.5.4 (`RegistryServicesTest`: 12 passed, 1 skipped, 21 assertions). This is a cross-platform runtime signal, not a PHP 8.3 Linux proof.
- **Windows directory junctions/reparse points (not fixed/established)**: on Windows PHP 8.3.37, a test `mklink /J` junction was observed as `file_exists=true`, `is_link=false`, `is_dir=false`, `filetype=unknown`, `lstat mode=0`, and `realpath()` resolved through the junction target. `fsutil reparsepoint query` reported mount-point reparse tag `0xa0000003`. Therefore PHP `is_link()` does **not** detect the tested Windows directory junction, and Windows junction/reparse-point rejection is **not currently established** by `RegistryPathResolver`.
- **Windows deployment restriction**: registry paths and all parent directories must be controlled by trusted administrators/service identities. Untrusted users or processes must not be able to create junctions, symlinks, mount points, or modify registry files/directories. Do not claim universal Windows reparse-point protection.

### TOCTOU residual risk (not eliminated)
Path validation and subsequent file opening/read are separate operations, leaving a time-of-check/time-of-use race window. Documenting this risk does not eliminate it.

This residual risk is accepted only under these deployment assumptions:
- Registry paths are server-controlled configuration/env; no user-supplied filesystem paths are accepted.
- Untrusted principals cannot modify registry directories, replace files, or create symlinks/junctions/reparse points in registry path segments.
- Filesystem permissions are restricted to trusted administrators/service identities.
- Registry files are deployed as read-only artifacts on trusted storage.

## Tests (latest verified in this pass)
- Windows PHP 8.3.37 `RegistryServicesTest`: **14 passed, 4 skipped, 25 assertions, EXIT 0**. Skipped: `final component symlink rejected` (**NOT VERIFIED** on Windows — symlink creation unavailable/denied), `symlinked parent directory rejected` (**NOT VERIFIED** on Windows — symlink creation unavailable/denied), `nested symlinked parent directory rejected` (**NOT VERIFIED** on Windows — symlink creation unavailable/denied), `unreadable file fails closed` (skipped — file permission denial not reliably enforceable on Windows in this environment).
- WSL Ubuntu 26.04.1 PHP 8.5.4 `RegistryServicesTest`: **15 passed, 3 skipped, 24 assertions, EXIT 0** (unreadable-file test skipped on drvfs chmod; two Windows-only tests skipped by design; symlink tests passed on WSL).
- Windows PHP 8.3.37 `AnnotationRegistryApiTest`: **12 passed, 157 assertions, EXIT 0**.
- Windows PHP 8.3.37 `B1ValidationApiTest`: **26 passed, 81 assertions, EXIT 0**.
- Step 8A Python: **Ran 11, OK, EXIT 0**.
- Step 8B Python: **Ran 46, OK, EXIT 0**.
- Full backend suite: **not run in this pass**; do not claim full suite green.

## Limitations
- **Windows junction/reparse-point protection is not established**: PHP `is_link()` does not detect the tested Windows directory junction/reparse-point behavior; do not claim universal Windows reparse-point protection.
- **TOCTOU risk remains**: path validation and later file read/open are separate operations; residual time-of-check/time-of-use risk is documented but not eliminated.
- **UNC/path containment behavior requires further hardening**: absolute paths outside `b1_workdir` are currently allowed by trusted server configuration, and UNC `..` normalization/containment semantics require explicit policy and hardening before Windows/UNC production use.
- **Linux-first/trusted-filesystem deployment assumptions apply**: S1 is suitable only under trusted server-controlled config, restricted filesystem permissions, and admin-controlled registry path segments; Windows deployments require the documented restrictions.
- In-memory CSV cache/filtering acceptable for 24k rows; summary is metadata/health only; unrelated full-suite failures need separate triage.
