"""Step 7 validation: fixture tests plus real-report consistency checks.

Fixture tests use small synthetic datasets. Real checks use the Step 6 inventory
and the Step 7 reports generated from the actual 24,356-image dataset.

Usage:
    python validate_quality_reports.py
"""

from __future__ import annotations

import csv
import hashlib
import json
import logging
import random
import shutil
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any

import cv2
import numpy as np
from PIL import Image

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from assess_image_quality import QualityAssessor  # noqa: E402
from detect_near_duplicates import NearDuplicateDetector  # noqa: E402
from scan_dataset import DatasetScanner  # noqa: E402

PIPELINE_DIR = SCRIPT_DIR.parent
CONFIG = json.loads((PIPELINE_DIR / "config" / "quality_assessment_config.json").read_text(encoding="utf-8"))
INVENTORY_CSV = PIPELINE_DIR / "exports" / "master_image_inventory.csv"
QUALITY_CSV = PIPELINE_DIR / "exports" / "image_quality_results.csv"
QUALITY_REPORT = PIPELINE_DIR / "reports" / "image_quality_report.json"
DUPLICATE_CSV = PIPELINE_DIR / "exports" / "duplicate_candidates.csv"
DUPLICATE_REPORT = PIPELINE_DIR / "reports" / "near_duplicate_report.json"
DATASET = PIPELINE_DIR.parent / "collected_dataset"


def quiet_logger() -> logging.Logger:
    logger = logging.getLogger(f"quality-test.{id(object())}")
    logger.setLevel(logging.CRITICAL)
    logger.propagate = False
    logger.addHandler(logging.NullHandler())
    return logger


def checkerboard(size: int = 128, squares: int = 8, seed: int = 0) -> np.ndarray:
    rng = np.random.default_rng(seed)
    step = max(1, size // squares)
    pattern = np.zeros((size, size), dtype=np.uint8)
    for row in range(0, size, step):
        for col in range(0, size, step):
            value = int(rng.integers(40, 230))
            pattern[row : row + step, col : col + step] = value
    return cv2.cvtColor(pattern, cv2.COLOR_GRAY2RGB)


class QualityFixtureTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.dataset = self.base / "dataset"
        self.pipeline = self.base / "pipeline"
        for folder in ("Alpha", "Beta"):
            (self.dataset / folder).mkdir(parents=True)
        for name in ("config", "exports", "reports", "logs"):
            (self.pipeline / name).mkdir(parents=True)
        (self.pipeline / "config" / "folder_class_mapping.json").write_text(
            json.dumps({"mappings": [{"folder_name": "Alpha", "proposed_class_id": None, "mapping_status": "unmapped"},
                                     {"folder_name": "Beta", "proposed_class_id": None, "mapping_status": "unmapped"}]}),
            encoding="utf-8",
        )

    def tearDown(self) -> None:
        self.temp.cleanup()

    def build_inventory(self) -> None:
        DatasetScanner(
            dataset_root=self.dataset,
            pipeline_dir=self.pipeline,
            mode="full",
            progress_interval=10000,
            logger=quiet_logger(),
        ).scan()

    def run_assessment(self, workers: int = 1) -> tuple[dict[str, Any], dict[str, Any]]:
        assessor = QualityAssessor(
            dataset_root=self.dataset,
            pipeline_dir=self.pipeline,
            config=CONFIG,
            workers=workers,
            logger=quiet_logger(),
        )
        return assessor.run(), json.loads((self.pipeline / "reports" / "image_quality_report.json").read_text(encoding="utf-8"))

    def run_detection(self, workers: int = 1) -> dict[str, Any]:
        detector = NearDuplicateDetector(
            dataset_root=self.dataset,
            pipeline_dir=self.pipeline,
            config=CONFIG,
            workers=workers,
            logger=quiet_logger(),
        )
        return detector.run()

    def quality_rows(self) -> list[dict[str, str]]:
        with (self.pipeline / "exports" / "image_quality_results.csv").open(encoding="utf-8", newline="") as handle:
            return list(csv.DictReader(handle))

    def duplicate_rows(self) -> list[dict[str, str]]:
        with (self.pipeline / "exports" / "duplicate_candidates.csv").open(encoding="utf-8", newline="") as handle:
            return list(csv.DictReader(handle))

    def test_sharp_and_blurry_images(self) -> None:
        sharp = checkerboard(seed=1)
        blurry = cv2.GaussianBlur(sharp, (0, 0), 6)
        Image.fromarray(sharp).save(self.dataset / "Alpha" / "sharp.png")
        Image.fromarray(blurry).save(self.dataset / "Alpha" / "blurry.png")
        self.build_inventory()
        report, _ = self.run_assessment()
        rows = {row["relative_path"]: row for row in report["results"]}
        sharp_var = rows["Alpha/sharp.png"]["laplacian_variance"]
        blurry_var = rows["Alpha/blurry.png"]["laplacian_variance"]
        self.assertGreater(sharp_var, blurry_var)
        self.assertLess(blurry_var, CONFIG["blur"]["probable_max"])
        self.assertIn("possible_blur", rows["Alpha/blurry.png"]["flags"])
        self.assertNotIn("possible_blur", rows["Alpha/sharp.png"]["flags"])

    def test_low_resolution_image(self) -> None:
        Image.fromarray(checkerboard(16, squares=4, seed=2)).save(self.dataset / "Alpha" / "tiny.png")
        self.build_inventory()
        report, _ = self.run_assessment()
        row = report["results"][0]
        self.assertIn("very_low_resolution", row["flags"])
        self.assertIn("manual_visual_review_required", row["flags"])
        self.assertTrue(row["manual_visual_review_required"])

    def test_corrupted_file_does_not_stop_assessment(self) -> None:
        Image.fromarray(checkerboard(seed=3)).save(self.dataset / "Alpha" / "good.png")
        (self.dataset / "Beta" / "broken.jpg").write_bytes(b"not a real jpeg file")
        self.build_inventory()
        report, _ = self.run_assessment()
        rows = {row["relative_path"]: row for row in report["results"]}
        self.assertEqual(report["total_assessed_images"], 2)
        self.assertIn("corrupted_image", rows["Beta/broken.jpg"]["flags"])
        self.assertEqual(rows["Beta/broken.jpg"]["scan_status"], "failed")
        self.assertEqual(rows["Alpha/good.png"]["scan_status"], "success")

    def test_exact_duplicates_preserved(self) -> None:
        image = checkerboard(seed=4)
        Image.fromarray(image).save(self.dataset / "Alpha" / "a.png")
        Image.fromarray(image).save(self.dataset / "Beta" / "b.png")
        self.build_inventory()
        self.run_assessment()
        report = self.run_detection()
        self.assertEqual(report["exact_duplicate_groups_from_step6"], 1)
        exact_groups = [g for g in report["groups"] if "exact_sha256" in g["detection_method"]]
        self.assertEqual(len(exact_groups), 1)
        self.assertEqual(exact_groups[0]["image_count"], 2)
        rows = self.duplicate_rows()
        self.assertEqual(sum(row["is_exact_duplicate"] == "True" for row in rows), 1)

    def test_resized_duplicates_detected(self) -> None:
        base = checkerboard(128, seed=5)
        Image.fromarray(base).save(self.dataset / "Alpha" / "base.png")
        resized = cv2.resize(base, (64, 64), interpolation=cv2.INTER_AREA)
        Image.fromarray(resized).save(self.dataset / "Beta" / "resized.png")
        self.build_inventory()
        self.run_assessment()
        report = self.run_detection()
        self.assertEqual(report["candidate_group_count"], 1)
        self.assertEqual(report["groups"][0]["image_count"], 2)
        self.assertIn("phash", report["groups"][0]["detection_method"])

    def test_compressed_variants_detected(self) -> None:
        image = checkerboard(160, seed=6)
        Image.fromarray(image).save(self.dataset / "Alpha" / "high.jpg", quality=95)
        Image.fromarray(image).save(self.dataset / "Beta" / "low.jpg", quality=15)
        self.build_inventory()
        self.run_assessment()
        report = self.run_detection()
        self.assertEqual(report["candidate_group_count"], 1)
        self.assertEqual(report["groups"][0]["image_count"], 2)

    def test_different_similar_images_not_merged(self) -> None:
        circle = np.zeros((128, 128), dtype=np.uint8)
        cv2.circle(circle, (64, 64), 45, 255, thickness=-1)
        square = np.zeros((128, 128), dtype=np.uint8)
        cv2.rectangle(square, (12, 12), (116, 116), 255, thickness=-1)
        Image.fromarray(cv2.cvtColor(circle, cv2.COLOR_GRAY2RGB)).save(self.dataset / "Alpha" / "circle.png")
        Image.fromarray(cv2.cvtColor(square, cv2.COLOR_GRAY2RGB)).save(self.dataset / "Beta" / "square.png")
        self.build_inventory()
        self.run_assessment()
        report = self.run_detection()
        self.assertEqual(report["candidate_group_count"], 0)

    def test_stable_image_id_references(self) -> None:
        for name in ("a.png", "b.png"):
            Image.fromarray(checkerboard(seed=7)).save(self.dataset / "Alpha" / name)
        self.build_inventory()
        inventory_ids = {row["image_id"] for row in json.loads(
            (self.pipeline / "exports" / "master_image_inventory.json").read_text(encoding="utf-8")
        )["images"]}
        self.run_assessment()
        self.run_detection()
        quality_ids = {row["image_id"] for row in self.quality_rows()}
        duplicate_ids = {row["image_id"] for row in self.duplicate_rows()}
        self.assertEqual(quality_ids, inventory_ids)
        self.assertTrue(duplicate_ids.issubset(inventory_ids))

    def test_csv_and_json_consistency(self) -> None:
        Image.fromarray(checkerboard(seed=8)).save(self.dataset / "Alpha" / "a.png")
        Image.fromarray(checkerboard(seed=8)).save(self.dataset / "Beta" / "b.png")
        self.build_inventory()
        quality_report, _ = self.run_assessment()
        duplicate_report = self.run_detection()
        quality_rows = self.quality_rows()
        self.assertEqual(len(quality_rows), len(quality_report["results"]))
        self.assertEqual(
            {row["image_id"]: row["flags"] for row in quality_rows},
            {row["image_id"]: "|".join(row["flags"]) for row in quality_report["results"]},
        )
        duplicate_rows = self.duplicate_rows()
        report_ids = {image_id for group in duplicate_report["groups"] for image_id in group["image_ids"]}
        self.assertEqual({row["image_id"] for row in duplicate_rows}, report_ids)

    def test_original_file_integrity(self) -> None:
        for folder, seed in (("Alpha", 9), ("Beta", 10)):
            Image.fromarray(checkerboard(seed=seed)).save(self.dataset / folder / f"{folder.lower()}.png")
        before = {
            path.relative_to(self.dataset).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in self.dataset.rglob("*") if path.is_file()
        }
        self.build_inventory()
        self.run_assessment()
        self.run_detection()
        after = {
            path.relative_to(self.dataset).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in self.dataset.rglob("*") if path.is_file()
        }
        self.assertEqual(before, after)


REAL_PRESENT = all(p.is_file() for p in (INVENTORY_CSV, QUALITY_CSV, QUALITY_REPORT, DUPLICATE_CSV, DUPLICATE_REPORT))


@unittest.skipUnless(REAL_PRESENT, "real Step 7 reports not generated yet")
class RealReportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        with INVENTORY_CSV.open(encoding="utf-8", newline="") as handle:
            cls.inventory = list(csv.DictReader(handle))
        cls.quality_report = json.loads(QUALITY_REPORT.read_text(encoding="utf-8"))
        cls.duplicate_report = json.loads(DUPLICATE_REPORT.read_text(encoding="utf-8"))

    def test_real_counts_match_inventory(self) -> None:
        self.assertEqual(self.quality_report["total_assessed_images"], len(self.inventory))
        self.assertEqual(self.quality_report["total_inventory_images"], len(self.inventory))
        ids = [row["image_id"] for row in self.quality_report["results"]]
        self.assertEqual(len(ids), len(set(ids)))

    def test_real_csv_json_consistency(self) -> None:
        with QUALITY_CSV.open(encoding="utf-8", newline="") as handle:
            quality_rows = list(csv.DictReader(handle))
        self.assertEqual(len(quality_rows), len(self.quality_report["results"]))
        self.assertEqual(
            {r["image_id"]: r["sha256"] for r in quality_rows},
            {r["image_id"]: r["sha256"] for r in self.quality_report["results"]},
        )
        with DUPLICATE_CSV.open(encoding="utf-8", newline="") as handle:
            duplicate_rows = list(csv.DictReader(handle))
        expected = {image_id for group in self.duplicate_report["groups"] for image_id in group["image_ids"]}
        self.assertEqual({row["image_id"] for row in duplicate_rows}, expected)

    def test_real_exact_groups_preserved(self) -> None:
        expected_exact = {row["duplicate_group_id"] for row in self.inventory if row.get("duplicate_group_id")}
        preserved = {
            group_id
            for group in self.duplicate_report["groups"]
            for group_id in group["exact_group_ids"]
        }
        self.assertTrue(expected_exact.issubset(preserved))
        self.assertEqual(self.duplicate_report["exact_duplicate_groups_from_step6"], len(expected_exact))

    def test_real_source_integrity(self) -> None:
        random.seed(42)
        sample = random.sample(self.inventory, min(200, len(self.inventory)))
        for row in sample:
            path = DATASET / row["relative_path"]
            self.assertTrue(path.is_file())
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(), row["sha256"])


if __name__ == "__main__":
    print("=" * 72)
    print("RasaLanka Step 7 quality / near-duplicate validation")
    print("=" * 72)
    suite = unittest.TestSuite()
    suite.addTests(unittest.defaultTestLoader.loadTestsFromTestCase(QualityFixtureTests))
    suite.addTests(unittest.defaultTestLoader.loadTestsFromTestCase(RealReportTests))
    fixture_count = unittest.defaultTestLoader.loadTestsFromTestCase(QualityFixtureTests).countTestCases()
    real_count = unittest.defaultTestLoader.loadTestsFromTestCase(RealReportTests).countTestCases()
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    print("-" * 72)
    print(f"FIXTURE TESTS: {fixture_count}")
    print(f"REAL DATASET TESTS: {real_count}")
    print(f"TOTAL RUN: {result.testsRun} | FAILURES: {len(result.failures)} | ERRORS: {len(result.errors)}")
    sys.exit(0 if result.wasSuccessful() else 1)
