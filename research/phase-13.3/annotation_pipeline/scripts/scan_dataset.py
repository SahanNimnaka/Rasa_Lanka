"""Scan the original RasaLanka dataset and build a master image inventory.

This stage is strictly image discovery, identification and metadata extraction.
It does NOT classify ingredients, generate annotations, or modify source files.
All outputs are written inside the annotation_pipeline directory.

Usage:
    python scan_dataset.py --full
    python scan_dataset.py --incremental
    python scan_dataset.py --full --dataset-root PATH --pipeline-dir PATH
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import logging
import os
import shutil
import sys
import time
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from PIL import Image, UnidentifiedImageError

SCRIPT_DIR = Path(__file__).resolve().parent
PIPELINE_DIR = SCRIPT_DIR.parent
DEFAULT_DATASET_ROOT = PIPELINE_DIR.parent / "collected_dataset"

SUPPORTED_EXTENSIONS = {
    ".jpg",
    ".jpeg",
    ".png",
    ".webp",
    ".bmp",
    ".tif",
    ".tiff",
}

INVENTORY_FIELDS = [
    "image_id",
    "batch_id",
    "original_folder",
    "original_filename",
    "relative_path",
    "file_extension",
    "file_size_bytes",
    "image_width",
    "image_height",
    "image_mode",
    "image_format",
    "sha256",
    "folder_class_proposal",
    "mapping_status",
    "scan_status",
    "scan_timestamp",
    "first_seen_timestamp",
    "error_message",
    "duplicate_group_id",
    "duplicate_of_image_id",
    "is_exact_duplicate",
]

HASH_CHUNK_BYTES = 1024 * 1024


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def sha256_file(path: Path) -> str:
    """Stream a file through SHA-256 without loading it into memory."""
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(HASH_CHUNK_BYTES), b""):
            digest.update(chunk)
    return digest.hexdigest()


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


def atomic_write_csv(path: Path, rows: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix(path.suffix + ".tmp")
    with temp.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=INVENTORY_FIELDS, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow(row)
    os.replace(temp, path)


def build_logger(log_path: Path | None) -> logging.Logger:
    logger = logging.getLogger(f"rasalanka.scan.{id(log_path)}")
    logger.setLevel(logging.INFO)
    logger.handlers.clear()
    logger.propagate = False
    formatter = logging.Formatter("%(asctime)s | %(levelname)s | %(message)s")
    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(formatter)
    logger.addHandler(stream)
    if log_path is not None:
        log_path.parent.mkdir(parents=True, exist_ok=True)
        file_handler = logging.FileHandler(log_path, mode="a", encoding="utf-8")
        file_handler.setFormatter(formatter)
        logger.addHandler(file_handler)
    return logger


class DatasetScanner:
    """Read-only dataset scanner with incremental state and stable IDs."""

    def __init__(
        self,
        dataset_root: Path,
        pipeline_dir: Path,
        mode: str = "incremental",
        progress_interval: int = 500,
        logger: logging.Logger | None = None,
        mapping_path: Path | None = None,
    ) -> None:
        if mode not in {"full", "incremental"}:
            raise ValueError("mode must be 'full' or 'incremental'")
        self.dataset_root = dataset_root.resolve()
        self.pipeline_dir = pipeline_dir.resolve()
        self.mode = mode
        self.progress_interval = max(1, progress_interval)
        self.logger = logger or build_logger(self.pipeline_dir / "logs" / "dataset_scan.log")

        self.export_dir = self.pipeline_dir / "exports"
        self.report_dir = self.pipeline_dir / "reports"
        self.config_dir = self.pipeline_dir / "config"
        self.inventory_json = self.export_dir / "master_image_inventory.json"
        self.inventory_csv = self.export_dir / "master_image_inventory.csv"
        self.report_json = self.report_dir / "dataset_inventory_report.json"
        self.report_md = self.report_dir / "dataset_inventory_report.md"
        self.history_path = self.report_dir / "dataset_scan_history.jsonl"
        self.registry_path = self.config_dir / "image_id_registry.json"
        self.mapping_path = mapping_path or (self.config_dir / "folder_class_mapping.json")
        self.scan_started = utc_now()
        self.batch_id = "BATCH-" + self.scan_started.replace(":", "").replace("-", "")

        if not self.dataset_root.is_dir():
            raise FileNotFoundError(f"Dataset root not found: {self.dataset_root}")

    # ------------------------------------------------------------------
    # State loading
    # ------------------------------------------------------------------
    def load_prior_inventory(self) -> list[dict[str, Any]]:
        payload = load_json(self.inventory_json, {})
        images = payload.get("images", []) if isinstance(payload, dict) else []
        return [row for row in images if isinstance(row, dict)] if isinstance(images, list) else []

    def load_registry(self, prior: list[dict[str, Any]]) -> dict[str, Any]:
        registry = load_json(
            self.registry_path,
            {"registry_version": "image-id-registry-v1", "next_sequence": 1, "path_to_id": {}, "retired_paths": []},
        )
        if not isinstance(registry, dict):
            registry = {}
        registry.setdefault("registry_version", "image-id-registry-v1")
        registry.setdefault("path_to_id", {})
        registry.setdefault("retired_paths", [])
        registry["path_to_id"] = dict(registry["path_to_id"])

        # Recover IDs from a prior inventory if the registry is missing or partial.
        max_sequence = 0
        for record in prior:
            rel = record.get("relative_path")
            image_id = record.get("image_id")
            if rel and image_id and rel not in registry["path_to_id"]:
                registry["path_to_id"][rel] = image_id
            if isinstance(image_id, str) and image_id.startswith("IMG-"):
                try:
                    max_sequence = max(max_sequence, int(image_id[4:]))
                except ValueError:
                    pass
        for image_id in registry["path_to_id"].values():
            if isinstance(image_id, str) and image_id.startswith("IMG-"):
                try:
                    max_sequence = max(max_sequence, int(image_id[4:]))
                except ValueError:
                    pass
        registry["next_sequence"] = max(int(registry.get("next_sequence", 1)), max_sequence + 1)
        return registry

    def load_mapping(self) -> dict[str, dict[str, Any]]:
        payload = load_json(self.mapping_path, {})
        if not isinstance(payload, dict):
            return {}
        result: dict[str, dict[str, Any]] = {}
        for entry in payload.get("mappings", []):
            if isinstance(entry, dict) and entry.get("folder_name"):
                result[entry["folder_name"]] = {
                    "folder_class_proposal": entry.get("proposed_class_id"),
                    "mapping_status": entry.get("mapping_status", "unmapped"),
                }
        return result

    def assign_image_id(
        self,
        relative_path: str,
        registry: dict[str, Any],
        used_ids: set[str],
        id_owner: dict[str, str],
    ) -> str:
        existing = registry["path_to_id"].get(relative_path)
        if existing and id_owner.get(existing, relative_path) == relative_path:
            return existing
        while True:
            sequence = int(registry["next_sequence"])
            candidate = f"IMG-{sequence:06d}"
            registry["next_sequence"] = sequence + 1
            if candidate not in used_ids:
                registry["path_to_id"][relative_path] = candidate
                id_owner[candidate] = relative_path
                return candidate

    # ------------------------------------------------------------------
    # Discovery
    # ------------------------------------------------------------------
    def discover(self) -> tuple[list[Path], list[str], list[dict[str, Any]], list[str]]:
        images: list[Path] = []
        non_images: list[dict[str, Any]] = []
        empty_folders: list[str] = []
        top_level_folders: list[str] = []

        for child in sorted(self.dataset_root.iterdir(), key=lambda p: p.name):
            if child.is_dir():
                top_level_folders.append(child.name)

        for dirpath, dirnames, filenames in os.walk(self.dataset_root):
            dirnames.sort()
            filenames.sort()
            current = Path(dirpath)
            if not dirnames and not filenames:
                empty_folders.append(current.relative_to(self.dataset_root).as_posix())
            for filename in filenames:
                path = current / filename
                if not path.is_file():
                    continue
                if path.suffix.lower() in SUPPORTED_EXTENSIONS:
                    images.append(path)
                else:
                    non_images.append(
                        {
                            "relative_path": path.relative_to(self.dataset_root).as_posix(),
                            "file_extension": path.suffix.lower(),
                            "file_size_bytes": path.stat().st_size,
                        }
                    )
        images.sort(key=lambda p: p.relative_to(self.dataset_root).as_posix())
        return images, empty_folders, non_images, top_level_folders

    def inspect_image(self, path: Path) -> dict[str, Any]:
        metadata: dict[str, Any] = {
            "image_width": None,
            "image_height": None,
            "image_mode": None,
            "image_format": None,
            "scan_status": "success",
            "error_message": None,
        }
        try:
            with Image.open(path) as image:
                metadata["image_width"] = image.width
                metadata["image_height"] = image.height
                metadata["image_mode"] = image.mode
                metadata["image_format"] = image.format
                image.verify()
        except (UnidentifiedImageError, OSError, ValueError, SyntaxError) as exc:
            metadata["scan_status"] = "failed"
            metadata["error_message"] = f"{type(exc).__name__}: {exc}"
        except Exception as exc:  # defensive: a single odd file must not abort the scan
            metadata["scan_status"] = "failed"
            metadata["error_message"] = f"{type(exc).__name__}: {exc}"
        return metadata

    # ------------------------------------------------------------------
    # Main scan
    # ------------------------------------------------------------------
    def scan(self) -> dict[str, Any]:
        self.logger.info("Starting %s scan of %s", self.mode, self.dataset_root)
        prior = self.load_prior_inventory()
        prior_by_path = {row.get("relative_path"): row for row in prior if row.get("relative_path")}
        registry = self.load_registry(prior)
        mapping = self.load_mapping()
        used_ids = set(registry["path_to_id"].values())
        id_owner = {image_id: path for path, image_id in registry["path_to_id"].items()}

        if self.mode == "full":
            self.backup_previous_outputs()

        images, empty_folders, non_images, top_level_folders = self.discover()
        discovered_paths = {p.relative_to(self.dataset_root).as_posix() for p in images}
        timestamp = utc_now()

        records: list[dict[str, Any]] = []
        new_files: list[str] = []
        changed_files: list[dict[str, Any]] = []
        started = time.monotonic()

        for index, path in enumerate(images, start=1):
            relative_path = path.relative_to(self.dataset_root).as_posix()
            prior_record = prior_by_path.get(relative_path)
            file_size = path.stat().st_size
            digest = sha256_file(path)

            cached = (
                self.mode == "incremental"
                and prior_record is not None
                and prior_record.get("sha256") == digest
                and prior_record.get("file_size_bytes") == file_size
                and prior_record.get("scan_status") in {"success", "success_cached"}
            )

            if cached:
                record = dict(prior_record)
                record["scan_status"] = "success_cached"
                record["scan_timestamp"] = timestamp
                record["error_message"] = None
            else:
                if prior_record is None:
                    new_files.append(relative_path)
                elif prior_record.get("sha256") != digest or prior_record.get("file_size_bytes") != file_size:
                    changed_files.append(
                        {
                            "image_id": prior_record.get("image_id"),
                            "relative_path": relative_path,
                            "old_sha256": prior_record.get("sha256"),
                            "new_sha256": digest,
                            "old_size_bytes": prior_record.get("file_size_bytes"),
                            "new_size_bytes": file_size,
                        }
                    )
                image_id = self.assign_image_id(relative_path, registry, used_ids, id_owner)
                used_ids.add(image_id)
                id_owner[image_id] = relative_path
                metadata = self.inspect_image(path)
                folder = relative_path.split("/", 1)[0]
                proposal = mapping.get(folder, {})
                record = {
                    "image_id": image_id,
                    "batch_id": self.batch_id,
                    "original_folder": folder,
                    "original_filename": path.name,
                    "relative_path": relative_path,
                    "file_extension": path.suffix.lower(),
                    "file_size_bytes": file_size,
                    "sha256": digest,
                    "folder_class_proposal": proposal.get("folder_class_proposal"),
                    "mapping_status": proposal.get("mapping_status", "unmapped"),
                    "scan_status": metadata["scan_status"],
                    "scan_timestamp": timestamp,
                    "first_seen_timestamp": prior_record.get("first_seen_timestamp", timestamp)
                    if prior_record
                    else timestamp,
                    "error_message": metadata["error_message"],
                    "duplicate_group_id": None,
                    "duplicate_of_image_id": None,
                    "is_exact_duplicate": False,
                }
                record.update(
                    {
                        "image_width": metadata["image_width"],
                        "image_height": metadata["image_height"],
                        "image_mode": metadata["image_mode"],
                        "image_format": metadata["image_format"],
                    }
                )

            records.append(record)
            if index % self.progress_interval == 0 or index == len(images):
                elapsed = max(time.monotonic() - started, 0.001)
                self.logger.info(
                    "Progress %d/%d (%.1f images/s)", index, len(images), index / elapsed
                )

        self.apply_duplicate_groups(records)

        missing_files = []
        for relative_path, old in prior_by_path.items():
            if relative_path not in discovered_paths:
                missing_files.append(
                    {
                        "image_id": old.get("image_id"),
                        "relative_path": relative_path,
                        "sha256": old.get("sha256"),
                    }
                )
                if relative_path in registry["path_to_id"] and relative_path not in registry["retired_paths"]:
                    registry["retired_paths"].append(relative_path)
        missing_files.sort(key=lambda item: item["relative_path"])

        report = self.build_report(
            records,
            top_level_folders,
            images,
            empty_folders,
            non_images,
            missing_files,
            new_files,
            changed_files,
            started,
        )
        self.write_outputs(records, report, registry)
        self.logger.info(
            "Scan complete: %d discovered, %d OK, %d failed, %d duplicates",
            report["total_discovered_images"],
            report["total_successfully_scanned"],
            report["total_failed_images"],
            report["total_exact_duplicate_images"],
        )
        return report

    def apply_duplicate_groups(self, records: list[dict[str, Any]]) -> None:
        by_hash: dict[str, list[dict[str, Any]]] = {}
        for record in records:
            digest = record.get("sha256")
            if digest:
                by_hash.setdefault(digest, []).append(record)
        group_index = 0
        for digest in sorted(by_hash):
            group = sorted(by_hash[digest], key=lambda r: r["image_id"])
            if len(group) < 2:
                continue
            group_index += 1
            group_id = f"DUP-{group_index:06d}"
            canonical = group[0]
            for record in group:
                record["duplicate_group_id"] = group_id
                record["is_exact_duplicate"] = record is not canonical
                record["duplicate_of_image_id"] = None if record is canonical else canonical["image_id"]

    def backup_previous_outputs(self) -> None:
        candidates = [
            self.inventory_json,
            self.inventory_csv,
            self.report_json,
            self.report_md,
            self.registry_path,
        ]
        existing = [path for path in candidates if path.is_file()]
        if not existing:
            return
        stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        backup_dir = self.export_dir / "backups" / stamp
        backup_dir.mkdir(parents=True, exist_ok=False)
        for path in existing:
            shutil.copy2(path, backup_dir / path.name)
        self.logger.info("Backed up %d prior files to %s", len(existing), backup_dir)

    def build_report(
        self,
        records: list[dict[str, Any]],
        top_level_folders: list[str],
        images: list[Path],
        empty_folders: list[str],
        non_images: list[dict[str, Any]],
        missing_files: list[dict[str, Any]],
        new_files: list[str],
        changed_files: list[dict[str, Any]],
        started: float,
    ) -> dict[str, Any]:
        success = sum(1 for r in records if r["scan_status"] in {"success", "success_cached"})
        failed = sum(1 for r in records if r["scan_status"] == "failed")
        by_hash: dict[str, list[dict[str, Any]]] = {}
        for record in records:
            digest = record.get("sha256")
            if digest:
                by_hash.setdefault(digest, []).append(record)
        hash_counts = {digest: len(members) for digest, members in by_hash.items()}
        duplicate_groups = []
        group_index = 0
        for digest in sorted(by_hash):
            members = by_hash[digest]
            if len(members) < 2:
                continue
            group_index += 1
            duplicate_groups.append(
                {
                    "duplicate_group_id": f"DUP-{group_index:06d}",
                    "sha256": digest,
                    "image_count": len(members),
                    "canonical_image_id": next(
                        (r["image_id"] for r in members if not r["is_exact_duplicate"]), None
                    ),
                    "image_ids": [r["image_id"] for r in members],
                    "relative_paths": [r["relative_path"] for r in members],
                }
            )

        widths = [r["image_width"] for r in records if isinstance(r.get("image_width"), int)]
        heights = [r["image_height"] for r in records if isinstance(r.get("image_height"), int)]
        dimensions = Counter(
            (r["image_width"], r["image_height"])
            for r in records
            if isinstance(r.get("image_width"), int) and isinstance(r.get("image_height"), int)
        )

        folder_counts = {folder: 0 for folder in top_level_folders}
        for record in records:
            folder_counts[record["original_folder"]] = folder_counts.get(record["original_folder"], 0) + 1

        return {
            "scan_mode": self.mode,
            "batch_id": self.batch_id,
            "scan_started_at": self.scan_started,
            "scan_finished_at": utc_now(),
            "duration_seconds": round(time.monotonic() - started, 3),
            "dataset_root": str(self.dataset_root),
            "policy": {
                "classification_performed": False,
                "annotations_generated": False,
                "folder_names_are_not_ground_truth": True,
                "source_images_modified": False,
            },
            "total_ingredient_folders": len(top_level_folders),
            "total_discovered_images": len(records),
            "total_successfully_scanned": success,
            "total_failed_images": failed,
            "total_exact_duplicate_images": sum(max(0, count - 1) for count in hash_counts.values()),
            "total_duplicate_groups": len(duplicate_groups),
            "total_unique_image_hashes": len(hash_counts),
            "total_empty_folders": len(empty_folders),
            "total_non_image_files": len(non_images),
            "image_counts_per_folder": dict(sorted(folder_counts.items())),
            "image_counts_by_extension": dict(
                sorted(Counter(r["file_extension"] for r in records).items())
            ),
            "image_dimensions_summary": {
                "images_with_dimensions": len(widths),
                "min_width": min(widths) if widths else None,
                "max_width": max(widths) if widths else None,
                "mean_width": round(sum(widths) / len(widths), 2) if widths else None,
                "min_height": min(heights) if heights else None,
                "max_height": max(heights) if heights else None,
                "mean_height": round(sum(heights) / len(heights), 2) if heights else None,
                "count_min_side_lt_224": sum(
                    1
                    for r in records
                    if isinstance(r.get("image_width"), int)
                    and isinstance(r.get("image_height"), int)
                    and min(r["image_width"], r["image_height"]) < 224
                ),
                "top_dimensions": [
                    {"width": width, "height": height, "count": count}
                    for (width, height), count in dimensions.most_common(10)
                ],
            },
            "missing_previously_indexed_files": missing_files,
            "newly_discovered_files": sorted(new_files),
            "changed_files": sorted(changed_files, key=lambda item: item["relative_path"]),
            "empty_folders": empty_folders,
            "non_image_files": non_images,
            "failed_images": [
                {"relative_path": r["relative_path"], "error": r["error_message"]}
                for r in records
                if r["scan_status"] == "failed"
            ],
            "duplicate_groups": duplicate_groups,
        }

    def write_outputs(
        self,
        records: list[dict[str, Any]],
        report: dict[str, Any],
        registry: dict[str, Any],
    ) -> None:
        self.export_dir.mkdir(parents=True, exist_ok=True)
        self.report_dir.mkdir(parents=True, exist_ok=True)
        self.config_dir.mkdir(parents=True, exist_ok=True)

        # Deterministic CSV column order.
        ordered = [{field: row.get(field) for field in INVENTORY_FIELDS} for row in records]
        atomic_write_csv(self.inventory_csv, ordered)
        atomic_write_json(
            self.inventory_json,
            {
                "inventory_version": "master-image-inventory-v1",
                "schema_version": "machine-annotation-v1",
                "batch_id": self.batch_id,
                "generated_at": utc_now(),
                "scan_mode": self.mode,
                "record_count": len(ordered),
                "images": ordered,
            },
        )
        atomic_write_json(self.report_json, report)
        self.report_md.write_text(render_markdown(report), encoding="utf-8", newline="\n")
        atomic_write_json(self.registry_path, registry)
        with self.history_path.open("a", encoding="utf-8", newline="\n") as handle:
            handle.write(
                json.dumps(
                    {
                        "scan_started_at": self.scan_started,
                        "mode": self.mode,
                        "batch_id": self.batch_id,
                        "discovered": report["total_discovered_images"],
                        "successful": report["total_successfully_scanned"],
                        "failed": report["total_failed_images"],
                        "new": len(report["newly_discovered_files"]),
                        "changed": len(report["changed_files"]),
                        "missing": len(report["missing_previously_indexed_files"]),
                    },
                    ensure_ascii=False,
                )
                + "\n"
            )


def render_markdown(report: dict[str, Any]) -> str:
    lines = [
        "# RasaLanka dataset inventory report",
        "",
        f"- Scan mode: `{report['scan_mode']}`",
        f"- Batch: `{report['batch_id']}`",
        f"- Started: {report['scan_started_at']}",
        f"- Finished: {report['scan_finished_at']}",
        f"- Duration: {report['duration_seconds']} s",
        f"- Dataset root: `{report['dataset_root']}`",
        "",
        "## Totals",
        "",
        "| Metric | Count |",
        "| --- | ---: |",
    ]
    metrics = [
        ("Ingredient folders", "total_ingredient_folders"),
        ("Discovered images", "total_discovered_images"),
        ("Successfully scanned", "total_successfully_scanned"),
        ("Failed images", "total_failed_images"),
        ("Exact duplicate images (extra copies)", "total_exact_duplicate_images"),
        ("Duplicate groups", "total_duplicate_groups"),
        ("Unique SHA-256 hashes", "total_unique_image_hashes"),
        ("Empty folders", "total_empty_folders"),
        ("Non-image files", "total_non_image_files"),
        ("Newly discovered files", None),
        ("Changed files", None),
        ("Missing previously indexed files", None),
    ]
    list_keys = {
        "Newly discovered files": "newly_discovered_files",
        "Changed files": "changed_files",
        "Missing previously indexed files": "missing_previously_indexed_files",
    }
    for label, key in metrics:
        count = len(report[list_keys[label]]) if label in list_keys else report[key]
        lines.append(f"| {label} | {count} |")

    lines += ["", "## Image counts per folder", "", "| Folder | Images |", "| --- | ---: |"]
    for folder, count in report["image_counts_per_folder"].items():
        lines.append(f"| {folder} | {count} |")

    lines += ["", "## Image counts by extension", "", "| Extension | Images |", "| --- | ---: |"]
    for ext, count in report["image_counts_by_extension"].items():
        lines.append(f"| {ext} | {count} |")

    dims = report["image_dimensions_summary"]
    lines += [
        "",
        "## Dimensions summary",
        "",
        f"- Images with dimensions: {dims['images_with_dimensions']}",
        f"- Width: min {dims['min_width']}, max {dims['max_width']}, mean {dims['mean_width']}",
        f"- Height: min {dims['min_height']}, max {dims['max_height']}, mean {dims['mean_height']}",
        f"- Images with min side < 224 px: {dims['count_min_side_lt_224']}",
        "",
        "## Failed images",
        "",
    ]
    if report["failed_images"]:
        for item in report["failed_images"][:50]:
            lines.append(f"- `{item['relative_path']}` — {item['error']}")
        if len(report["failed_images"]) > 50:
            lines.append(f"- … and {len(report['failed_images']) - 50} more")
    else:
        lines.append("- None")

    lines += ["", "## Incremental changes", ""]
    for label, key in [
        ("Newly discovered", "newly_discovered_files"),
        ("Changed", "changed_files"),
        ("Missing", "missing_previously_indexed_files"),
    ]:
        items = report[key]
        lines.append(f"- {label}: {len(items)}")
        for item in items[:20]:
            lines.append(f"  - `{item}`" if isinstance(item, str) else f"  - `{item.get('relative_path')}`")
        if len(items) > 20:
            lines.append(f"  - … and {len(items) - 20} more")

    lines += [
        "",
        "## Integrity note",
        "",
        "This scan is read-only. No images were renamed, moved, deleted or modified. "
        "Folder names are candidate labels only and are not verified ingredient ground truth. "
        "No ingredient classification or final annotations were produced.",
        "",
    ]
    return "\n".join(lines)


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--full", action="store_true", help="rescan every image; back up prior outputs")
    group.add_argument("--incremental", action="store_true", help="skip unchanged files by SHA-256 (default)")
    parser.add_argument("--dataset-root", type=Path, default=DEFAULT_DATASET_ROOT)
    parser.add_argument("--pipeline-dir", type=Path, default=PIPELINE_DIR)
    parser.add_argument("--progress-interval", type=int, default=500)
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv)
    mode = "full" if args.full else "incremental"
    scanner = DatasetScanner(
        dataset_root=args.dataset_root,
        pipeline_dir=args.pipeline_dir,
        mode=mode,
        progress_interval=args.progress_interval,
    )
    try:
        report = scanner.scan()
    except Exception as exc:
        scanner.logger.exception("Scan aborted: %s", exc)
        return 1
    print(json.dumps(
        {
            "mode": report["scan_mode"],
            "discovered": report["total_discovered_images"],
            "successful": report["total_successfully_scanned"],
            "failed": report["total_failed_images"],
            "duplicates": report["total_exact_duplicate_images"],
            "unique_hashes": report["total_unique_image_hashes"],
            "duration_seconds": report["duration_seconds"],
        },
        indent=2,
    ))
    return 0


if __name__ == "__main__":
    sys.exit(main())
