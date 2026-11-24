"""Correct only the 5 master CSV records referencing missing image files.

- Preserves original master as backup.
- Excludes genuinely missing records from corrected working master.
- No dataset images/taxonomy/mappings modified.
"""
from __future__ import annotations

import csv
import datetime
import shutil
from pathlib import Path

TARGETS = {
    ("Chile Flakes", "chile_flake_00012.jpg"),
    ("Chile Flakes", "chile_flake_00024.jpg"),
    ("Potato", "potato_00004.jpg"),
    ("Potato", "potato_00005.jpg"),
    ("Potato", "potato_00006.jpg"),
}


def main():
    base = Path("research/phase-13.3/annotation_pipeline_step8a/reports")
    src = base / "human_verification_master.csv"
    backup = base / "human_verification_master.pre_missing_file_correction_backup.csv"
    if not backup.exists():
        shutil.copy2(src, backup)
        print(f"backup_created:{backup.as_posix()}")
    else:
        print(f"backup_exists:{backup.as_posix()}")

    rows = list(csv.DictReader(src.open(encoding="utf-8-sig", newline="")))
    kept, removed = [], []
    for r in rows:
        key = ((r.get("Original folder") or "").strip(), (r.get("Filename") or "").strip())
        (removed if key in TARGETS else kept).append(r)

    with src.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=["Original folder", "Filename", "Expected ingredient", "Verified ingredients", "Result", "Status"])
        w.writeheader()
        w.writerows(kept)

    orig_rows = list(csv.DictReader(backup.open(encoding="utf-8-sig", newline="")))
    line_map = {
        ((r.get("Original folder") or "").strip(), (r.get("Filename") or "").strip()): i
        for i, r in enumerate(orig_rows, start=2)
    }
    rep = base / "missing_file_correction_report.md"
    now = datetime.datetime.now(datetime.timezone.utc).isoformat()
    lines = [
        "# Missing File Correction Report",
        "",
        f"- Generated: {now}",
        f"- Original master preserved as: `{backup.as_posix()}`",
        f"- Corrected working master: `{src.as_posix()}`",
        "- Scope: only 5 CSV records referencing missing image files",
        "- No dataset images modified/renamed/moved/deleted",
        "- No taxonomy/folder mappings modified",
        "",
        "## Investigation",
        "",
        "Checked actual folders and recursive dataset search for the 5 target basenames. Also inspected neighboring numeric filenames in affected folders.",
        "- `Chile Flakes`: files present are 00001-00011,00013-00023,00025-00027; missing 00012,00024. No renamed/moved candidate found.",
        "- `Potato`: files present are 00001-00003,00007-00210; missing 00004,00005,00006. No renamed/moved candidate found.",
        "",
        "## Decisions",
        "",
        "| Folder | Filename | CSV line (original backup) | Search result | Decision | Reason |",
        "|---|---|---:|---|---|---|",
    ]
    for folder, fn in sorted(TARGETS):
        exists = (Path("research/phase-13.3/collected_dataset") / folder / fn).exists()
        lines.append(
            f"| {folder} | {fn} | {line_map.get((folder, fn), '')} | Not found in folder/recursive search; exists={exists} | Excluded from corrected working master | Genuinely missing file; no rename/move candidate found; no filename guessed |"
        )
    lines += [
        "",
        "## Counts",
        "",
        f"- Original master rows: {len(orig_rows)}",
        f"- Removed rows: {len(removed)}",
        f"- Corrected working master rows: {len(kept)}",
        "",
        "## Removed records",
        "",
    ]
    for r in removed:
        lines.append(f"- {r}")
    lines += [
        "",
        "## No-change statement",
        "",
        "- Original master backed up; corrected master excludes only the 5 confirmed-missing records.",
        "- No fake files created; no filename guesses.",
        "- No dataset images/taxonomy/mappings modified.",
    ]
    rep.write_text("\n".join(lines), encoding="utf-8")
    print(f"original:{len(rows)} kept:{len(kept)} removed:{len(removed)}")
    print(f"report:{rep.as_posix()}")


if __name__ == "__main__":
    main()
