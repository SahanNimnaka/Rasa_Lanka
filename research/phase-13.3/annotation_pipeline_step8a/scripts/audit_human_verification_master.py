"""Read-only consistency audit for human_verification_master.csv.

Compares master CSV records against collected_dataset files and reports:
- CSV records whose image files do not exist
- Image files missing from CSV
- Duplicate filenames/records
- Folder/ingredient mapping inconsistencies
- Approved display-name aliases (resolved by decision register)
- Pending canonical mappings (unresolved, explicitly preserved)
- Filename inconsistencies
- Invalid or unsupported image formats

No dataset images or master CSV are modified.
"""
from __future__ import annotations

import argparse
import csv
import datetime
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

ALLOWED_EXT = {".jpg", ".jpeg", ".png", ".bmp", ".gif", ".webp", ".tif", ".tiff", ".jfif", ".avif"}
FILENAME_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9 _\-\.()\[\]]*$")


def norm(s: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", (s or "").lower()).strip("_")


def load_mapping_decisions(path: Path):
    if not path.exists():
        return {}
    obj = json.loads(path.read_text(encoding="utf-8"))
    decisions = {}
    for d in obj.get("decisions", []):
        folder = (d.get("folder_name") or "").strip()
        label = (d.get("observed_label") or "").strip()
        if folder:
            decisions[(folder, label)] = d
    return decisions


def load_rows(csv_path: Path):
    rows = list(csv.DictReader(csv_path.open(encoding="utf-8-sig", newline="")))
    for i, r in enumerate(rows, start=2):
        r["_line"] = i
        for k in ["Original folder", "Filename", "Expected ingredient", "Verified ingredients", "Result", "Status"]:
            r[k] = (r.get(k) or "").strip()
    return rows


def scan_actual(ds: Path):
    actual = []
    for d in sorted(ds.iterdir()):
        if not d.is_dir():
            continue
        for f in sorted(d.iterdir()):
            if f.is_file():
                actual.append((d.name, f.name, f.suffix.lower(), str(f).replace("\\", "/")))
    return actual


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--csv", default="research/phase-13.3/annotation_pipeline_step8a/reports/human_verification_master.csv")
    ap.add_argument("--dataset", default="research/phase-13.3/collected_dataset")
    ap.add_argument("--mapping", default="research/phase-13.3/annotation_pipeline/config/folder_class_mapping.json")
    ap.add_argument("--taxonomy", default="research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json")
    ap.add_argument("--decisions", default="research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json")
    ap.add_argument("--out-dir", default="research/phase-13.3/annotation_pipeline_step8a/reports")
    args = ap.parse_args()

    csv_path = Path(args.csv)
    ds = Path(args.dataset)
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    mapping = json.loads(Path(args.mapping).read_text(encoding="utf-8"))
    m_by = {m["folder_name"]: m for m in mapping.get("mappings", [])}
    decisions = load_mapping_decisions(Path(args.decisions))
    # taxonomy loaded for reference/validation context; not used as ground truth
    json.loads(Path(args.taxonomy).read_text(encoding="utf-8"))

    rows = load_rows(csv_path)
    actual = scan_actual(ds)
    actual_set = {(fo, fi) for fo, fi, _, _ in actual}
    csv_set = {(r["Original folder"], r["Filename"]) for r in rows}

    missing_files = [r for r in rows if (r["Original folder"], r["Filename"]) not in actual_set]
    missing_from_csv = [(fo, fi, ext, p) for fo, fi, ext, p in actual if (fo, fi) not in csv_set]

    dup_keys = Counter((r["Original folder"].lower(), r["Filename"].lower()) for r in rows)
    duplicate_records = [r for r in rows if dup_keys[(r["Original folder"].lower(), r["Filename"].lower())] > 1]
    name_across = defaultdict(list)
    for r in rows:
        name_across[r["Filename"].lower()].append(r["Original folder"])
    duplicate_filenames_across_folders = {n: sorted(set(v)) for n, v in name_across.items() if len(set(v)) > 1}

    invalid_formats = []
    for fo, fi, ext, p in actual:
        if ext not in ALLOWED_EXT:
            invalid_formats.append({"line": "", "folder": fo, "filename": fi, "ext": ext, "source": "filesystem", "path": p})
    for r in rows:
        ext = Path(r["Filename"]).suffix.lower()
        if ext and ext not in ALLOWED_EXT:
            invalid_formats.append({"line": r["_line"], "folder": r["Original folder"], "filename": r["Filename"], "ext": ext, "source": "csv", "path": ""})

    filename_issues = []
    for r in rows:
        fn = r["Filename"]
        reasons = []
        if not fn:
            reasons.append("empty_filename")
        if fn != (r.get("Filename") or ""):
            reasons.append("surrounding_whitespace")
        if "  " in fn:
            reasons.append("double_space")
        if any(ord(c) < 32 for c in fn):
            reasons.append("control_chars")
        if "/" in fn or "\\" in fn:
            reasons.append("path_separator_in_filename")
        if fn and not Path(fn).suffix:
            reasons.append("missing_extension")
        if fn and not FILENAME_RE.match(fn):
            reasons.append("unexpected_characters")
        if reasons:
            filename_issues.append({"line": r["_line"], "folder": r["Original folder"], "filename": fn, "reasons": ";".join(reasons)})

    mapping_issues = []
    approved_alias_records = []
    pending_canonical_records = []
    for r in rows:
        folder = r["Original folder"]
        expected = r["Expected ingredient"]
        verified = r["Verified ingredients"]
        m = m_by.get(folder)
        reasons = []
        decision = decisions.get((folder, expected)) or decisions.get((folder, verified))
        if not m:
            reasons.append("folder_missing_in_mapping_config")
        else:
            prop = m.get("proposed_class_id")
            cands = m.get("candidate_class_ids") or []
            status = m.get("mapping_status")
            exp_n, ver_n, folder_n, prop_n = norm(expected), norm(verified), norm(folder), norm(prop or "")
            cand_ns = {norm(c) for c in cands}
            label_differs = bool(expected and exp_n != folder_n and exp_n != prop_n and (not cand_ns or exp_n not in cand_ns))
            if label_differs:
                if decision and decision.get("decision_type") == "approved_alias" and decision.get("alias_approved"):
                    approved_alias_records.append({
                        "line": r["_line"], "folder": folder, "filename": r["Filename"], "expected": expected, "verified": verified,
                        "result": r["Result"], "status": r["Status"], "decision_type": decision.get("decision_type"),
                        "canonical_class_id": decision.get("canonical_class_id") or "",
                        "details": f"Approved alias: '{expected}' -> '{decision.get('canonical_class_id')}' (preserve original label; no master CSV rewrite)"
                    })
                elif decision and decision.get("decision_type") == "pending_canonical_mapping":
                    pending_canonical_records.append({
                        "line": r["_line"], "folder": folder, "filename": r["Filename"], "expected": expected, "verified": verified,
                        "result": r["Result"], "status": r["Status"], "decision_type": decision.get("decision_type"),
                        "canonical_class_id": decision.get("canonical_class_id") or "",
                        "candidate_canonical_class_ids": ",".join(decision.get("candidate_canonical_class_ids") or []),
                        "details": f"Pending canonical mapping: label '{expected}' preserved; candidates={decision.get('candidate_canonical_class_ids')}; {decision.get('rationale','')}"
                    })
                else:
                    reasons.append(
                        f"expected_differs_from_folder_or_mapping(expected={expected},proposed={prop},candidates={cands},status={status})"
                    )
            if verified and ver_n != exp_n:
                reasons.append(f"verified_differs_from_expected(expected={expected},verified={verified})")
            if not verified:
                reasons.append("blank_verified_ingredients")
            if not r["Result"]:
                reasons.append("blank_result")
            if not r["Status"]:
                reasons.append("blank_status")
        if reasons:
            mapping_issues.append({
                "line": r["_line"], "folder": folder, "filename": r["Filename"], "expected": expected, "verified": verified,
                "result": r["Result"], "status": r["Status"], "reasons": " | ".join(reasons),
            })

    summary = {
        "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "master_csv": csv_path.as_posix(),
        "dataset_dir": ds.as_posix(),
        "mapping_decisions": Path(args.decisions).as_posix(),
        "csv_rows": len(rows),
        "csv_folders": len({r["Original folder"] for r in rows}),
        "actual_files": len(actual),
        "actual_folders": len({fo for fo, _, _, _ in actual}),
        "csv_records_missing_image_file": len(missing_files),
        "image_files_missing_from_csv": len(missing_from_csv),
        "duplicate_csv_records": len(duplicate_records),
        "duplicate_folder_filename_keys": sum(1 for v in dup_keys.values() if v > 1),
        "duplicate_filenames_across_folders": len(duplicate_filenames_across_folders),
        "invalid_or_unsupported_image_formats": len(invalid_formats),
        "filename_inconsistency_records": len(filename_issues),
        "folder_or_ingredient_mapping_inconsistency_records": len(mapping_issues),
        "approved_alias_records": len(approved_alias_records),
        "pending_canonical_mapping_records": len(pending_canonical_records),
    }

    detail = out_dir / "human_verification_master_audit_issues.csv"
    with detail.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=["issue_type", "line", "folder", "filename", "expected_ingredient", "verified_ingredients", "result", "status", "details"])
        w.writeheader()
        for r in missing_files:
            w.writerow({"issue_type": "csv_record_missing_image_file", "line": r["_line"], "folder": r["Original folder"], "filename": r["Filename"], "expected_ingredient": r["Expected ingredient"], "verified_ingredients": r["Verified ingredients"], "result": r["Result"], "status": r["Status"], "details": "Referenced file not found in collected_dataset/<folder>/<filename>"})
        for fo, fi, ext, pth in missing_from_csv:
            w.writerow({"issue_type": "image_file_missing_from_csv", "line": "", "folder": fo, "filename": fi, "expected_ingredient": "", "verified_ingredients": "", "result": "", "status": "", "details": f"Actual file exists but no CSV record: {pth}"})
        for r in duplicate_records:
            key = (r["Original folder"].lower(), r["Filename"].lower())
            w.writerow({"issue_type": "duplicate_csv_record", "line": r["_line"], "folder": r["Original folder"], "filename": r["Filename"], "expected_ingredient": r["Expected ingredient"], "verified_ingredients": r["Verified ingredients"], "result": r["Result"], "status": r["Status"], "details": f"Duplicate key count={dup_keys[key]}"})
        for item in invalid_formats:
            w.writerow({"issue_type": "invalid_or_unsupported_image_format", "line": item["line"], "folder": item["folder"], "filename": item["filename"], "expected_ingredient": "", "verified_ingredients": "", "result": "", "status": "", "details": f"Extension {item['ext']} not in allowed set {sorted(ALLOWED_EXT)}; source={item.get('source','filesystem')}; path={item.get('path','')}"})
        for it in filename_issues:
            w.writerow({"issue_type": "filename_inconsistency", "line": it["line"], "folder": it["folder"], "filename": it["filename"], "expected_ingredient": "", "verified_ingredients": "", "result": "", "status": "", "details": it["reasons"]})
        for it in approved_alias_records:
            w.writerow({"issue_type": "approved_alias", "line": it["line"], "folder": it["folder"], "filename": it["filename"], "expected_ingredient": it["expected"], "verified_ingredients": it["verified"], "result": it["result"], "status": it["status"], "details": it["details"]})
        for it in pending_canonical_records:
            w.writerow({"issue_type": "pending_canonical_mapping", "line": it["line"], "folder": it["folder"], "filename": it["filename"], "expected_ingredient": it["expected"], "verified_ingredients": it["verified"], "result": it["result"], "status": it["status"], "details": it["details"]})
        for it in mapping_issues:
            w.writerow({"issue_type": "folder_or_ingredient_mapping_inconsistency", "line": it["line"], "folder": it["folder"], "filename": it["filename"], "expected_ingredient": it["expected"], "verified_ingredients": it["verified"], "result": it["result"], "status": it["status"], "details": it["reasons"]})

    csv_by = Counter(r["Original folder"] for r in rows)
    act_by = Counter(fo for fo, _, _, _ in actual)
    report = out_dir / "human_verification_master_audit_report.md"
    with report.open("w", encoding="utf-8", newline="") as f:
        f.write("# Human Verification Master CSV - Dataset Consistency Audit\n\n")
        f.write(f"- Generated: {summary['generated_at']}\n")
        f.write(f"- Master CSV: `{csv_path.as_posix()}`\n")
        f.write(f"- Dataset: `{ds.as_posix()}`\n")
        f.write("- Audit mode: **read-only** (no images/CSV modified)\n\n")
        f.write("## Summary\n\n")
        for k, v in summary.items():
            if k in ("generated_at", "master_csv", "dataset_dir"):
                continue
            f.write(f"- **{k}**: {v}\n")
        f.write("\n## Per-folder coverage\n\n")
        f.write("| Folder | CSV records | Actual files | Delta (actual-csv) | Missing from CSV | CSV missing file |\n")
        f.write("|---|---:|---:|---:|---:|---:|\n")
        for folder in sorted(set(csv_by) | set(act_by)):
            c = csv_by.get(folder, 0)
            a = act_by.get(folder, 0)
            miss_csv = sum(1 for fo, fi, _, _ in actual if fo == folder and (fo, fi) not in csv_set)
            miss_file = sum(1 for r in rows if r["Original folder"] == folder and (r["Original folder"], r["Filename"]) not in actual_set)
            f.write(f"| {folder} | {c} | {a} | {a-c} | {miss_csv} | {miss_file} |\n")
        f.write("\n## Duplicate filenames across folders\n\n")
        if duplicate_filenames_across_folders:
            f.write(f"Count: {len(duplicate_filenames_across_folders)}. Sample:\n\n")
            for n, folders in list(sorted(duplicate_filenames_across_folders.items()))[:25]:
                f.write(f"- `{n}`: {', '.join(folders)}\n")
        else:
            f.write("None.\n")
        f.write("\n## Mapping decision resolution\n\n")
        f.write(f"- Approved alias records: {len(approved_alias_records)}\n")
        f.write(f"- Pending canonical mapping records: {len(pending_canonical_records)}\n")
        f.write(f"- Unresolved mapping inconsistencies: {len(mapping_issues)}\n")
        f.write("- Approved aliases are display-name/canonical-ID resolutions documented in the decision register; original master CSV labels are preserved.\n")
        f.write("- Pending canonical mappings are explicitly unresolved; labels are preserved and no canonical taxonomy mapping is forced.\n")
        f.write("\n## Notes\n\n")
        f.write("- This audit is read-only. No dataset images were modified, renamed, moved, or deleted. `human_verification_master.csv` was not modified.\n")
        f.write("- `blank_verified_ingredients/result/status` are reported as mapping/verification inconsistencies because the 6-column schema expects them; they require human confirmation, not automated filling.\n")
        f.write("- Expected/verified ingredient differences are reported for review; folder names and machine mapping proposals are not treated as ground truth.\n")
        f.write("\n## Generated files\n\n")
        f.write(f"- `{detail.as_posix()}`\n")
        f.write(f"- `{report.as_posix()}`\n")

    print(json.dumps(summary, indent=2))
    print(f"DETAIL:{detail.as_posix()}")
    print(f"REPORT:{report.as_posix()}")


if __name__ == "__main__":
    main()
