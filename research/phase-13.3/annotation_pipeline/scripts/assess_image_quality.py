"""Step 7: machine-generated image quality screening for the RasaLanka dataset.

Read-only. Each image in the Step 6 master inventory is assessed individually for
resolution, blur, integrity and rough visual-suitability heuristics. Nothing is
deleted, renamed, moved or classified. Flags are screening candidates only.

Usage:
    python assess_image_quality.py --workers 4
    python assess_image_quality.py --incremental --workers 4
"""

from __future__ import annotations

import argparse
import csv
import json
import logging
import os
import statistics
import sys
import time
from collections import Counter
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import cv2
import numpy as np
from PIL import Image, UnidentifiedImageError

SCRIPT_DIR = Path(__file__).resolve().parent
PIPELINE_DIR = SCRIPT_DIR.parent
DEFAULT_DATASET_ROOT = PIPELINE_DIR.parent / "collected_dataset"
CONFIG_PATH = PIPELINE_DIR / "config" / "quality_assessment_config.json"
INVENTORY_CSV = PIPELINE_DIR / "exports" / "master_image_inventory.csv"
RESULTS_CSV = PIPELINE_DIR / "exports" / "image_quality_results.csv"
REPORT_JSON = PIPELINE_DIR / "reports" / "image_quality_report.json"
REPORT_MD = PIPELINE_DIR / "reports" / "image_quality_report.md"
CACHE_PATH = PIPELINE_DIR / "exports" / "image_quality_cache.json"
HISTORY_PATH = PIPELINE_DIR / "reports" / "quality_assessment_history.jsonl"

RESULT_FIELDS = [
    "image_id",
    "relative_path",
    "original_folder",
    "original_filename",
    "sha256",
    "width",
    "height",
    "min_side",
    "max_side",
    "pixel_count",
    "image_mode",
    "image_format",
    "file_size_bytes",
    "bytes_per_pixel",
    "laplacian_variance",
    "blur_severity",
    "edge_density",
    "saturation_mean",
    "corner_contrast",
    "text_row_fraction",
    "flags",
    "manual_visual_review_required",
    "scan_status",
    "error_message",
    "assessed_at",
    "assessment_version",
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
    logger = logging.getLogger("rasalanka.quality")
    logger.setLevel(logging.INFO)
    logger.handlers.clear()
    logger.propagate = False
    formatter = logging.Formatter("%(asctime)s | %(levelname)s | %(message)s")
    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(formatter)
    logger.addHandler(stream)
    return logger


class QualityAssessor:
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
        self.workers = max(1, workers)
        self.incremental = incremental
        self.logger = logger or build_logger()
        self.inventory_csv = inventory_csv or (self.pipeline_dir / "exports" / "master_image_inventory.csv")
        self.results_csv = self.pipeline_dir / "exports" / "image_quality_results.csv"
        self.report_json = self.pipeline_dir / "reports" / "image_quality_report.json"
        self.report_md = self.pipeline_dir / "reports" / "image_quality_report.md"
        self.cache_path = self.pipeline_dir / "exports" / "image_quality_cache.json"
        self.history_path = self.pipeline_dir / "reports" / "quality_assessment_history.jsonl"
        self.started_at = utc_now()

    def load_inventory(self) -> list[dict[str, str]]:
        if not self.inventory_csv.is_file():
            raise FileNotFoundError(f"Master inventory not found: {self.inventory_csv}")
        with self.inventory_csv.open(encoding="utf-8", newline="") as handle:
            return list(csv.DictReader(handle))

    def load_cache(self) -> dict[str, dict[str, Any]]:
        payload = load_json(self.cache_path, {})
        if not isinstance(payload, dict) or payload.get("config_version") != self.config["config_version"]:
            return {}
        cache = payload.get("records", {})
        return cache if isinstance(cache, dict) else {}

    def classify_blur(self, variance: float | None) -> str:
        if variance is None:
            return "unavailable"
        blur = self.config["blur"]
        if variance < blur["severe_max"]:
            return "severe"
        if variance < blur["probable_max"]:
            return "probable"
        if variance < blur["possible_max"]:
            return "possible"
        return "clear"

    def assess_one(self, row: dict[str, str]) -> dict[str, Any]:
        image_id = row.get("image_id", "")
        relative_path = row.get("relative_path", "")
        sha256 = row.get("sha256", "")
        result: dict[str, Any] = {
            "image_id": image_id,
            "relative_path": relative_path,
            "original_folder": row.get("original_folder", ""),
            "original_filename": row.get("original_filename", ""),
            "sha256": sha256,
            "width": None,
            "height": None,
            "min_side": None,
            "max_side": None,
            "pixel_count": None,
            "image_mode": None,
            "image_format": None,
            "file_size_bytes": row.get("file_size_bytes"),
            "bytes_per_pixel": None,
            "laplacian_variance": None,
            "blur_severity": "unavailable",
            "edge_density": None,
            "saturation_mean": None,
            "corner_contrast": None,
            "text_row_fraction": None,
            "flags": [],
            "manual_visual_review_required": False,
            "scan_status": "failed",
            "error_message": None,
            "assessed_at": utc_now(),
            "assessment_version": self.config["config_version"],
        }
        flags: list[str] = []
        path = self.dataset_root / relative_path
        if not path.is_file():
            flags.append("missing_source_file")
            flags.append("manual_visual_review_required")
            result["flags"] = flags
            result["manual_visual_review_required"] = True
            result["error_message"] = "source file missing"
            return result

        rgb: np.ndarray | None = None
        decoded = False
        try:
            with Image.open(path) as image:
                result["image_mode"] = image.mode
                result["image_format"] = image.format
                result["width"], result["height"] = image.size
                try:
                    image.verify()
                except Exception as exc:
                    flags.append("corrupted_image")
                    result["error_message"] = f"verify failed: {type(exc).__name__}: {exc}"
            with Image.open(path) as image:
                rgb = np.asarray(image.convert("RGB"))
            decoded = True
        except (UnidentifiedImageError, OSError, ValueError, SyntaxError) as exc:
            if "corrupted_image" not in flags:
                flags.append("corrupted_image")
            result["error_message"] = f"{type(exc).__name__}: {exc}"
        except Exception as exc:
            if "corrupted_image" not in flags:
                flags.append("corrupted_image")
            result["error_message"] = f"{type(exc).__name__}: {exc}"

        if result["width"] is not None and result["height"] is not None:
            width, height = int(result["width"]), int(result["height"])
            result["min_side"] = min(width, height)
            result["max_side"] = max(width, height)
            result["pixel_count"] = width * height
            expected_width = row.get("image_width", "")
            expected_height = row.get("image_height", "")
            if width <= 0 or height <= 0 or (expected_width and int(expected_width) != width) or (
                expected_height and int(expected_height) != height
            ):
                flags.append("invalid_dimensions")
            resolution = self.config["resolution"]
            if result["min_side"] < resolution["very_low_min_side"]:
                flags.append("very_low_resolution")
            elif result["min_side"] < resolution["low_min_side"]:
                flags.append("low_resolution")
            if result["pixel_count"] < resolution["unusually_small_pixels"]:
                flags.append("unusually_small_dimensions")
            if result["pixel_count"] > resolution["unusually_large_pixels"]:
                flags.append("unusually_large_dimensions")
            try:
                result["bytes_per_pixel"] = round(
                    int(row.get("file_size_bytes") or path.stat().st_size) / result["pixel_count"], 5
                )
            except (ValueError, ZeroDivisionError):
                result["bytes_per_pixel"] = None
            if (
                result["bytes_per_pixel"] is not None
                and result["bytes_per_pixel"] < self.config["compression"]["highly_compressed_bytes_per_pixel"]
            ):
                flags.append("highly_compressed")
        if result["image_mode"] and result["image_mode"] not in self.config["integrity"]["expected_modes"]:
            flags.append("unexpected_mode")

        if decoded and rgb is not None:
            try:
                gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
                if gray.size < 9:
                    raise ValueError("image too small for gradient analysis")
                kernel = int(self.config["blur"]["kernel"])
                result["laplacian_variance"] = round(float(cv2.Laplacian(gray, cv2.CV_64F, ksize=kernel).var()), 3)
                result["blur_severity"] = self.classify_blur(result["laplacian_variance"])
                if result["blur_severity"] in {"severe", "probable", "possible"}:
                    flags.append("possible_blur")
                if result["blur_severity"] == "severe":
                    flags.append("severe_blur")

                visual = self.config["visual_suitability"]
                edges = cv2.Canny(gray, visual["canny_low"], visual["canny_high"])
                edge_mask = edges > 0
                result["edge_density"] = round(float(edge_mask.mean()), 5)
                row_fraction = float((edge_mask.mean(axis=1) > 0.12).mean())
                result["text_row_fraction"] = round(row_fraction, 5)
                hsv = cv2.cvtColor(rgb, cv2.COLOR_RGB2HSV)
                result["saturation_mean"] = round(float(hsv[:, :, 1].mean()), 3)
                h, w = gray.shape
                ch, cw = max(1, h // 5), max(1, w // 5)
                corners = [
                    gray[:ch, :cw],
                    gray[:ch, w - cw :],
                    gray[h - ch :, :cw],
                    gray[h - ch :, w - cw :],
                ]
                result["corner_contrast"] = round(
                    max(float(corner.std()) for corner in corners if corner.size), 3
                )

                if (
                    result["edge_density"] > visual["edge_density_high"]
                    and result["text_row_fraction"] > 0.25
                ):
                    flags.append("text_heavy_candidate")
                if (
                    result["edge_density"] > visual["edge_density_high"]
                    and result["saturation_mean"] < visual["saturation_low"]
                ):
                    flags.append("packaging_candidate")
                if (
                    result["corner_contrast"] > visual["corner_contrast_high"]
                    and result["edge_density"] < visual["edge_density_high"]
                ):
                    flags.append("possible_watermark")
                if (
                    result["edge_density"] < visual["edge_density_low"]
                    and result["saturation_mean"] < visual["saturation_low"]
                ):
                    flags.append("no_clear_subject_candidate")
            except Exception as exc:
                result["error_message"] = (
                    (result["error_message"] or "") + f" | feature extraction failed: {type(exc).__name__}: {exc}"
                ).strip(" |")

        screening_flags = {
            "very_low_resolution",
            "invalid_dimensions",
            "corrupted_image",
            "unexpected_mode",
            "possible_blur",
            "severe_blur",
            "highly_compressed",
            "text_heavy_candidate",
            "packaging_candidate",
            "possible_watermark",
            "no_clear_subject_candidate",
            "missing_source_file",
        }
        if any(flag in screening_flags for flag in flags):
            flags.append("manual_visual_review_required")
        result["flags"] = sorted(set(flags))
        result["manual_visual_review_required"] = "manual_visual_review_required" in result["flags"]
        result["scan_status"] = "success" if decoded else "failed"
        return result

    def run(self) -> dict[str, Any]:
        started = time.monotonic()
        rows = self.load_inventory()
        cache = self.load_cache()
        results: list[dict[str, Any]] = []
        cache_records: dict[str, dict[str, Any]] = {}
        reused = 0
        counter = 0

        self.logger.info("Assessing %d images with %d worker(s)", len(rows), self.workers)
        with ThreadPoolExecutor(max_workers=self.workers) as pool:
            futures = {}
            for row in rows:
                key = f"{row.get('image_id', '')}|{row.get('sha256', '')}"
                if self.incremental and key in cache:
                    cached = dict(cache[key])
                    results.append(cached)
                    cache_records[key] = cached
                    reused += 1
                else:
                    futures[pool.submit(self.assess_one, row)] = key
            for future in as_completed(futures):
                key = futures[future]
                try:
                    record = future.result()
                except Exception as exc:
                    record = {
                        "image_id": key.split("|", 1)[0],
                        "relative_path": "",
                        "flags": ["manual_visual_review_required"],
                        "manual_visual_review_required": True,
                        "scan_status": "failed",
                        "error_message": f"assessment exception: {type(exc).__name__}: {exc}",
                    }
                results.append(record)
                cache_records[key] = record
                counter += 1
                if counter % 500 == 0:
                    self.logger.info("Assessed %d new images", counter)

        results.sort(key=lambda item: item.get("relative_path", ""))
        report = self.build_report(results, rows, reused, time.monotonic() - started)
        self.write_outputs(results, report, cache_records)
        self.logger.info("Quality assessment complete: %d results", len(results))
        return report

    def build_report(
        self,
        results: list[dict[str, Any]],
        inventory: list[dict[str, str]],
        reused: int,
        duration: float,
    ) -> dict[str, Any]:
        flag_counts: Counter[str] = Counter()
        for record in results:
            flag_counts.update(record.get("flags", []))
        blur_counts = Counter(record.get("blur_severity", "unavailable") for record in results)
        variances = [r["laplacian_variance"] for r in results if isinstance(r.get("laplacian_variance"), (int, float))]
        edges = [r["edge_density"] for r in results if isinstance(r.get("edge_density"), (int, float))]
        return {
            "report_version": "image-quality-report-v1",
            "config_version": self.config["config_version"],
            "generated_at": utc_now(),
            "started_at": self.started_at,
            "duration_seconds": round(duration, 3),
            "dataset_root": str(self.dataset_root),
            "inventory_source": str(self.inventory_csv),
            "policy": self.config["policy"],
            "total_inventory_images": len(inventory),
            "total_assessed_images": len(results),
            "reused_from_cache": reused,
            "successfully_assessed": sum(1 for r in results if r.get("scan_status") == "success"),
            "failed_assessments": sum(1 for r in results if r.get("scan_status") == "failed"),
            "manual_visual_review_required": sum(1 for r in results if r.get("manual_visual_review_required")),
            "flag_distribution": dict(sorted(flag_counts.items())),
            "blur_severity_distribution": dict(sorted(blur_counts.items())),
            "blur_statistics": {
                "images_with_variance": len(variances),
                "min": min(variances) if variances else None,
                "max": max(variances) if variances else None,
                "mean": round(statistics.mean(variances), 3) if variances else None,
                "median": round(statistics.median(variances), 3) if variances else None,
            },
            "resolution_statistics": {
                "low_resolution": flag_counts.get("low_resolution", 0),
                "very_low_resolution": flag_counts.get("very_low_resolution", 0),
                "below_224px": sum(
                    1
                    for r in results
                    if isinstance(r.get("min_side"), int) and r["min_side"] < 224
                ),
                "unusually_small": flag_counts.get("unusually_small_dimensions", 0),
                "unusually_large": flag_counts.get("unusually_large_dimensions", 0),
            },
            "mean_edge_density": round(statistics.mean(edges), 5) if edges else None,
            "results": results,
        }

    def write_outputs(
        self,
        results: list[dict[str, Any]],
        report: dict[str, Any],
        cache_records: dict[str, dict[str, Any]],
    ) -> None:
        self.pipeline_dir.joinpath("exports").mkdir(parents=True, exist_ok=True)
        self.pipeline_dir.joinpath("reports").mkdir(parents=True, exist_ok=True)
        with self.results_csv.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=RESULT_FIELDS, extrasaction="ignore")
            writer.writeheader()
            for record in results:
                row = dict(record)
                row["flags"] = "|".join(record.get("flags", []))
                writer.writerow(row)
        atomic_write_json(self.report_json, report)
        self.report_md.write_text(render_markdown(report), encoding="utf-8", newline="\n")
        atomic_write_json(
            self.cache_path,
            {"config_version": self.config["config_version"], "records": cache_records},
        )
        with self.history_path.open("a", encoding="utf-8", newline="\n") as handle:
            handle.write(json.dumps({
                "started_at": self.started_at,
                "generated_at": report["generated_at"],
                "assessed": report["total_assessed_images"],
                "reused": report["reused_from_cache"],
                "failed": report["failed_assessments"],
                "review_required": report["manual_visual_review_required"],
                "duration_seconds": report["duration_seconds"],
            }, ensure_ascii=False) + "\n")


def render_markdown(report: dict[str, Any]) -> str:
    lines = [
        "# RasaLanka image quality screening report",
        "",
        f"- Generated: {report['generated_at']}",
        f"- Duration: {report['duration_seconds']} s",
        f"- Images assessed: {report['total_assessed_images']} (reused from cache: {report['reused_from_cache']})",
        f"- Successfully assessed: {report['successfully_assessed']}",
        f"- Failed assessments: {report['failed_assessments']}",
        f"- Manual visual review required: {report['manual_visual_review_required']}",
        "",
        "These are machine-generated screening results. No human visual verification, "
        "OCR, watermark model, packaging model or ingredient classification was performed.",
        "",
        "## Flag distribution",
        "",
        "| Flag | Images |",
        "| --- | ---: |",
    ]
    for flag, count in report["flag_distribution"].items():
        lines.append(f"| `{flag}` | {count} |")
    lines += ["", "## Blur severity", "", "| Severity | Images |", "| --- | ---: |"]
    for severity, count in report["blur_severity_distribution"].items():
        lines.append(f"| {severity} | {count} |")
    blur = report["blur_statistics"]
    lines += [
        "",
        f"- Laplacian variance: min {blur['min']}, mean {blur['mean']}, median {blur['median']}, max {blur['max']}",
        "",
        "## Resolution",
        "",
    ]
    for key, value in report["resolution_statistics"].items():
        lines.append(f"- {key}: {value}")
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
    assessor = QualityAssessor(
        dataset_root=args.dataset_root,
        pipeline_dir=args.pipeline_dir,
        config=config,
        workers=args.workers,
        incremental=args.incremental,
    )
    try:
        report = assessor.run()
    except Exception as exc:
        assessor.logger.exception("Quality assessment aborted: %s", exc)
        return 1
    print(json.dumps({
        "assessed": report["total_assessed_images"],
        "successful": report["successfully_assessed"],
        "failed": report["failed_assessments"],
        "review_required": report["manual_visual_review_required"],
        "duration_seconds": report["duration_seconds"],
    }, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
