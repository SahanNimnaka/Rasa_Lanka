"""Step 7: perceptual-hash near-duplicate detection for the RasaLanka dataset.

Read-only. Extends Step 6 exact SHA-256 duplicate groups with pHash and dHash
candidates. Candidates are never automatically merged or deleted; every group
is marked pending human review.

Usage:
    python detect_near_duplicates.py --workers 4
    python detect_near_duplicates.py --incremental --workers 4
"""

from __future__ import annotations

import argparse
import csv
import itertools
import json
import logging
import os
import statistics
import sys
import time
from collections import Counter, defaultdict
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import cv2
import numpy as np

SCRIPT_DIR = Path(__file__).resolve().parent
PIPELINE_DIR = SCRIPT_DIR.parent
DEFAULT_DATASET_ROOT = PIPELINE_DIR.parent / "collected_dataset"
CONFIG_PATH = PIPELINE_DIR / "config" / "quality_assessment_config.json"
INVENTORY_CSV = PIPELINE_DIR / "exports" / "master_image_inventory.csv"
CANDIDATES_CSV = PIPELINE_DIR / "exports" / "duplicate_candidates.csv"
REPORT_JSON = PIPELINE_DIR / "reports" / "near_duplicate_report.json"
REPORT_MD = PIPELINE_DIR / "reports" / "near_duplicate_report.md"
HASH_CACHE = PIPELINE_DIR / "exports" / "perceptual_hash_cache.json"
HISTORY_PATH = PIPELINE_DIR / "reports" / "near_duplicate_history.jsonl"

CANDIDATE_FIELDS = [
    "group_id",
    "image_id",
    "relative_path",
    "original_folder",
    "original_filename",
    "sha256",
    "phash",
    "dhash",
    "similarity_to_representative",
    "detection_method",
    "review_status",
    "group_size",
    "is_exact_duplicate",
    "exact_group_id",
]


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def load_json(path: Path, default: Any) -> Any:
    if not path.is_file():
        return default
    with path.open(encoding="utf-8") as handle:
        return json.load(handle)


def atomic_write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix(path.suffix + ".tmp")
    with temp.open("w", encoding="utf-8", newline="\n") as handle:
        json.dump(payload, handle, indent=2, ensure_ascii=False)
        handle.write("\n")
    os.replace(temp, path)


def build_logger() -> logging.Logger:
    logger = logging.getLogger("rasalanka.neardup")
    logger.setLevel(logging.INFO)
    logger.handlers.clear()
    logger.propagate = False
    formatter = logging.Formatter("%(asctime)s | %(levelname)s | %(message)s")
    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(formatter)
    logger.addHandler(stream)
    return logger


def hamming_similarity(first: str, second: str) -> float:
    if not first or not second or len(first) != len(second):
        return 0.0
    return 1.0 - sum(a != b for a, b in zip(first, second)) / len(first)


def compute_hashes(path: Path, settings: dict[str, Any]) -> tuple[str, str]:
    """Compute 64-bit pHash (DCT) and 64-bit dHash (horizontal gradient)."""
    image = cv2.imread(str(path), cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError("cv2 could not decode image")
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    size = int(settings["phash_size"])
    hash_bits = int(settings["phash_hash_bits"])
    resized = cv2.resize(gray, (size, size), interpolation=cv2.INTER_AREA).astype(np.float32)
    dct = cv2.dct(resized)[:hash_bits, :hash_bits]
    values = dct.flatten()[1:]
    phash = "".join("1" if value > float(np.median(values)) else "0" for value in values)

    dwidth = int(settings["dhash_width"])
    dheight = int(settings["dhash_height"])
    small = cv2.resize(gray, (dwidth, dheight), interpolation=cv2.INTER_AREA)
    dhash = "".join(
        "1" if small[row, col] > small[row, col - 1] else "0"
        for row in range(dheight)
        for col in range(1, dwidth)
    )
    return phash, dhash


class UnionFind:
    def __init__(self, size: int) -> None:
        self.parent = list(range(size))

    def find(self, item: int) -> int:
        while self.parent[item] != item:
            self.parent[item] = self.parent[self.parent[item]]
            item = self.parent[item]
        return item

    def union(self, left: int, right: int) -> None:
        left_root, right_root = self.find(left), self.find(right)
        if left_root != right_root:
            self.parent[right_root] = left_root


class NearDuplicateDetector:
    def __init__(
        self,
        dataset_root: Path,
        pipeline_dir: Path,
        config: dict[str, Any],
        workers: int = 4,
        incremental: bool = False,
        logger: logging.Logger | None = None,
        inventory_csv: Path | None = None,
    ) -> None:
        self.dataset_root = dataset_root.resolve()
        self.pipeline_dir = pipeline_dir.resolve()
        self.config = config
        self.settings = config["duplicate_detection"]
        self.workers = max(1, workers)
        self.incremental = incremental
        self.logger = logger or build_logger()
        self.inventory_csv = inventory_csv or (self.pipeline_dir / "exports" / "master_image_inventory.csv")
        self.candidates_csv = self.pipeline_dir / "exports" / "duplicate_candidates.csv"
        self.report_json = self.pipeline_dir / "reports" / "near_duplicate_report.json"
        self.report_md = self.pipeline_dir / "reports" / "near_duplicate_report.md"
        self.hash_cache_path = self.pipeline_dir / "exports" / "perceptual_hash_cache.json"
        self.history_path = self.pipeline_dir / "reports" / "near_duplicate_history.jsonl"
        self.started_at = utc_now()

    def load_inventory(self) -> list[dict[str, str]]:
        if not self.inventory_csv.is_file():
            raise FileNotFoundError(f"Master inventory not found: {self.inventory_csv}")
        with self.inventory_csv.open(encoding="utf-8", newline="") as handle:
            return list(csv.DictReader(handle))

    def load_hash_cache(self) -> dict[str, dict[str, str]]:
        payload = load_json(self.hash_cache_path, {})
        if not isinstance(payload, dict) or payload.get("config_version") != self.config["config_version"]:
            return {}
        cache = payload.get("hashes", {})
        return cache if isinstance(cache, dict) else {}

    def compute_for_row(self, row: dict[str, str]) -> dict[str, Any]:
        path = self.dataset_root / row.get("relative_path", "")
        try:
            phash, dhash = compute_hashes(path, self.settings)
            return {"sha256": row.get("sha256", ""), "phash": phash, "dhash": dhash, "error": None}
        except Exception as exc:
            return {
                "sha256": row.get("sha256", ""),
                "phash": None,
                "dhash": None,
                "error": f"{type(exc).__name__}: {exc}",
            }

    @staticmethod
    def bands(value: str, sizes: list[int]) -> list[str]:
        result = []
        position = 0
        for size in sizes:
            result.append(f"{position}:{value[position:position + size]}")
            position += size
        return result

    def run(self) -> dict[str, Any]:
        started = time.monotonic()
        rows = self.load_inventory()
        cache = self.load_hash_cache()
        hashes: dict[str, dict[str, Any]] = {}
        new_hashes = 0
        failed_hashes = 0
        pending: list[dict[str, str]] = []
        for row in rows:
            digest = row.get("sha256", "")
            if digest in cache:
                hashes[digest] = cache[digest]
            elif digest not in hashes:
                hashes[digest] = {"sha256": digest, "phash": None, "dhash": None}
                pending.append(row)

        self.logger.info("Computing perceptual hashes for %d images (%d cache hits)", len(pending), len(rows) - len(pending))
        with ThreadPoolExecutor(max_workers=self.workers) as pool:
            futures = [pool.submit(self.compute_for_row, row) for row in pending]
            for index, future in enumerate(as_completed(futures), start=1):
                result = future.result()
                digest = result["sha256"]
                if result["phash"] and result["dhash"]:
                    hashes[digest] = result
                    new_hashes += 1
                else:
                    failed_hashes += 1
                if index % 500 == 0:
                    self.logger.info("Perceptual hashes computed: %d/%d", index, len(pending))

        parent = UnionFind(len(rows))
        exact_groups: dict[str, list[int]] = defaultdict(list)
        for index, row in enumerate(rows):
            group_id = row.get("duplicate_group_id", "")
            if group_id:
                exact_groups[group_id].append(index)
        for members in exact_groups.values():
            for other in members[1:]:
                parent.union(members[0], other)

        buckets: dict[str, list[int]] = defaultdict(list)
        skipped_buckets = 0
        for index, row in enumerate(rows):
            digest = row.get("sha256", "")
            entry = hashes.get(digest, {})
            if not entry.get("phash"):
                continue
            band_sets = [
                self.bands(entry["phash"], [16] * int(self.settings["lsh_bands"])),
                self.bands(entry["phash"], [8] * int(self.settings["lsh_small_bands"])),
                self.bands(entry["dhash"], [16] * int(self.settings["lsh_bands"])),
            ]
            for group in band_sets:
                for band in group:
                    buckets[band].append(index)
        for key in list(buckets):
            if len(buckets[key]) > int(self.settings["max_bucket_size"]):
                skipped_buckets += 1
                del buckets[key]

        candidate_pairs: set[tuple[int, int]] = set()
        for members in buckets.values():
            if len(members) < 2:
                continue
            for left, right in itertools.combinations(sorted(members), 2):
                candidate_pairs.add((left, right))

        min_similarity = float(self.settings["min_similarity"])
        phash_limit = int(self.settings["phash_max_hamming"])
        dhash_limit = int(self.settings["dhash_max_hamming"])
        confirmed_pairs = 0
        pair_similarities: dict[tuple[int, int], tuple[float, float]] = {}
        for left, right in candidate_pairs:
            left_entry = hashes.get(rows[left].get("sha256", ""), {})
            right_entry = hashes.get(rows[right].get("sha256", ""), {})
            if not left_entry.get("phash") or not right_entry.get("phash"):
                continue
            phash_sim = hamming_similarity(left_entry["phash"], right_entry["phash"])
            dhash_sim = hamming_similarity(left_entry["dhash"], right_entry["dhash"])
            pair_similarities[(left, right)] = (phash_sim, dhash_sim)
            if (
                phash_sim >= min_similarity
                or dhash_sim >= min_similarity
                or (64 - round(phash_sim * 64)) <= phash_limit
                or (64 - round(dhash_sim * 64)) <= dhash_limit
            ):
                parent.union(left, right)
                confirmed_pairs += 1

        groups: dict[int, list[int]] = defaultdict(list)
        for index in range(len(rows)):
            groups[parent.find(index)].append(index)
        multi_groups = [sorted(members) for members in groups.values() if len(members) > 1]
        multi_groups.sort(key=lambda members: min(rows[i]["image_id"] for i in members))

        group_details = []
        candidate_rows = []
        for group_index, members in enumerate(multi_groups, start=1):
            group_id = f"NDUP-{group_index:06d}"
            representative = min(members, key=lambda i: rows[i]["image_id"])
            rep_entry = hashes.get(rows[representative].get("sha256", ""), {})
            methods: set[str] = set()
            if len({rows[i]["sha256"] for i in members}) == 1:
                methods.add("exact_sha256")
            similarities = []
            for i in members:
                if i == representative:
                    continue
                pair = (min(i, representative), max(i, representative))
                sims = pair_similarities.get(pair)
                if sims:
                    similarities.append(sims)
                    if sims[0] >= min_similarity:
                        methods.add("phash")
                    if sims[1] >= min_similarity:
                        methods.add("dhash")
            similarity_to_rep = 1.0 if "exact_sha256" in methods else (
                max(max(sim) for sim in similarities) if similarities else None
            )
            method_label = "+".join(sorted(methods)) if methods else "lsh_candidate"
            folders = sorted({rows[i]["original_folder"] for i in members})
            group_details.append({
                "group_id": group_id,
                "review_status": "pending_review",
                "detection_method": method_label,
                "image_count": len(members),
                "cross_folder": len(folders) > 1,
                "folders": folders,
                "image_ids": [rows[i]["image_id"] for i in members],
                "original_filenames": [rows[i]["original_filename"] for i in members],
                "sha256": [rows[i]["sha256"] for i in members],
                "phash": [hashes.get(rows[i]["sha256"], {}).get("phash") for i in members],
                "dhash": [hashes.get(rows[i]["sha256"], {}).get("dhash") for i in members],
                "similarity_to_representative": similarity_to_rep,
                "mean_phash_similarity": round(statistics.mean(s[0] for s in similarities), 4) if similarities else None,
                "mean_dhash_similarity": round(statistics.mean(s[1] for s in similarities), 4) if similarities else None,
                "exact_group_ids": sorted({rows[i]["duplicate_group_id"] for i in members if rows[i].get("duplicate_group_id")}),
            })
            for i in members:
                row = rows[i]
                candidate_rows.append({
                    "group_id": group_id,
                    "image_id": row["image_id"],
                    "relative_path": row["relative_path"],
                    "original_folder": row["original_folder"],
                    "original_filename": row["original_filename"],
                    "sha256": row["sha256"],
                    "phash": rep_entry.get("phash") if i == representative else hashes.get(row["sha256"], {}).get("phash"),
                    "dhash": rep_entry.get("dhash") if i == representative else hashes.get(row["sha256"], {}).get("dhash"),
                    "similarity_to_representative": 1.0 if i == representative else similarity_to_rep,
                    "detection_method": method_label,
                    "review_status": "pending_review",
                    "group_size": len(members),
                    "is_exact_duplicate": bool(i != representative and row["sha256"] == rows[representative]["sha256"]),
                    "exact_group_id": row.get("duplicate_group_id", ""),
                })

        method_counts = Counter(detail["detection_method"] for detail in group_details)
        report = {
            "report_version": "near-duplicate-report-v1",
            "config_version": self.config["config_version"],
            "generated_at": utc_now(),
            "started_at": self.started_at,
            "duration_seconds": round(time.monotonic() - started, 3),
            "dataset_root": str(self.dataset_root),
            "inventory_source": str(self.inventory_csv),
            "policy": {
                "screening_only": True,
                "no_automatic_merge": True,
                "no_images_modified": True,
                "human_review_required": True,
                "exact_sha256_groups_preserved": True,
                "ocr_claimed": False,
            },
            "total_inventory_images": len(rows),
            "hashed_images": sum(1 for digest in {r["sha256"] for r in rows} if hashes.get(digest, {}).get("phash")),
            "new_perceptual_hashes": new_hashes,
            "failed_perceptual_hashes": failed_hashes,
            "exact_duplicate_groups_from_step6": len(exact_groups),
            "candidate_pairs_evaluated": len(candidate_pairs),
            "confirmed_pairs": confirmed_pairs,
            "skipped_large_buckets": skipped_buckets,
            "candidate_group_count": len(multi_groups),
            "images_in_candidate_groups": sum(len(group) for group in multi_groups),
            "cross_folder_group_count": sum(1 for d in group_details if d["cross_folder"]),
            "detection_method_counts": dict(sorted(method_counts.items())),
            "groups": group_details,
        }
        self.write_outputs(candidate_rows, report, hashes)
        self.logger.info("Near-duplicate detection complete: %d candidate groups", len(multi_groups))
        return report

    def write_outputs(
        self,
        candidate_rows: list[dict[str, Any]],
        report: dict[str, Any],
        hashes: dict[str, dict[str, Any]],
    ) -> None:
        self.pipeline_dir.joinpath("exports").mkdir(parents=True, exist_ok=True)
        self.pipeline_dir.joinpath("reports").mkdir(parents=True, exist_ok=True)
        with self.candidates_csv.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=CANDIDATE_FIELDS, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(candidate_rows)
        atomic_write_json(self.report_json, report)
        self.report_md.write_text(render_markdown(report), encoding="utf-8", newline="\n")
        atomic_write_json(
            self.hash_cache_path,
            {"config_version": self.config["config_version"], "hashes": hashes},
        )
        with self.history_path.open("a", encoding="utf-8", newline="\n") as handle:
            handle.write(json.dumps({
                "started_at": self.started_at,
                "generated_at": report["generated_at"],
                "groups": report["candidate_group_count"],
                "images_in_groups": report["images_in_candidate_groups"],
                "duration_seconds": report["duration_seconds"],
            }, ensure_ascii=False) + "\n")


def render_markdown(report: dict[str, Any]) -> str:
    lines = [
        "# RasaLanka near-duplicate candidate report",
        "",
        f"- Generated: {report['generated_at']}",
        f"- Duration: {report['duration_seconds']} s",
        f"- Inventory images: {report['total_inventory_images']}",
        f"- Hashed images: {report['hashed_images']}",
        f"- Exact SHA-256 groups preserved from Step 6: {report['exact_duplicate_groups_from_step6']}",
        f"- Candidate pairs evaluated: {report['candidate_pairs_evaluated']}",
        f"- Confirmed similarity pairs: {report['confirmed_pairs']}",
        f"- Candidate groups: {report['candidate_group_count']}",
        f"- Images in candidate groups: {report['images_in_candidate_groups']}",
        f"- Cross-folder groups: {report['cross_folder_group_count']}",
        "",
        "All groups are machine-generated screening candidates with `pending_review` status. "
        "No images were merged, deleted or modified, and no ingredient classification was performed.",
        "",
        "## Detection methods",
        "",
        "| Method | Groups |",
        "| --- | ---: |",
    ]
    for method, count in report["detection_method_counts"].items():
        lines.append(f"| {method} | {count} |")
    lines += ["", "## Largest candidate groups", "", "| Group | Images | Folders | Method |", "| --- | ---: | --- | --- |"]
    for group in sorted(report["groups"], key=lambda g: g["image_count"], reverse=True)[:30]:
        lines.append(
            f"| {group['group_id']} | {group['image_count']} | "
            f"{', '.join(group['folders'][:3])} | {group['detection_method']} |"
        )
    lines.append("")
    return "\n".join(lines)


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset-root", type=Path, default=DEFAULT_DATASET_ROOT)
    parser.add_argument("--pipeline-dir", type=Path, default=PIPELINE_DIR)
    parser.add_argument("--config", type=Path, default=CONFIG_PATH)
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--incremental", action="store_true")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    config = load_json(args.config, {})
    if not config:
        print(f"Config not found: {args.config}", file=sys.stderr)
        return 1
    detector = NearDuplicateDetector(
        dataset_root=args.dataset_root,
        pipeline_dir=args.pipeline_dir,
        config=config,
        workers=args.workers,
        incremental=args.incremental,
    )
    try:
        report = detector.run()
    except Exception as exc:
        detector.logger.exception("Near-duplicate detection aborted: %s", exc)
        return 1
    print(json.dumps({
        "groups": report["candidate_group_count"],
        "images_in_groups": report["images_in_candidate_groups"],
        "exact_groups_preserved": report["exact_duplicate_groups_from_step6"],
        "duration_seconds": report["duration_seconds"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
