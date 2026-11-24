"""Generate manual review contact sheets and CSV template for Paper Corn.

Read-only with respect to dataset images, master CSV, taxonomy, and mappings.
Outputs are written only to Step 8A reports directory.
"""
from __future__ import annotations

import csv
import datetime
import math
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ALLOWED_DECISIONS = ["black_pepper_whole", "black_pepper_ground", "uncertain", "mixed"]


def load_font(size: int):
    for name in ["arial.ttf", "Arial.ttf", "DejaVuSans.ttf", "segoeui.ttf"]:
        try:
            return ImageFont.truetype(name, size=size)
        except Exception:
            pass
    return ImageFont.load_default()


def main():
    base = Path("research/phase-13.3")
    src_dir = base / "collected_dataset" / "Paper Corn"
    master_csv = base / "annotation_pipeline_step8a/reports/human_verification_master.csv"
    out_dir = base / "annotation_pipeline_step8a/reports/paper_corn_manual_review"
    sheets_dir = out_dir / "contact_sheets"
    out_dir.mkdir(parents=True, exist_ok=True)
    sheets_dir.mkdir(parents=True, exist_ok=True)

    master_rows = list(csv.DictReader(master_csv.open(encoding="utf-8-sig", newline="")))
    pc_rows = [r for r in master_rows if (r.get("Original folder") or "").strip() == "Paper Corn"]
    by_name = {(r.get("Filename") or "").strip(): r for r in pc_rows}

    files = sorted([p for p in src_dir.iterdir() if p.is_file()])
    if not files:
        raise SystemExit(f"No files found in {src_dir}")

    # CSV template
    template_path = out_dir / "paper_corn_review_template.csv"
    with template_path.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(
            f,
            fieldnames=["Filename", "Current ingredient label", "Proposed canonical class", "Review decision", "Reviewer notes"],
        )
        w.writeheader()
        for p in files:
            r = by_name.get(p.name, {})
            current = (r.get("Verified ingredients") or r.get("Expected ingredient") or "").strip()
            w.writerow(
                {
                    "Filename": p.name,
                    "Current ingredient label": current,
                    "Proposed canonical class": "",  # human fills; do not auto-classify
                    "Review decision": "",  # black_pepper_whole / black_pepper_ground / uncertain / mixed
                    "Reviewer notes": "",
                }
            )

    # Contact sheets
    cols, rows_per_sheet = 4, 5
    per_sheet = cols * rows_per_sheet
    thumb_w, thumb_h = 420, 320
    label_h = 56
    margin = 24
    title_h = 70
    sheet_w = margin * 2 + cols * thumb_w
    sheet_h = title_h + margin + rows_per_sheet * (thumb_h + label_h) + margin
    font = load_font(18)
    title_font = load_font(28)
    small_font = load_font(16)

    sheet_paths = []
    total_sheets = math.ceil(len(files) / per_sheet)
    for s in range(total_sheets):
        batch = files[s * per_sheet : (s + 1) * per_sheet]
        img = Image.new("RGB", (sheet_w, sheet_h), "white")
        draw = ImageDraw.Draw(img)
        title = f"Paper Corn Manual Review - Sheet {s + 1}/{total_sheets} ({batch[0].name} - {batch[-1].name})"
        draw.text((margin, 20), title, fill="black", font=title_font)
        draw.text((margin, 48), "Allowed decisions: black_pepper_whole | black_pepper_ground | uncertain | mixed", fill="black", font=small_font)
        for idx, p in enumerate(batch):
            r = idx // cols
            c = idx % cols
            x0 = margin + c * thumb_w
            y0 = title_h + margin + r * (thumb_h + label_h)
            cell = Image.new("RGB", (thumb_w - 8, thumb_h + label_h - 8), "white")
            cdraw = ImageDraw.Draw(cell)
            try:
                with Image.open(p) as im:
                    im = im.convert("RGB")
                    im.thumbnail((thumb_w - 16, thumb_h - 16))
                    px = (thumb_w - 8 - im.size[0]) // 2
                    py = 4
                    cell.paste(im, (px, py))
            except Exception as e:
                cdraw.rectangle((4, 4, thumb_w - 12, thumb_h - 12), outline="red")
                cdraw.text((10, 20), f"READ ERROR\n{p.name}\n{e}", fill="red", font=small_font)
            cdraw.rectangle((0, 0, thumb_w - 9, thumb_h + label_h - 9), outline="black")
            cdraw.text((6, thumb_h + 6), p.name, fill="black", font=font)
            img.paste(cell, (x0, y0))
        out = sheets_dir / f"paper_corn_contact_sheet_{s + 1:02d}.jpg"
        img.save(out, format="JPEG", quality=92)
        sheet_paths.append(out)

    # Instructions
    instr = out_dir / "PAPER_CORN_MANUAL_REVIEW_README.md"
    now = datetime.datetime.now(datetime.timezone.utc).isoformat()
    lines = [
        "# Paper Corn Manual Review Pack",
        "",
        f"- Generated: {now}",
        f"- Source folder: `{src_dir.as_posix()}` (read-only)",
        f"- Master CSV reference: `{master_csv.as_posix()}` (not modified)",
        f"- Images: {len(files)}",
        f"- Contact sheets: {len(sheet_paths)}",
        "",
        "## Purpose",
        "",
        "Resolve the Step 8A ambiguity for `Paper Corn` / `Pepper Corn` by human review. No images were auto-classified.",
        "",
        "## Allowed review decisions",
        "",
        "- `black_pepper_whole` — whole peppercorns/pepper seeds clearly visible; no ground-powder dominance.",
        "- `black_pepper_ground` — ground/powdered pepper clearly visible; no whole peppercorn dominance.",
        "- `uncertain` — image is ambiguous, poor quality, occluded, or cannot be confidently assigned.",
        "- `mixed` — both whole and ground pepper are clearly present as significant content.",
        "",
        "## How to complete the review",
        "",
        "1. Open the contact sheets in `contact_sheets/` for visual reference. Each image includes a readable filename.",
        "2. Open `paper_corn_review_template.csv` in a spreadsheet editor.",
        "3. For each row, review the corresponding image in `research/phase-13.3/collected_dataset/Paper Corn/`.",
        "4. Fill **Review decision** with exactly one allowed value: `black_pepper_whole`, `black_pepper_ground`, `uncertain`, or `mixed`.",
        "5. Fill **Proposed canonical class** with the same canonical class when decision is `black_pepper_whole` or `black_pepper_ground`; for `uncertain` or `mixed`, leave blank or explain in notes as instructed by the coordinator.",
        "6. Add **Reviewer notes** for ambiguity, packaging, poor quality, multiple objects, or reason for uncertainty.",
        "7. Do **not** rename images, edit filenames, change taxonomy, or modify the master CSV directly.",
        "8. Save the completed CSV as a new file (e.g., `paper_corn_review_template.completed.csv`) and return it for Step 8A approval before any data correction.",
        "",
        "## Guardrails",
        "",
        "- Do not treat folder name or existing `Pepper Corn` label as ground truth.",
        "- Do not guess when unclear; use `uncertain`.",
        "- Use `mixed` only when both whole and ground forms are clearly present.",
        "- Original images, master CSV, taxonomy, and folder mappings were not modified by this pack.",
        "",
        "## Generated files",
        "",
        f"- `{template_path.as_posix()}`",
        f"- `{instr.as_posix()}`",
        f"- `{sheets_dir.as_posix()}/paper_corn_contact_sheet_01..{len(sheet_paths):02d}.jpg`",
    ]
    instr.write_text("\n".join(lines), encoding="utf-8")
    print(f"template:{template_path.as_posix()}")
    print(f"instructions:{instr.as_posix()}")
    print(f"sheets:{len(sheet_paths)}")
    for p in sheet_paths:
        print(p.as_posix())


if __name__ == "__main__":
    main()
