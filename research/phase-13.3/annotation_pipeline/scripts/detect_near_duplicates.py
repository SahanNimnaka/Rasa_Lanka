"""Step 7 (corrected): pairwise perceptual near-duplicate detection.

The previous implementation used unrestricted Union-Find connected components,
which allowed A-B and B-C similarity to create a misleading A-B-C group. This
version retains actual pairwise comparisons and only builds complete-link
groups in which EVERY member pair satisfies the configured Hamming threshold.

Read-only. Exact SHA-256 groups from Step 6 are preserved but kept distinct from
perceptual candidates. Every candidate remains pending human review.

Usage:
    python detect_near_duplicates.py --workers 4 --incremental
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
from typing import Any, Iterable

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

PAIR_FIELDS = [
    "group_id",
    "image_id_a",
    "relative_path_a",
    "original_folder_a",
    "original_filename_a",
    "sha256_a",
    "phash_a",
    "dhash_a",
    "image_id_b",
    "relative_path_b",
    "original_folder_b",
    "original_filename_b",
    "sha256_b",
    "phash_b",
    "dhash_b",
    "phash_hamming",
    "dhash_hamming",
    "phash_similarity",
    "dhash_similarity",
    "detection_method",
    "cross_folder",
    "review_status",
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


def hamming_distance(first: str, second: str) -> int:
    """Return Hamming distance between two equal-length binary hash strings."""
    if not first or not second or len(first) != len(second):
        raise ValueError("hashes must be non-empty and equal length")
    return sum(left != right for left, right in zip(first, second))


def similarity_from_hamming(distance: int, bits: int = 64) -> float:
    return round(1.0 - distance / bits, 6)


def pair_is_candidate(
    phash_distance: int | None,
    dhash_distance: int | None,
    settings: dict[str, Any],
) -> bool:
    """Exact comparison rule: phash distance <= limit OR dhash distance <= limit."""
    if phash_distance is None or dhash_distance is None:
        return False
    return (
        phash_distance <= int(settings["phash_max_hamming"])
        or dhash_distance <= int(settings["dhash_max_hamming"])
    )


def detection_method_for(
    phash_distance: int,
    dhash_distance: int,
    settings: dict[str, Any],
    exact_sha256: bool,
) -> str | None:
    if exact_sha256:
        return "exact_sha256"
    phash_ok = phash_distance <= int(settings["phash_max_hamming"])
    dhash_ok = dhash_distance <= int(settings["dhash_max_hamming"])
    if phash_ok and dhash_ok:
        return "phash+dhash"
    if phash_ok:
        return "phash"
    if dhash_ok:
        return "dhash"
    return None


def build_complete_link_groups(pair_records: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Build strict groups where every member pair has an accepted pair record.

    This deliberately avoids transitive closure: a candidate joins a growing
    group only when it is directly similar to EVERY current member.
    """
    neighbours: dict[str, set[str]] = defaultdict(set)
    info: dict[str, dict[str, Any]] = {}
    for pair in pair_records:
        left, right = pair["image_id_a"], pair["image_id_b"]
        neighbours[left].add(right)
        neighbours[right].add(left)
        info.setdefault(left, {
            "relative_path": pair["relative_path_a"],
            "original_folder": pair["original_folder_a"],
            "original_filename": pair["original_filename_a"],
            "sha256": pair["sha256_a"],
            "phash": pair["phash_a"],
            "dhash": pair["dhash_a"],
        })
        info.setdefault(right, {
            "relative_path": pair["relative_path_b"],
            "original_folder": pair["original_folder_b"],
            "original_filename": pair["original_filename_b"],
            "sha256": pair["sha256_b"],
            "phash": pair["phash_b"],
            "dhash": pair["dhash_b"],
        })

    assigned: set[str] = set()
    groups: list[dict[str, Any]] = []
    for seed in sorted(neighbours):
        if seed in assigned:
            continue
        members = [seed]
        for candidate in sorted(neighbours[seed]):
            if candidate in assigned:
                continue
            if all(candidate in neighbours[member] for member in members):
                members.append(candidate)
        assigned.update(members)
        if len(members) < 2:
            continue
        member_set = set(members)
        internal = [
            pair for pair in pair_records
            if pair["image_id_a"] in member_set and pair["image_id_b"] in member_set
        ]
        folders = sorted({info[image_id]["original_folder"] for image_id in members})
        groups.append({
            "image_count": len(members),
            "image_ids": members,
            "original_filenames": [info[image_id]["original_filename"] for image_id in members],
            "relative_paths": [info[image_id]["relative_path"] for image_id in members],
            "original_folders": folders,
            "sha256": [info[image_id]["sha256"] for image_id in members],
            "phash": [info[image_id]["phash"] for image_id in members],
            "dhash": [info[image_id]["dhash"] for image_id in members],
            "cross_folder": len(folders) > 1,
            "detection_methods": sorted({pair["detection_method"] for pair in internal}),
            "exact_group_ids": sorted({
                pair["exact_group_id"] for pair in internal if pair.get("exact_group_id")
            }),
            "member_pair_count": len(internal),
            "phash_hamming_min": min(pair["phash_hamming"] for pair in internal),
            "phash_hamming_max": max(pair["phash_hamming"] for pair in internal),
            "dhash_hamming_min": min(pair["dhash_hamming"] for pair in internal),
            "dhash_hamming_max": max(pair["dhash_hamming"] for pair in internal),
            "phash_similarity_min": min(pair["phash_similarity"] for pair in internal),
            "phash_similarity_max": max(pair["phash_similarity"] for pair in internal),
            "dhash_similarity_min": min(pair["dhash_similarity"] for pair in internal),
            "dhash_similarity_max": max(pair["dhash_similarity"] for pair in internal),
        })
    groups.sort(key=lambda group: group["image_ids"][0])
    return groups


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
    median = float(np.median(values))
    phash = "".join("1" if value > median else "0" for value in values)

    dwidth = int(settings["dhash_width"])
    dheight = int(settings["dhash_height"])
    small = cv2.resize(gray, (dwidth, dheight), interpolation=cv2.INTER_AREA)
    dhash = "".join(
        "1" if small[row, col] > small[row, col - 1] else "0"
        for row in range(dheight)
        for col in range(1, dwidth)
    )
    return phash, dhash


def keep_nearest_rejected(rejected: list[dict[str, Any]], candidate: dict[str, Any], limit: int = 20) -> None:
    rejected.append(candidate)
    rejected.sort(key=lambda item: min(item["phash_hamming"], item["dhash_hamming"]))
    del rejected[limit:]


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
    def bands(value: str, sizes: list[int], namespace: str = "p") -> list[str]:
        """Return LSH band keys. Namespace prevents pHash/dHash key collisions."""
        result = []
        position = 0
        for size in sizes:
            result.append(f"{namespace}{size}:{position}:{value[position:position + size]}")
            position += size
        return result

    def make_pair(
        self,
        left: dict[str, str],
        right: dict[str, str],
        left_hash: dict[str, Any],
        right_hash: dict[str, Any],
        method: str,
    ) -> dict[str, Any]:
        phash_distance = hamming_distance(left_hash["phash"], right_hash["phash"])
        dhash_distance = hamming_distance(left_hash["dhash"], right_hash["dhash"])
        return {
            "group_id": "",
            "image_id_a": left["image_id"],
            "relative_path_a": left["relative_path"],
            "original_folder_a": left["original_folder"],
            "original_filename_a": left["original_filename"],
            "sha256_a": left["sha256"],
            "phash_a": left_hash["phash"],
            "dhash_a": left_hash["dhash"],
            "image_id_b": right["image_id"],
            "relative_path_b": right["relative_path"],
            "original_folder_b": right["original_folder"],
            "original_filename_b": right["original_filename"],
            "sha256_b": right["sha256"],
            "phash_b": right_hash["phash"],
            "dhash_b": right_hash["dhash"],
            "phash_hamming": phash_distance,
            "dhash_hamming": dhash_distance,
            "phash_similarity": similarity_from_hamming(phash_distance),
            "dhash_similarity": similarity_from_hamming(dhash_distance),
            "detection_method": method,
            "cross_folder": left["original_folder"] != right["original_folder"],
            "review_status": "pending_review",
            "exact_group_id": left.get("duplicate_group_id", "") if left.get("duplicate_group_id") == right.get("duplicate_group_id") else "",
        }

    def run(self) -> dict[str, Any]:
        started = time.monotonic()
        rows = self.load_inventory()
        # Incremental runs reuse the persistent cache; full runs recompute so a
        # stale cache can never silently change results.
        cache = self.load_hash_cache() if self.incremental else {}
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

        if pending:
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
        else:
            self.logger.info("Reused all %d perceptual hashes from cache", len(hashes))

        # 1. Exact SHA-256 groups inherited from Step 6 (kept separate).
        exact_groups: dict[str, list[dict[str, str]]] = defaultdict(list)
        for row in rows:
            if row.get("duplicate_group_id"):
                exact_groups[row["duplicate_group_id"]].append(row)
        exact_group_details = [
            {
                "group_id": group_id,
                "review_status": "pending_review",
                "image_count": len(members),
                "image_ids": [row["image_id"] for row in members],
                "original_filenames": [row["original_filename"] for row in members],
                "original_folders": [row["original_folder"] for row in members],
                "relative_paths": [row["relative_path"] for row in members],
                "sha256": [row["sha256"] for row in members],
                "detection_method": "exact_sha256",
            }
            for group_id, members in sorted(exact_groups.items())
        ]

        pairs: list[dict[str, Any]] = []
        seen_pairs: set[tuple[str, str]] = set()
        for members in exact_groups.values():
            for left, right in itertools.combinations(members, 2):
                key = tuple(sorted((left["image_id"], right["image_id"])))
                if key in seen_pairs:
                    continue
                left_hash = hashes.get(left["sha256"], {})
                right_hash = hashes.get(right["sha256"], {})
                # Keep exact-group metadata in the report even when perceptual
                # hashes could not be computed, but never emit a malformed pair row.
                if not left_hash.get("phash") or not right_hash.get("phash"):
                    continue
                seen_pairs.add(key)
                pairs.append(self.make_pair(left, right, left_hash, right_hash, "exact_sha256"))

        # 2. LSH candidate generation, followed by explicit pairwise comparison.
        buckets: dict[str, list[int]] = defaultdict(list)
        skipped_buckets = 0
        for index, row in enumerate(rows):
            entry = hashes.get(row.get("sha256", ""), {})
            if not entry.get("phash"):
                continue
            band_sets = [
                self.bands(entry["phash"], [16] * int(self.settings["lsh_bands"]), "p16"),
                self.bands(entry["phash"], [8] * int(self.settings["lsh_small_bands"]), "p8"),
                self.bands(entry["dhash"], [16] * int(self.settings["lsh_bands"]), "d16"),
            ]
            for band_set in band_sets:
                for band in band_set:
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

        rejected_count = 0
        rejected_examples: list[dict[str, Any]] = []
        for left_index, right_index in candidate_pairs:
            left, right = rows[left_index], rows[right_index]
            left_hash = hashes.get(left.get("sha256", ""), {})
            right_hash = hashes.get(right.get("sha256", ""), {})
            if not left_hash.get("phash") or not right_hash.get("phash"):
                continue
            key = tuple(sorted((left["image_id"], right["image_id"])))
            if key in seen_pairs:
                continue
            phash_distance = hamming_distance(left_hash["phash"], right_hash["phash"])
            dhash_distance = hamming_distance(left_hash["dhash"], right_hash["dhash"])
            if left["sha256"] == right["sha256"]:
                method = "exact_sha256"
            else:
                method = detection_method_for(phash_distance, dhash_distance, self.settings, False)
            if method is None:
                rejected_count += 1
                keep_nearest_rejected(rejected_examples, {
                    "image_id_a": left["image_id"],
                    "image_id_b": right["image_id"],
                    "relative_path_a": left["relative_path"],
                    "relative_path_b": right["relative_path"],
                    "phash_hamming": phash_distance,
                    "dhash_hamming": dhash_distance,
                })
                continue
            seen_pairs.add(key)
            pairs.append(self.make_pair(left, right, left_hash, right_hash, method))

        # 3. Strict complete-link groups; no unrestricted transitive closure.
        strict_groups = build_complete_link_groups(pairs)
        pair_lookup: dict[tuple[str, str], dict[str, Any]] = {}
        for pair in pairs:
            pair_lookup[tuple(sorted((pair["image_id_a"], pair["image_id_b"])))] = pair
        for group_number, group in enumerate(strict_groups, start=1):
            group_id = f"NDUP-{group_number:06d}"
            group["group_id"] = group_id
            group["review_status"] = "pending_review"
            for left, right in itertools.combinations(group["image_ids"], 2):
                pair = pair_lookup.get(tuple(sorted((left, right))))
                if pair is not None:
                    pair["group_id"] = group_id

        involved_images = {pair["image_id_a"] for pair in pairs} | {pair["image_id_b"] for pair in pairs}
        cross_pairs = [pair for pair in pairs if pair["cross_folder"]]
        perceptual_pairs = [pair for pair in pairs if pair["detection_method"] != "exact_sha256"]
        exact_pairs = [pair for pair in pairs if pair["detection_method"] == "exact_sha256"]
        method_counts = Counter(pair["detection_method"] for pair in pairs)
        phash_distances = [pair["phash_hamming"] for pair in pairs]
        dhash_distances = [pair["dhash_hamming"] for pair in pairs]

        report = {
            "report_version": "near-duplicate-report-v2-pairwise",
            "config_version": self.config["config_version"],
            "generated_at": utc_now(),
            "started_at": self.started_at,
            "duration_seconds": round(time.monotonic() - started, 3),
            "dataset_root": str(self.dataset_root),
            "inventory_source": str(self.inventory_csv),
            "policy": {
                "screening_only": True,
                "human_verification_required": True,
                "pair_candidates_are_not_confirmed_duplicates": True,
                "no_automatic_merge": True,
                "no_images_modified": True,
                "folder_names_are_not_ground_truth": True,
                "cross_folder_candidates_require_manual_review": True,
                "exact_sha256_groups_preserved": True,
                "unrestricted_transitive_grouping_used": False,
                "comparison_rule": self.settings.get("comparison_rule", "phash_hamming <= limit OR dhash_hamming <= limit"),
                "ocr_claimed": False,
            },
            "exact_duplicate_groups_from_step6": len(exact_groups),
            "exact_duplicate_groups": exact_group_details,
            "total_candidate_pairs": len(pairs),
            "exact_candidate_pairs": len(exact_pairs),
            "perceptual_candidate_pairs": len(perceptual_pairs),
            "cross_folder_candidate_pairs": len(cross_pairs),
            "cross_folder_candidate_pair_examples": [
                {
                    "image_id_a": pair["image_id_a"],
                    "image_id_b": pair["image_id_b"],
                    "folder_a": pair["original_folder_a"],
                    "folder_b": pair["original_folder_b"],
                    "phash_hamming": pair["phash_hamming"],
                    "dhash_hamming": pair["dhash_hamming"],
                }
                for pair in cross_pairs[:50]
            ],
            "images_in_candidate_pairs": len(involved_images),
            "candidate_group_count": len(strict_groups),
            "images_in_candidate_groups": sum(len(group["image_ids"]) for group in strict_groups),
            "cross_folder_group_count": sum(1 for group in strict_groups if group["cross_folder"]),
            "groups": strict_groups,
            "strict_near_duplicate_groups": strict_groups,
            "largest_candidate_groups": sorted(strict_groups, key=lambda g: g["image_count"], reverse=True)[:20],
            "pairwise_similarity_statistics": {
                "pairs_with_statistics": len(pairs),
                "phash_hamming_min": min(phash_distances) if phash_distances else None,
                "phash_hamming_mean": round(statistics.mean(phash_distances), 3) if phash_distances else None,
                "phash_hamming_max": max(phash_distances) if phash_distances else None,
                "dhash_hamming_min": min(dhash_distances) if dhash_distances else None,
                "dhash_hamming_mean": round(statistics.mean(dhash_distances), 3) if dhash_distances else None,
                "dhash_hamming_max": max(dhash_distances) if dhash_distances else None,
            },
            "detection_method_counts": dict(sorted(method_counts.items())),
            "candidate_pairs_evaluated": len(candidate_pairs),
            "rejected_comparisons": rejected_count,
            "rejected_near_threshold_examples": rejected_examples,
            "skipped_large_buckets": skipped_buckets,
            "new_perceptual_hashes": new_hashes,
            "reused_perceptual_hashes": len(rows) - len(pending),
            "failed_perceptual_hashes": failed_hashes,
            "hashed_images": sum(1 for digest in {row["sha256"] for row in rows} if hashes.get(digest, {}).get("phash")),
        }
        self.write_outputs(pairs, report, hashes)
        self.logger.info(
            "Near-duplicate detection complete: %d pair candidates, %d strict groups",
            len(pairs), len(strict_groups),
        )
        return report

    def write_outputs(
        self,
        pairs: list[dict[str, Any]],
        report: dict[str, Any],
        hashes: dict[str, dict[str, Any]],
    ) -> None:
        self.pipeline_dir.joinpath("exports").mkdir(parents=True, exist_ok=True)
        self.pipeline_dir.joinpath("reports").mkdir(parents=True, exist_ok=True)
        with self.candidates_csv.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=PAIR_FIELDS, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(pairs)
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
                "pair_candidates": report["total_candidate_pairs"],
                "strict_groups": report["candidate_group_count"],
                "largest_group": max((g["image_count"] for g in report["groups"]), default=0),
                "duration_seconds": report["duration_seconds"],
            }, ensure_ascii=False) + "\n")


def render_markdown(report: dict[str, Any]) -> str:
    lines = [
        "# RasaLanka near-duplicate candidate report (pairwise)",
        "",
        f"- Generated: {report['generated_at']}",
        f"- Duration: {report['duration_seconds']} s",
        f"- Exact SHA-256 groups from Step 6: {report['exact_duplicate_groups_from_step6']}",
        f"- Exact candidate pairs: {report['exact_candidate_pairs']}",
        f"- Perceptual candidate pairs: {report['perceptual_candidate_pairs']}",
        f"- All candidate pairs: {report['total_candidate_pairs']}",
        f"- Cross-folder candidate pairs: {report['cross_folder_candidate_pairs']}",
        f"- Images involved in candidate pairs: {report['images_in_candidate_pairs']}",
        f"- Strict complete-link groups: {report['candidate_group_count']}",
        f"- Images in strict groups: {report['images_in_candidate_groups']}",
        f"- Largest strict group: {max((g['image_count'] for g in report['groups']), default=0)}",
        f"- Candidate pairs evaluated: {report['candidate_pairs_evaluated']}",
        f"- Rejected comparisons: {report['rejected_comparisons']}",
        f"- Skipped oversized buckets: {report['skipped_large_buckets']}",
        f"- Hashes reused / computed / failed: {report['reused_perceptual_hashes']} / "
        f"{report['new_perceptual_hashes']} / {report['failed_perceptual_hashes']}",
        "",
        "Comparison rule: " + report["policy"]["comparison_rule"],
        "",
        "Every candidate is `pending_review`. Pair candidates are **not confirmed duplicates**. "
        "Unrestricted transitive grouping is not used; strict groups require every member pair "
        "to satisfy the threshold. Human visual verification is required before any action.",
        "",
        "## Detection methods",
        "",
        "| Method | Pairs |",
        "| --- | ---: |",
    ]
    for method, count in report["detection_method_counts"].items():
        lines.append(f"| {method} | {count} |")
    stats = report["pairwise_similarity_statistics"]
    lines += [
        "",
        "## Pairwise similarity statistics",
        "",
        f"- pHash Hamming: min {stats['phash_hamming_min']}, mean {stats['phash_hamming_mean']}, max {stats['phash_hamming_max']}",
        f"- dHash Hamming: min {stats['dhash_hamming_min']}, mean {stats['dhash_hamming_mean']}, max {stats['dhash_hamming_max']}",
        "",
        "## Largest strict groups",
        "",
        "| Group | Images | Folders | Methods | Min pHash sim | Min dHash sim |",
        "| --- | ---: | --- | --- | ---: | ---: |",
    ]
    for group in report["largest_candidate_groups"]:
        lines.append(
            f"| {group['group_id']} | {group['image_count']} | "
            f"{', '.join(group['original_folders'][:3])} | "
            f"{', '.join(group['detection_methods'])} | "
            f"{group['phash_similarity_min']} | {group['dhash_similarity_min']} |"
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
        "exact_groups_preserved": report["exact_duplicate_groups_from_step6"],
        "pair_candidates": report["total_candidate_pairs"],
        "perceptual_pairs": report["perceptual_candidate_pairs"],
        "cross_folder_pairs": report["cross_folder_candidate_pairs"],
        "strict_groups": report["candidate_group_count"],
        "largest_group": max((g["image_count"] for g in report["groups"]), default=0),
        "duration_seconds": report["duration_seconds"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
