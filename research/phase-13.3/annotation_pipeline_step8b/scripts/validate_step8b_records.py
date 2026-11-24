"""Step 8B B1 - Decision-aware human recognition validator.

Validates Step 8A-compatible human-recognition-v1 records with B0 decision gates:
- schema + inventory linkage
- taxonomy placement (in-scope vs out-of-scope)
- decision register alias resolution / pending canonical blocking
- duplicate record IDs and conflicting active individual records
- group image/count/folder consistency
- provenance/confirmation requirements
- structured JSON/CSV validation reports

Read-only: does not modify source records, master CSV, taxonomy, mappings, or images.
"""
from __future__ import annotations

import argparse
import csv
import json
from collections import Counter, defaultdict
from dataclasses import dataclass, asdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from jsonschema import Draft202012Validator

ACTIVE_CONFLICT_STATUSES = {"confirmed", "pending_confirmation", "uncertain"}


def norm(value: Any) -> str:
    return " ".join(str(value or "").strip().lower().replace("\\", "/").split())


def norm_path(value: Any) -> str:
    return str(value or "").strip().replace("\\", "/")


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def load_inventory(csv_path: Path) -> tuple[list[dict[str, str]], dict[str, dict[str, str]], dict[str, dict[str, str]]]:
    rows = list(csv.DictReader(csv_path.open(encoding="utf-8-sig", newline="")))
    by_id = {r.get("image_id", ""): r for r in rows if r.get("image_id")}
    by_path = {norm_path(r.get("relative_path", "")): r for r in rows if r.get("relative_path")}
    return rows, by_id, by_path


def load_decisions(path: Path) -> dict[str, Any]:
    obj = load_json(path)
    decisions = obj.get("decisions", [])
    by_observed = {}
    by_folder = {}
    for d in decisions:
        observed = str(d.get("observed_label") or "")
        folder = str(d.get("folder_name") or "")
        if observed:
            by_observed[norm(observed)] = d
        if folder:
            by_folder[norm(folder)] = d
    return {"raw": obj, "decisions": decisions, "by_observed": by_observed, "by_folder": by_folder}


@dataclass
class Issue:
    severity: str  # error|warning|export_block
    code: str
    record_id: str
    source: str
    image_id: str
    field: str
    message: str
    details: dict[str, Any]

    def to_row(self) -> dict[str, Any]:
        row = asdict(self)
        row["details"] = json.dumps(self.details, ensure_ascii=False, sort_keys=True)
        return row


def iter_records(path: Path):
    """Yield (source, record). Malformed files are yielded as __load_error__ records so batch continues."""
    def _load_error(src: Path, err: Exception):
        return {"__load_error__": True, "record_id": "", "error": str(err), "error_type": type(err).__name__}
    if path.is_dir():
        for f in sorted(path.rglob("*.json")):
            if f.name.endswith(".template.json"):
                continue
            try:
                yield f, load_json(f)
            except Exception as e:
                yield f, _load_error(f, e)
    else:
        try:
            obj = load_json(path)
        except Exception as e:
            yield path, _load_error(path, e)
            return
        if isinstance(obj, list):
            for i, rec in enumerate(obj):
                yield path.with_name(f"{path.name}#{i}"), rec
        else:
            yield path, obj


def make_issue(severity: str, code: str, rec: dict[str, Any] | None, source: Path | str, field: str, message: str, **details: Any) -> Issue:
    rec = rec or {}
    return Issue(
        severity=severity,
        code=code,
        record_id=str(rec.get("record_id") or ""),
        source=str(source),
        image_id=str(((rec.get("individual") or {}).get("image_id")) or ""),
        field=field,
        message=message,
        details=details,
    )


def label_decision(label: str, record_folder: str | None, decisions: dict[str, Any]) -> dict[str, Any] | None:
    by_observed = decisions["by_observed"]
    d = by_observed.get(norm(label))
    if d:
        return d
    # folder-context fallback: if record folder has a pending decision and label is candidate canonical id
    if record_folder:
        fd = decisions["by_folder"].get(norm(record_folder))
        if fd and fd.get("decision_type") == "pending_canonical_mapping":
            candidates = {norm(c) for c in fd.get("candidate_canonical_class_ids", [])}
            if norm(label) in candidates:
                return fd
    return None


def resolve_labels(labels: list[Any], record_folder: str | None, decisions: dict[str, Any]) -> tuple[list[str], list[dict[str, Any]], list[dict[str, Any]]]:
    resolved: list[str] = []
    alias_resolutions: list[dict[str, Any]] = []
    pending_hits: list[dict[str, Any]] = []
    for raw in labels or []:
        label = str(raw)
        d = label_decision(label, record_folder, decisions)
        if d and d.get("decision_type") == "approved_alias" and d.get("alias_approved"):
            canonical = str(d.get("canonical_class_id"))
            resolved.append(canonical)
            alias_resolutions.append({
                "display_label": label,
                "canonical_class_id": canonical,
                "decision_type": d.get("decision_type"),
                "decision_register_ref": f"{d.get('folder_name')}::{d.get('observed_label')}",
                "preserve_original_label": True,
            })
        else:
            resolved.append(label)
            if d and d.get("decision_type") == "pending_canonical_mapping":
                pending_hits.append({
                    "label": label,
                    "canonical_class_id": d.get("canonical_class_id"),
                    "candidate_canonical_class_ids": d.get("candidate_canonical_class_ids", []),
                    "decision_register_ref": f"{d.get('folder_name')}::{d.get('observed_label')}",
                    "affected_records": d.get("affected_records"),
                })
    # de-dupe while preserving order
    seen = set()
    deduped = []
    for l in resolved:
        if l not in seen:
            deduped.append(l)
            seen.add(l)
    return deduped, alias_resolutions, pending_hits


def validate_record(
    src: Path,
    rec: Any,
    validator: Draft202012Validator,
    by_id: dict[str, dict[str, str]],
    by_path: dict[str, dict[str, str]],
    in_scope: set[str],
    out_scope: set[str],
    decisions: dict[str, Any],
) -> tuple[list[Issue], dict[str, Any]]:
    issues: list[Issue] = []
    result: dict[str, Any] = {
        "source": str(src),
        "record_id": rec.get("record_id") if isinstance(rec, dict) else "",
        "recognition_method": rec.get("recognition_method") if isinstance(rec, dict) else "",
        "verification_status": rec.get("verification_status") if isinstance(rec, dict) else "",
        "valid": False,
        "export_eligible_canonical": False,
        "export_block_codes": [],
        "alias_resolutions": [],
        "pending_mapping_hits": [],
        "resolved_ingredient_labels": [],
        "b1_compat_fields_ignored_for_schema": [],
    }
    if not isinstance(rec, dict):
        issues.append(make_issue("error", "E-SCHEMA", None, src, "", "Record must be a JSON object"))
        result["export_block_codes"] = ["X-INVALID"]
        return issues, result
    if rec.get("__load_error__"):
        issues.append(make_issue("error", "E-LOAD", {"record_id": ""}, src, "", f"Failed to load/parse JSON record: {rec.get('error')}", error_type=rec.get("error_type")))
        result["export_block_codes"] = ["X-INVALID"]
        return issues, result

    # B1 compatibility: allow known lifecycle metadata fields not present in v1 schema.
    # These are validated semantically (supersession/export blocking) but not schema-failed.
    schema_rec = dict(rec)
    b1_compat_fields = []
    compat_specs = {
        "supersedes_record_id": {"types": (str,), "allow_null": False},
    }
    for k, spec in compat_specs.items():
        if k in schema_rec:
            val = schema_rec.pop(k, None)
            b1_compat_fields.append(k)
            if val is None and not spec["allow_null"]:
                issues.append(make_issue("error", "E-SCHEMA", rec, src, k, f"{k} must not be null when present"))
            elif val is not None and not isinstance(val, spec["types"]):
                issues.append(make_issue("error", "E-SCHEMA", rec, src, k, f"{k} must be of type {', '.join(t.__name__ for t in spec['types'])}"))
            elif isinstance(val, str) and not val.strip():
                issues.append(make_issue("error", "E-SCHEMA", rec, src, k, f"{k} must be a non-empty string when present"))
    for err in sorted(validator.iter_errors(schema_rec), key=str):
        loc = "/".join(map(str, err.path))
        issues.append(make_issue("error", "E-SCHEMA", rec, src, loc, err.message))
    if b1_compat_fields:
        result["b1_compat_fields_ignored_for_schema"] = b1_compat_fields

    method = rec.get("recognition_method")
    status = rec.get("verification_status")
    prov = rec.get("provenance") if isinstance(rec.get("provenance"), dict) else {}
    record_folder = None
    if method == "individual" and isinstance(rec.get("individual"), dict):
        record_folder = rec["individual"].get("original_folder")
    elif method == "group" and isinstance(rec.get("group"), dict):
        record_folder = rec["group"].get("original_folder")

    # Provenance / confirmation
    if status == "confirmed":
        if prov.get("is_human_ground_truth") is not True:
            issues.append(make_issue("error", "E-PROVENANCE", rec, src, "provenance.is_human_ground_truth", "Confirmed record requires provenance.is_human_ground_truth=true"))
        if not str(prov.get("evidence_summary") or "").strip():
            issues.append(make_issue("error", "E-PROVENANCE", rec, src, "provenance.evidence_summary", "Confirmed record requires non-empty provenance.evidence_summary"))
        if not str(prov.get("source_reference") or "").strip():
            issues.append(make_issue("error", "E-PROVENANCE", rec, src, "provenance.source_reference", "Confirmed record requires non-empty provenance.source_reference"))
        if not rec.get("recognition_date") and str(prov.get("source_type") or "").startswith("historical_"):
            issues.append(make_issue("warning", "W-MISSING-DATE", rec, src, "recognition_date", "Confirmed historical record has no recognition_date; preserve unknown explicitly"))

    # Label placement + decision register
    label_arrays = {
        "ingredient_labels": in_scope,
        "uncertain_labels": in_scope | out_scope,
        "out_of_scope_labels": out_scope,
    }
    all_alias_resolutions = []
    all_pending_hits = []
    for arr_name, allowed_set in label_arrays.items():
        labels = rec.get(arr_name, []) or []
        if not isinstance(labels, list):
            continue
        resolved, alias_resolutions, pending_hits = resolve_labels(labels, record_folder, decisions)
        if arr_name == "ingredient_labels":
            result["resolved_ingredient_labels"] = resolved
        all_alias_resolutions.extend([{**a, "field": arr_name} for a in alias_resolutions])
        all_pending_hits.extend([{**p, "field": arr_name} for p in pending_hits])

        for idx, (raw, resolved_label) in enumerate(zip(labels, resolved)):
            raw_s = str(raw)
            resolved_s = str(resolved_label)
            field = f"{arr_name}[{idx}]"
            decision = label_decision(raw_s, record_folder, decisions)
            resolved_decision = label_decision(resolved_s, record_folder, decisions)
            effective_decision = decision or resolved_decision

            if effective_decision and effective_decision.get("decision_type") == "pending_canonical_mapping":
                is_candidate = norm(resolved_s) in {norm(c) for c in effective_decision.get("candidate_canonical_class_ids", [])}
                is_observed = norm(raw_s) == norm(effective_decision.get("observed_label"))
                issues.append(make_issue("warning", "W-PENDING-MAPPING", rec, src, field, "Label touches pending canonical mapping; canonical export blocked", label=raw_s, resolved=resolved_s, decision=effective_decision.get("folder_name")))
                issues.append(make_issue("export_block", "X-PENDING-CANONICAL", rec, src, field, "Pending canonical mapping is not eligible for canonical export", label=raw_s, resolved=resolved_s))
                # ingredient_labels: pending observed/candidate labels cannot be canonically approved.
                if arr_name == "ingredient_labels":
                    if is_candidate or is_observed or effective_decision.get("canonical_class_id") is None:
                        issues.append(make_issue("error", "E-PENDING-AS-CANONICAL", rec, src, field, "Pending canonical mapping cannot be used for canonical approval without explicit resolution", label=raw_s, candidate=resolved_s, folder=effective_decision.get("folder_name")))
                # out_of_scope_labels: do not allow pending mapping to silently bypass review as out-of-scope classification.
                if arr_name == "out_of_scope_labels" and (is_candidate or is_observed):
                    issues.append(make_issue("error", "E-PENDING-AS-OUT-OF-SCOPE", rec, src, field, "Pending canonical mapping cannot be classified as out-of-scope without explicit resolution", label=raw_s, resolved=resolved_s, folder=effective_decision.get("folder_name")))
                # uncertain_labels: allowed as uncertainty reporting, but never canonical export; no hard invalidation.
                # pending observed display label may not be in taxonomy; skip taxonomy placement error
                if norm(raw_s) == norm(effective_decision.get("observed_label")) and effective_decision.get("canonical_class_id") is None:
                    continue

            if resolved_s not in (in_scope | out_scope):
                issues.append(make_issue("error", "E-TAXONOMY", rec, src, field, f"Label {resolved_s!r} is not in approved taxonomy", raw_label=raw_s))
                continue

            if arr_name == "ingredient_labels" and resolved_s not in in_scope:
                issues.append(make_issue("error", "E-TAXONOMY-PLACEMENT", rec, src, field, f"Out-of-scope label {resolved_s!r} cannot be in ingredient_labels", raw_label=raw_s))
            if arr_name == "out_of_scope_labels" and resolved_s not in out_scope:
                issues.append(make_issue("error", "E-TAXONOMY-PLACEMENT", rec, src, field, f"In-scope label {resolved_s!r} cannot be in out_of_scope_labels", raw_label=raw_s))

    result["alias_resolutions"] = all_alias_resolutions
    result["pending_mapping_hits"] = all_pending_hits

    # Linkage and method-specific checks
    if method == "individual":
        ind = rec.get("individual") if isinstance(rec.get("individual"), dict) else {}
        iid = str(ind.get("image_id") or "")
        rel = norm_path(ind.get("relative_path") or "")
        folder = str(ind.get("original_folder") or "")
        fname = str(ind.get("original_filename") or "")
        inv = by_id.get(iid)
        inv_by_path = by_path.get(rel)
        if not inv:
            issues.append(make_issue("error", "E-LINK", rec, src, "individual.image_id", f"Unknown image_id {iid}", image_id=iid))
        if rel and not inv_by_path:
            issues.append(make_issue("error", "E-LINK", rec, src, "individual.relative_path", f"Unknown relative_path {rel}", relative_path=rel))
        if inv:
            if rel and norm_path(inv.get("relative_path")) != rel:
                issues.append(make_issue("error", "E-LINK", rec, src, "individual.relative_path", "image_id/path mismatch", image_id=iid, expected=inv.get("relative_path"), actual=rel))
            if folder and str(inv.get("original_folder")) != folder:
                issues.append(make_issue("error", "E-LINK", rec, src, "individual.original_folder", "image_id/folder mismatch", image_id=iid, expected=inv.get("original_folder"), actual=folder))
            if fname and str(inv.get("original_filename")) != fname:
                issues.append(make_issue("error", "E-LINK", rec, src, "individual.original_filename", "image_id/filename mismatch", image_id=iid, expected=inv.get("original_filename"), actual=fname))
        if inv and inv_by_path and inv.get("image_id") != inv_by_path.get("image_id"):
            issues.append(make_issue("error", "E-LINK", rec, src, "individual", "image_id and relative_path resolve to different inventory rows", image_id=iid, relative_path=rel))

    elif method == "group":
        grp = rec.get("group") if isinstance(rec.get("group"), dict) else {}
        ids = grp.get("image_ids") or []
        image_count = grp.get("image_count")
        folder = str(grp.get("original_folder") or "")
        if isinstance(ids, list):
            if image_count != len(ids):
                issues.append(make_issue("error", "E-GROUP-COUNT", rec, src, "group.image_count", "group.image_count must equal len(group.image_ids)", image_count=image_count, actual=len(ids)))
            seen = set()
            for i, raw_iid in enumerate(ids):
                iid = str(raw_iid)
                if iid in seen:
                    issues.append(make_issue("error", "E-GROUP-COUNT", rec, src, f"group.image_ids[{i}]", "Duplicate image_id in group.image_ids", image_id=iid))
                seen.add(iid)
                inv = by_id.get(iid)
                if not inv:
                    issues.append(make_issue("error", "E-LINK", rec, src, f"group.image_ids[{i}]", f"Unknown group image_id {iid}", image_id=iid))
                elif folder and str(inv.get("original_folder")) != folder:
                    issues.append(make_issue("warning", "W-GROUP-FOLDER-MISMATCH", rec, src, f"group.image_ids[{i}]", "Group image belongs to a different inventory folder than group.original_folder", image_id=iid, expected_folder=folder, actual_folder=inv.get("original_folder")))
        issues.append(make_issue("export_block", "X-GROUP-SCOPE", rec, src, "recognition_method", "Group record is not eligible for individual image ground-truth export"))
        if status == "confirmed":
            issues.append(make_issue("warning", "W-GROUP-CONFIRMED", rec, src, "recognition_method", "Confirmed group record must not be converted to individually verified labels without image-level evidence"))
    else:
        if method is not None:
            issues.append(make_issue("error", "E-SCHEMA", rec, src, "recognition_method", f"Unsupported recognition_method {method!r}"))

    # Generic export blocks
    if status != "confirmed":
        issues.append(make_issue("export_block", "X-NOT-CONFIRMED", rec, src, "verification_status", f"Status {status!r} is not canonical-export eligible"))
    if status == "confirmed" and (prov.get("is_human_ground_truth") is not True or not str(prov.get("evidence_summary") or "").strip() or not str(prov.get("source_reference") or "").strip()):
        issues.append(make_issue("export_block", "X-PROVENANCE-INCOMPLETE", rec, src, "provenance", "Confirmed record provenance is incomplete"))
    if rec.get("uncertain_labels"):
        issues.append(make_issue("export_block", "X-UNRESOLVED-UNCERTAIN", rec, src, "uncertain_labels", "Uncertain labels unresolved for canonical positive export"))

    # Compute final flags
    has_errors = any(i.severity == "error" for i in issues)
    export_block_codes = sorted({i.code for i in issues if i.severity == "export_block" or (i.severity == "error" and i.code.startswith("E-"))})
    if has_errors:
        export_block_codes = sorted(set(export_block_codes) | {"X-INVALID"})
    result["valid"] = not has_errors
    result["export_block_codes"] = export_block_codes
    result["export_eligible_canonical"] = (not has_errors) and len(export_block_codes) == 0 and status == "confirmed" and method == "individual"
    return issues, result


def _record_image_ids(rec: dict[str, Any]) -> set[str]:
    method = rec.get("recognition_method")
    if method == "individual":
        ind = rec.get("individual") if isinstance(rec.get("individual"), dict) else {}
        iid = str(ind.get("image_id") or "").strip()
        return {iid} if iid else set()
    if method == "group":
        grp = rec.get("group") if isinstance(rec.get("group"), dict) else {}
        ids = grp.get("image_ids") if isinstance(grp.get("image_ids"), list) else []
        return {str(i).strip() for i in ids if str(i).strip()}
    return set()


def _extract_supersedes(rec: dict[str, Any]) -> str | None:
    sid = rec.get("supersedes_record_id")
    if sid is None and isinstance(rec.get("step8b"), dict):
        sid = rec.get("step8b", {}).get("supersedes_record_id")
    if sid is None:
        return None
    if not isinstance(sid, str):
        return "__INVALID_NON_STRING__"
    sid = sid.strip()
    return sid or None


def detect_cross_record_issues(records: list[tuple[Path, Any]], per_record_issues: list[list[Issue]]) -> list[Issue]:
    issues: list[Issue] = []
    by_record_id: dict[str, list[tuple[Path, Any]]] = defaultdict(list)
    for src, rec in records:
        if isinstance(rec, dict):
            rid = str(rec.get("record_id") or "")
            if rid:
                by_record_id[rid].append((src, rec))
    for rid, items in by_record_id.items():
        if len(items) > 1:
            for src, rec in items:
                issues.append(make_issue("error", "E-DUPLICATE-ID", rec, src, "record_id", f"Duplicate record_id {rid}", duplicate_count=len(items), other_sources=[str(s) for s, _ in items]))

    # Supersession validation
    valid_record_ids = {rid for rid, items in by_record_id.items() if len(items) == 1}
    rec_by_id: dict[str, tuple[Path, dict[str, Any]]] = {rid: items[0] for rid, items in by_record_id.items() if len(items) == 1}
    superseded_by: dict[str, list[str]] = defaultdict(list)
    supersedes_map: dict[str, str] = {}

    for src, rec in records:
        if not isinstance(rec, dict) or rec.get("__load_error__"):
            continue
        rid = str(rec.get("record_id") or "").strip()
        sid = _extract_supersedes(rec)
        if sid is None:
            continue
        field = "supersedes_record_id" if "supersedes_record_id" in rec else "step8b.supersedes_record_id"
        if sid == "__INVALID_NON_STRING__":
            issues.append(make_issue("error", "E-SUPERSESSION", rec, src, field, "supersedes_record_id must be a string record ID", value=rec.get("supersedes_record_id")))
            continue
        if not rid:
            issues.append(make_issue("error", "E-SUPERSESSION", rec, src, field, "Record with supersedes_record_id must have a valid record_id", supersedes_record_id=sid))
            continue
        if sid == rid:
            issues.append(make_issue("error", "E-SUPERSESSION", rec, src, field, "Record cannot supersede itself", supersedes_record_id=sid))
            continue
        if sid not in valid_record_ids:
            issues.append(make_issue("error", "E-SUPERSESSION", rec, src, field, f"supersedes_record_id {sid!r} does not reference an existing unique record", supersedes_record_id=sid))
            continue
        supersedes_map[rid] = sid
        superseded_by[sid].append(rid)

        target = rec_by_id[sid][1]
        rec_imgs = _record_image_ids(rec)
        target_imgs = _record_image_ids(target)
        rec_method = rec.get("recognition_method")
        target_method = target.get("recognition_method")
        # Conservative B1 scope rule: supersession must not partially overlap image scope.
        # - individual->individual: same image_id
        # - group->group: identical image set
        # - cross method (individual<->group): only allowed when identical single-image scope.
        # - empty/malformed scope: rejected (cannot prove safe supersession).
        scope_ok = False
        if not rec_imgs or not target_imgs:
            issues.append(make_issue(
                "error", "E-SUPERSESSION", rec, src, field,
                "supersedes_record_id cannot be validated because record or target image scope is empty/malformed",
                supersedes_record_id=sid,
                record_method=rec_method,
                target_method=target_method,
                record_images=sorted(rec_imgs),
                target_images=sorted(target_imgs),
            ))
        else:
            if rec_method == "individual" and target_method == "individual":
                scope_ok = rec_imgs == target_imgs
            elif rec_method == "group" and target_method == "group":
                scope_ok = rec_imgs == target_imgs
            elif rec_method == "individual" and target_method == "group":
                scope_ok = (len(rec_imgs) == 1 and rec_imgs == target_imgs)
            elif rec_method == "group" and target_method == "individual":
                scope_ok = (len(target_imgs) == 1 and rec_imgs == target_imgs)
            else:
                scope_ok = rec_imgs == target_imgs
            if not scope_ok:
                issues.append(make_issue(
                    "error", "E-SUPERSESSION", rec, src, field,
                    "supersedes_record_id must reference a record with compatible image scope; partial overlap/cross-scope supersession is not allowed in B1",
                    supersedes_record_id=sid,
                    record_method=rec_method,
                    target_method=target_method,
                    record_images=sorted(rec_imgs),
                    target_images=sorted(target_imgs),
                ))

    for sid, superseders in superseded_by.items():
        if len(superseders) > 1:
            for rid in superseders:
                src, rec = rec_by_id[rid]
                issues.append(make_issue("error", "E-SUPERSESSION", rec, src, "supersedes_record_id", f"Multiple records supersede the same record {sid}", superseded_record_id=sid, superseding_record_ids=sorted(superseders)))

    # Circular supersession detection
    visiting: set[str] = set()
    visited: set[str] = set()
    stack: list[str] = []

    def dfs(rid: str):
        if rid in visited:
            return
        if rid in visiting:
            if rid in stack:
                cycle = stack[stack.index(rid):] + [rid]
                for node in cycle[:-1]:
                    src, rec = rec_by_id.get(node, (Path("<unknown>"), {"record_id": node}))
                    issues.append(make_issue("error", "E-SUPERSESSION", rec, src, "supersedes_record_id", "Circular supersession chain detected", cycle=cycle))
            return
        visiting.add(rid)
        stack.append(rid)
        nxt = supersedes_map.get(rid)
        if nxt and nxt in supersedes_map:
            dfs(nxt)
        elif nxt and nxt in rec_by_id:
            # walk into target even if target has no outgoing edge; needed for 2-node cycles
            dfs(nxt)
        stack.pop()
        visiting.remove(rid)
        visited.add(rid)

    for rid in list(supersedes_map.keys()):
        dfs(rid)

    # Any record in an invalid supersession relation must not be treated as active canonical ground truth.
    invalid_supersession_ids: set[str] = set()
    for i in issues:
        if i.code == "E-SUPERSESSION" and i.record_id:
            invalid_supersession_ids.add(i.record_id)
            # Do not block the target merely because another record made an invalid supersession attempt.
            # The referring record gets E-SUPERSESSION + X-INVALID-SUPERSESSION; target remains governed
            # by its own validity/conflicts/supersession state.
            cycle = i.details.get("cycle") if isinstance(i.details, dict) else None
            if isinstance(cycle, list):
                for c in cycle:
                    if isinstance(c, str) and c:
                        invalid_supersession_ids.add(c)
    for rid in invalid_supersession_ids:
        if rid in rec_by_id:
            src, rec = rec_by_id[rid]
            issues.append(make_issue("export_block", "X-INVALID-SUPERSESSION", rec, src, "supersedes_record_id", "Record participates in invalid supersession relation; canonical export blocked", record_id=rid))

    # conflicting active individual records by image_id
    by_image: dict[str, list[tuple[Path, dict[str, Any]]]] = defaultdict(list)
    superseded_ids: set[str] = set(supersedes_map.values())
    for src, rec in records:
        if not isinstance(rec, dict) or rec.get("__load_error__"):
            continue
        if rec.get("recognition_method") != "individual":
            continue
        status = rec.get("verification_status")
        rid = str(rec.get("record_id") or "")
        if status == "rejected" or status == "not_inspected":
            continue
        # Superseded records are excluded from active conflict detection, but remain export-blocked
        # to prevent accidental canonical approval of stale records.
        if rid in superseded_ids or rid in invalid_supersession_ids:
            continue
        if status not in ACTIVE_CONFLICT_STATUSES:
            continue
        iid = str(((rec.get("individual") or {}) if isinstance(rec.get("individual"), dict) else {}).get("image_id") or "")
        if iid:
            by_image[iid].append((src, rec))
    for iid, items in by_image.items():
        if len(items) < 2:
            continue
        # conflict if labels/status materially differ
        signatures = []
        for _, rec in items:
            sig = json.dumps({
                "status": rec.get("verification_status"),
                "ingredient_labels": sorted(rec.get("ingredient_labels") or []),
                "uncertain_labels": sorted(rec.get("uncertain_labels") or []),
                "out_of_scope_labels": sorted(rec.get("out_of_scope_labels") or []),
            }, sort_keys=True)
            signatures.append(sig)
        if len(set(signatures)) == 1:
            for src, rec in items:
                issues.append(make_issue("error", "E-DUPLICATE-CONTENT", rec, src, "individual.image_id", f"Duplicate active individual records with identical content for {iid}; adjudication/dedup required", image_id=iid, record_ids=[r.get("record_id") for _, r in items]))
                issues.append(make_issue("export_block", "X-CONFLICT", rec, src, "individual.image_id", f"Duplicate active individual records block canonical export for {iid}", image_id=iid))
            continue
        for src, rec in items:
            issues.append(make_issue("error", "E-CONFLICT", rec, src, "individual.image_id", f"Conflicting active individual records for image {iid}", image_id=iid, record_ids=[r.get("record_id") for _, r in items]))
            issues.append(make_issue("export_block", "X-CONFLICT", rec, src, "individual.image_id", f"Conflicting active individual records block canonical export for {iid}", image_id=iid))
    return issues


def _display_path(value: str | Path, base: Path | None = None) -> str:
    p = Path(str(value))
    base = base or Path.cwd()
    try:
        rel = p.resolve().relative_to(base.resolve())
        return rel.as_posix()
    except Exception:
        # avoid leaking temp absolute paths; keep stable basename/id suffix
        name = p.name
        return name or str(p)


def build_reports(results: list[dict[str, Any]], issues: list[Issue], out_dir: Path, input_path: str) -> dict[str, str]:
    out_dir.mkdir(parents=True, exist_ok=True)
    json_path = out_dir / "step8b_validation_report.json"
    csv_path = out_dir / "step8b_validation_issues.csv"
    summary_path = out_dir / "step8b_validation_summary.md"

    base = Path.cwd()
    display_input = _display_path(input_path, base)
    # normalize paths in results/issues for report stability and privacy
    norm_results: list[dict[str, Any]] = []
    for r in results:
        rr = dict(r)
        rr["source"] = _display_path(str(rr.get("source", "")), base)
        norm_results.append(rr)
    norm_issues: list[Issue] = []
    for i in issues:
        norm_issues.append(Issue(
            severity=i.severity, code=i.code, record_id=i.record_id,
            source=_display_path(i.source, base), image_id=i.image_id,
            field=i.field, message=i.message, details=i.details,
        ))

    # Deterministic ordering for reports (independent of input order/temp paths)
    severity_rank = {"error": 0, "warning": 1, "export_block": 2}
    norm_issues = sorted(norm_issues, key=lambda i: (severity_rank.get(i.severity, 9), i.code, i.record_id, i.image_id, i.field, i.message, i.source))
    norm_results = sorted(norm_results, key=lambda r: (str(r.get("record_id", "")), str(r.get("recognition_method", "")), str(r.get("verification_status", "")), str(r.get("source", ""))))
    issue_rows = [i.to_row() for i in norm_issues]
    summary = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "input": display_input,
        "source_file_count": len({str(r.get("source","")).split("#")[0] for r in norm_results}),
        "records_checked": len(norm_results),
        "valid_records": sum(1 for r in norm_results if r["valid"]),
        "invalid_records": sum(1 for r in norm_results if not r["valid"]),
        "export_eligible_canonical_records": sum(1 for r in norm_results if r["export_eligible_canonical"]),
        "error_count": sum(1 for i in norm_issues if i.severity == "error"),
        "warning_count": sum(1 for i in norm_issues if i.severity == "warning"),
        "export_block_count": sum(1 for i in norm_issues if i.severity == "export_block"),
        "issues_by_code": dict(sorted(Counter(i.code for i in norm_issues).items())),
    }
    report = {"summary": summary, "results": norm_results, "issues": issue_rows}
    json_path.write_text(json.dumps(report, indent=2, ensure_ascii=False, sort_keys=False), encoding="utf-8")

    with csv_path.open("w", encoding="utf-8", newline="") as f:
        fieldnames = ["severity", "code", "record_id", "source", "image_id", "field", "message", "details"]
        w = csv.DictWriter(f, fieldnames=fieldnames)
        w.writeheader()
        for row in issue_rows:
            w.writerow(row)

    with summary_path.open("w", encoding="utf-8") as f:
        f.write("# Step 8B Validation Summary\n\n")
        for k in ["input","records_checked","valid_records","invalid_records","export_eligible_canonical_records","error_count","warning_count","export_block_count","generated_at"]:
            f.write(f"- **{k}**: {summary.get(k)}\n")
        f.write("\n## Issue codes\n\n")
        if summary["issues_by_code"]:
            for code, count in summary["issues_by_code"].items():
                f.write(f"- `{code}`: {count}\n")
        else:
            f.write("- None\n")
    return {"json": str(json_path), "csv": str(csv_path), "summary_md": str(summary_path)}


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Step 8B B1 decision-aware validator")
    ap.add_argument("--records", required=True, help="Record JSON file or directory")
    ap.add_argument("--schema", default="research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json")
    ap.add_argument("--inventory", default="research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv")
    ap.add_argument("--taxonomy", default="research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json")
    ap.add_argument("--decisions", default="research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json")
    ap.add_argument("--out-dir", default="research/phase-13.3/annotation_pipeline_step8b/reports/validation")
    args = ap.parse_args(argv)

    schema = load_json(Path(args.schema))
    validator = Draft202012Validator(schema)
    _, by_id, by_path = load_inventory(Path(args.inventory))
    tax = load_json(Path(args.taxonomy))
    in_scope = set(tax.get("in_scope_classes", []))
    out_scope = set(tax.get("out_of_scope_classes", []))
    decisions = load_decisions(Path(args.decisions))

    records = list(iter_records(Path(args.records)))
    per_record_issues: list[list[Issue]] = []
    results: list[dict[str, Any]] = []
    for src, rec in records:
        issues, result = validate_record(src, rec, validator, by_id, by_path, in_scope, out_scope, decisions)
        per_record_issues.append(issues)
        results.append(result)

    cross_issues = detect_cross_record_issues(records, per_record_issues)
    # compute superseded IDs for export blocking (from valid semantic references only)
    id_counts = Counter(str(rec.get("record_id") or "") for _, rec in records if isinstance(rec, dict) and str(rec.get("record_id") or ""))
    valid_record_ids = {rid for rid, c in id_counts.items() if c == 1}
    superseded_ids: set[str] = set()
    invalid_supersession_ids: set[str] = {i.record_id for i in cross_issues if i.code == "E-SUPERSESSION" and i.record_id}
    for i in cross_issues:
        if i.code == "E-SUPERSESSION" and isinstance(i.details, dict):
            cycle = i.details.get("cycle")
            if isinstance(cycle, list):
                invalid_supersession_ids.update([c for c in cycle if isinstance(c, str) and c])
    for _, rec in records:
        if not isinstance(rec, dict) or rec.get("__load_error__"):
            continue
        rid = str(rec.get("record_id") or "").strip()
        sid = _extract_supersedes(rec)
        if not isinstance(sid, str) or not sid or sid == "__INVALID_NON_STRING__":
            continue
        if rid and sid and sid != rid and sid in valid_record_ids and rid not in invalid_supersession_ids and sid not in invalid_supersession_ids:
            superseded_ids.add(sid)
    # attach cross-record issues to affected record results
    issues_by_record_id: dict[str, list[Issue]] = defaultdict(list)
    for i in cross_issues:
        issues_by_record_id[i.record_id].append(i)

    all_issues: list[Issue] = []
    for rec_issues, result in zip(per_record_issues, results):
        combined = list(rec_issues) + issues_by_record_id.get(str(result.get("record_id") or ""), [])
        rid = str(result.get("record_id") or "")
        if rid and rid in superseded_ids:
            combined.append(Issue(severity="export_block", code="X-SUPERSEDED", record_id=rid, source=str(result.get("source","")), image_id="", field="record_id", message="Record is superseded by a newer record and cannot be canonically exported", details={"record_id": rid}))
        # dedupe identical issues
        seen = set()
        deduped = []
        for i in combined:
            key = (i.severity, i.code, i.record_id, i.source, i.image_id, i.field, i.message, json.dumps(i.details, sort_keys=True))
            if key in seen:
                continue
            seen.add(key)
            deduped.append(i)
        all_issues.extend(deduped)
        has_errors = any(i.severity == "error" for i in deduped)
        export_block_codes = sorted({i.code for i in deduped if i.severity == "export_block" or (i.severity == "error" and i.code.startswith("E-"))})
        if has_errors:
            export_block_codes = sorted(set(export_block_codes) | {"X-INVALID"})
        result["valid"] = not has_errors
        result["export_block_codes"] = export_block_codes
        result["export_eligible_canonical"] = (not has_errors) and len(export_block_codes) == 0 and result.get("verification_status") == "confirmed" and result.get("recognition_method") == "individual"

    report_paths = build_reports(results, all_issues, Path(args.out_dir), args.records)
    summary = {
        "records_checked": len(results),
        "valid_records": sum(1 for r in results if r["valid"]),
        "invalid_records": sum(1 for r in results if not r["valid"]),
        "export_eligible_canonical_records": sum(1 for r in results if r["export_eligible_canonical"]),
        "errors": sum(1 for i in all_issues if i.severity == "error"),
        "warnings": sum(1 for i in all_issues if i.severity == "warning"),
        "report_json": report_paths["json"],
        "report_csv": report_paths["csv"],
    }
    print(json.dumps(summary, indent=2))
    return 1 if summary["errors"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
