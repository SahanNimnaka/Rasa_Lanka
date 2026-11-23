"""Step 7 validation: fixture tests plus real-report consistency checks.

Fixture tests use small synthetic datasets. Real checks use the Step 6 inventory
and the Step 7 reports generated from the actual 24,356-image dataset.

Usage:
    python validate_quality_reports.py
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
import logging
import random
import tempfile
import unittest
from pathlib import Path
from typing import Any

import cv2
import numpy as np
from PIL import Image

SCRIPT_DIR = Path(__file__).resolve().parent
sys_path = str(SCRIPT_DIR)
import sys  # noqa: E402

if sys_path not in sys.path:
    sys.path.insert(0, sys_path)

from assess_image_quality import QualityAssessor  # noqa: E402
from detect_near_duplicates import (  # noqa: E402
    NearDuplicateDetector,
    build_complete_link_groups,
    hamming_distance,
    pair_is_candidate,
    similarity_from_hamming,
)
from scan_dataset import DatasetScanner  # noqa: E402

PIPELINE_DIR = SCRIPT_DIR.parent
CONFIG = json.loads((PIPELINE_DIR / "config" / "quality_assessment_config.json").read_text(encoding="utf-8"))
INVENTORY_CSV = PIPELINE_DIR / "exports" / "master_image_inventory.csv"
INVENTORY_JSON = PIPELINE_DIR / "exports" / "master_image_inventory.json"
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


def png_bytes(array: np.ndarray) -> bytes:
    buffer = io.BytesIO()
    Image.fromarray(array).save(buffer, format="PNG")
    return buffer.getvalue()


def fabricated_pair(
    left: str,
    right: str,
    left_folder: str = "Alpha",
    right_folder: str = "Beta",
    phash_hamming: int = 4,
    dhash_hamming: int = 4,
) -> dict[str, Any]:
    return {
        "group_id": "",
        "image_id_a": left,
        "relative_path_a": f"{left_folder}/{left}.png",
        "original_folder_a": left_folder,
        "original_filename_a": f"{left}.png",
        "sha256_a": left,
        "phash_a": "0" * 64,
        "dhash_a": "0" * 64,
        "image_id_b": right,
        "relative_path_b": f"{right_folder}/{right}.png",
        "original_folder_b": right_folder,
        "original_filename_b": f"{right}.png",
        "sha256_b": right,
        "phash_b": "1" * phash_hamming + "0" * (64 - phash_hamming),
        "dhash_b": "1" * dhash_hamming + "0" * (64 - dhash_hamming),
        "phash_hamming": phash_hamming,
        "dhash_hamming": dhash_hamming,
        "phash_similarity": similarity_from_hamming(phash_hamming),
        "dhash_similarity": similarity_from_hamming(dhash_hamming),
        "detection_method": "phash+dhash",
        "cross_folder": left_folder != right_folder,
        "review_status": "pending_review",
        "exact_group_id": "",
    }


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
            json.dumps({"mappings": [
                {"folder_name": "Alpha", "proposed_class_id": None, "mapping_status": "unmapped"},
                {"folder_name": "Beta", "proposed_class_id": None, "mapping_status": "unmapped"},
            ]}),
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

    def run_assessment(self) -> dict[str, Any]:
        assessor = QualityAssessor(
            dataset_root=self.dataset,
            pipeline_dir=self.pipeline,
            config=CONFIG,
            workers=1,
            logger=quiet_logger(),
        )
        return assessor.run()

    def run_detection(self, incremental: bool = False) -> dict[str, Any]:
        detector = NearDuplicateDetector(
            dataset_root=self.dataset,
            pipeline_dir=self.pipeline,
            config=CONFIG,
            workers=1,
            incremental=incremental,
            logger=quiet_logger(),
        )
        return detector.run()

    def duplicate_rows(self) -> list[dict[str, str]]:
        with (self.pipeline / "exports" / "duplicate_candidates.csv").open(encoding="utf-8", newline="") as handle:
            return list(csv.DictReader(handle))

    def inventory_ids(self) -> set[str]:
        payload = json.loads((self.pipeline / "exports" / "master_image_inventory.json").read_text(encoding="utf-8"))
        return {row["image_id"] for row in payload["images"]}

    def test_exact_duplicate_preservation(self) -> None:
        data = png_bytes(checkerboard(seed=4))
        (self.dataset / "Alpha" / "a.png").write_bytes(data)
        (self.dataset / "Beta" / "b.png").write_bytes(data)
        self.build_inventory()
        report = self.run_detection()
        self.assertEqual(report["exact_duplicate_groups_from_step6"], 1)
        exact_ids = report["exact_duplicate_groups"][0]["image_ids"]
        self.assertEqual(len(exact_ids), 2)
        exact_rows = [row for row in self.duplicate_rows() if row["detection_method"] == "exact_sha256"]
        self.assertEqual(len(exact_rows), 1)
        self.assertEqual(exact_rows[0]["sha256_a"], exact_rows[0]["sha256_b"])
        self.assertTrue(exact_rows[0]["exact_group_id"])

    def test_genuinely_similar_image_pair(self) -> None:
        base = checkerboard(128, seed=5)
        Image.fromarray(base).save(self.dataset / "Alpha" / "base.png")
        resized = cv2.resize(base, (64, 64), interpolation=cv2.INTER_AREA)
        Image.fromarray(resized).save(self.dataset / "Beta" / "resized.png")
        self.build_inventory()
        report = self.run_detection()
        self.assertGreaterEqual(report["perceptual_candidate_pairs"], 1)
        self.assertTrue(any(row["detection_method"] in {"phash", "dhash", "phash+dhash"} for row in self.duplicate_rows()))

    def test_different_images_should_not_match(self) -> None:
        circle = np.zeros((128, 128), dtype=np.uint8)
        cv2.circle(circle, (64, 64), 45, 255, thickness=-1)
        square = np.zeros((128, 128), dtype=np.uint8)
        cv2.rectangle(square, (12, 12), (116, 116), 255, thickness=-1)
        Image.fromarray(cv2.cvtColor(circle, cv2.COLOR_GRAY2RGB)).save(self.dataset / "Alpha" / "circle.png")
        Image.fromarray(cv2.cvtColor(square, cv2.COLOR_GRAY2RGB)).save(self.dataset / "Beta" / "square.png")
        self.build_inventory()
        report = self.run_detection()
        self.assertEqual(report["total_candidate_pairs"], 0)
        self.assertEqual(report["candidate_group_count"], 0)

    def test_transitive_chain_a_b_c_is_not_grouped(self) -> None:
        # A-B and B-C pass; no A-C pair record exists, so A and C must never
        # appear in the same strict group.
        pairs = [
            fabricated_pair("IMG-A", "IMG-B", phash_hamming=4, dhash_hamming=4),
            fabricated_pair("IMG-B", "IMG-C", phash_hamming=5, dhash_hamming=5),
        ]
        groups = build_complete_link_groups(pairs)
        for group in groups:
            self.assertFalse({"IMG-A", "IMG-C"}.issubset(set(group["image_ids"])))
        self.assertTrue(all(g["member_pair_count"] == g["image_count"] * (g["image_count"] - 1) // 2 for g in groups))

    def test_oversized_transitive_group_prevented(self) -> None:
        ids = [f"IMG-{i:06d}" for i in range(12)]
        pairs = [
            fabricated_pair(ids[index], ids[index + 1], phash_hamming=3, dhash_hamming=3)
            for index in range(len(ids) - 1)
        ]
        groups = build_complete_link_groups(pairs)
        self.assertLessEqual(max((g["image_count"] for g in groups), default=0), 2)
        self.assertTrue(all(g["member_pair_count"] == g["image_count"] * (g["image_count"] - 1) // 2 for g in groups))

    def test_cross_folder_candidate_identification(self) -> None:
        data = png_bytes(checkerboard(seed=9))
        (self.dataset / "Alpha" / "a.png").write_bytes(data)
        (self.dataset / "Beta" / "b.png").write_bytes(data)
        self.build_inventory()
        report = self.run_detection()
        self.assertEqual(report["cross_folder_candidate_pairs"], 1)
        row = self.duplicate_rows()[0]
        self.assertEqual(row["cross_folder"], "True")
        self.assertNotEqual(row["original_folder_a"], row["original_folder_b"])
        self.assertEqual(row["review_status"], "pending_review")

    def test_stable_image_id_references(self) -> None:
        for name in ("a.png", "b.png"):
            Image.fromarray(checkerboard(seed=7)).save(self.dataset / "Alpha" / name)
        self.build_inventory()
        report = self.run_detection()
        inventory_ids = self.inventory_ids()
        pair_ids = {row[field] for row in self.duplicate_rows() for field in ("image_id_a", "image_id_b")}
        self.assertTrue(pair_ids.issubset(inventory_ids))
        self.assertEqual({image_id for group in report["groups"] for image_id in group["image_ids"]}, pair_ids)

    def test_correct_pairwise_similarity_calculations(self) -> None:
        self.assertEqual(hamming_distance("0000", "0011"), 2)
        self.assertEqual(similarity_from_hamming(2, bits=4), 0.5)
        self.assertFalse(pair_is_candidate(30, 30, CONFIG["duplicate_detection"]))
        self.assertTrue(pair_is_candidate(10, 30, CONFIG["duplicate_detection"]))
        self.assertFalse(pair_is_candidate(None, 0, CONFIG["duplicate_detection"]))
        base = checkerboard(160, seed=6)
        Image.fromarray(base).save(self.dataset / "Alpha" / "high.jpg", quality=95)
        Image.fromarray(base).save(self.dataset / "Beta" / "low.jpg", quality=15)
        self.build_inventory()
        self.run_detection()
        row = self.duplicate_rows()[0]
        self.assertEqual(int(row["phash_hamming"]), hamming_distance(row["phash_a"], row["phash_b"]))
        self.assertEqual(int(row["dhash_hamming"]), hamming_distance(row["dhash_a"], row["dhash_b"]))
        self.assertEqual(float(row["phash_similarity"]), similarity_from_hamming(int(row["phash_hamming"])))
        self.assertEqual(float(row["dhash_similarity"]), similarity_from_hamming(int(row["dhash_hamming"])))

    def test_csv_and_json_consistency(self) -> None:
        data = png_bytes(checkerboard(seed=8))
        (self.dataset / "Alpha" / "a.png").write_bytes(data)
        (self.dataset / "Beta" / "b.png").write_bytes(data)
        self.build_inventory()
        report = self.run_detection()
        rows = self.duplicate_rows()
        self.assertEqual(len(rows), report["total_candidate_pairs"])
        self.assertEqual(sum(row["cross_folder"] == "True" for row in rows), report["cross_folder_candidate_pairs"])
        self.assertEqual(sum(row["detection_method"] == "exact_sha256" for row in rows), report["exact_candidate_pairs"])
        self.assertEqual(
            sum(row["detection_method"] != "exact_sha256" for row in rows),
            report["perceptual_candidate_pairs"],
        )
        group_ids = {group["group_id"] for group in report["groups"]}
        self.assertTrue(all(not row["group_id"] or row["group_id"] in group_ids for row in rows))

    def test_original_image_integrity(self) -> None:
        for folder, seed in (("Alpha", 10), ("Beta", 11)):
            Image.fromarray(checkerboard(seed=seed)).save(self.dataset / folder / f"{folder.lower()}.png")
        before = {
            path.relative_to(self.dataset).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in self.dataset.rglob("*") if path.is_file()
        }
        self.build_inventory()
        self.run_detection()
        after = {
            path.relative_to(self.dataset).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
            for path in self.dataset.rglob("*") if path.is_file()
        }
        self.assertEqual(before, after)

    def test_incremental_hash_cache_behavior(self) -> None:
        Image.fromarray(checkerboard(seed=12)).save(self.dataset / "Alpha" / "a.png")
        Image.fromarray(cv2.resize(checkerboard(seed=12), (64, 64), interpolation=cv2.INTER_AREA)).save(
            self.dataset / "Beta" / "b.png"
        )
        self.build_inventory()
        first = self.run_detection(incremental=False)
        self.assertGreater(first["new_perceptual_hashes"], 0)
        self.assertEqual(first["reused_perceptual_hashes"], 0)
        cache_path = self.pipeline / "exports" / "perceptual_hash_cache.json"
        self.assertTrue(cache_path.is_file())
        second = self.run_detection(incremental=True)
        self.assertEqual(second["new_perceptual_hashes"], 0)
        self.assertGreater(second["reused_perceptual_hashes"], 0)
        self.assertEqual(first["total_candidate_pairs"], second["total_candidate_pairs"])

    def test_no_self_pairs_from_band_collisions(self) -> None:
        data = png_bytes(checkerboard(seed=14))
        (self.dataset / "Alpha" / "a.png").write_bytes(data)
        (self.dataset / "Beta" / "b.png").write_bytes(data)
        self.build_inventory()
        report = self.run_detection()
        rows = self.duplicate_rows()
        self.assertGreater(len(rows), 0)
        self.assertTrue(all(row["image_id_a"] != row["image_id_b"] for row in rows))
        self.assertTrue(all(row["relative_path_a"] != row["relative_path_b"] for row in rows))
        self.assertEqual(report["images_in_candidate_pairs"], 2)

    def test_empty_or_failed_hash_handling(self) -> None:
        Image.fromarray(checkerboard(seed=13)).save(self.dataset / "Alpha" / "good.png")
        (self.dataset / "Beta" / "broken.jpg").write_bytes(b"not a real jpeg file")
        self.build_inventory()
        report = self.run_detection()
        self.assertEqual(report["failed_perceptual_hashes"], 1)
        rows = self.duplicate_rows()
        broken_ids = {row["image_id"] for row in json.loads(
            (self.pipeline / "exports" / "master_image_inventory.json").read_text(encoding="utf-8")
        )["images"] if row["relative_path"] == "Beta/broken.jpg"}
        self.assertTrue(all(
            row["image_id_a"] not in broken_ids and row["image_id_b"] not in broken_ids for row in rows
        ))


REAL_PRESENT = all(p.is_file() for p in (INVENTORY_CSV, INVENTORY_JSON, QUALITY_CSV, QUALITY_REPORT, DUPLICATE_CSV, DUPLICATE_REPORT))


@unittest.skipUnless(REAL_PRESENT, "real Step 7 reports not generated yet")
class RealReportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        with INVENTORY_CSV.open(encoding="utf-8", newline="") as handle:
            cls.inventory = list(csv.DictReader(handle))
        with DUPLICATE_CSV.open(encoding="utf-8", newline="") as handle:
            cls.duplicate_rows = list(csv.DictReader(handle))
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
        self.assertEqual(len(self.duplicate_rows), self.duplicate_report["total_candidate_pairs"])
        self.assertEqual(
            sum(r["cross_folder"] == "True" for r in self.duplicate_rows),
            self.duplicate_report["cross_folder_candidate_pairs"],
        )
        self.assertTrue(all(r["review_status"] == "pending_review" for r in self.duplicate_rows))

    def test_real_exact_groups_preserved(self) -> None:
        expected_exact = {row["duplicate_group_id"] for row in self.inventory if row.get("duplicate_group_id")}
        preserved = {group["group_id"] for group in self.duplicate_report["exact_duplicate_groups"]}
        self.assertTrue(expected_exact.issubset(preserved))
        self.assertEqual(self.duplicate_report["exact_duplicate_groups_from_step6"], len(expected_exact))

    def test_no_unrestricted_transitive_group(self) -> None:
        largest = max((g["image_count"] for g in self.duplicate_report["groups"]), default=0)
        self.assertLess(largest, 10953)
        for group in self.duplicate_report["groups"]:
            expected_pairs = group["image_count"] * (group["image_count"] - 1) // 2
            self.assertEqual(group["member_pair_count"], expected_pairs)
        self.assertFalse(self.duplicate_report["policy"]["unrestricted_transitive_grouping_used"])

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
    fixture_suite = unittest.defaultTestLoader.loadTestsFromTestCase(QualityFixtureTests)
    real_suite = unittest.defaultTestLoader.loadTestsFromTestCase(RealReportTests)
    suite.addTests(fixture_suite)
    suite.addTests(real_suite)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    print("-" * 72)
    print(f"FIXTURE TESTS: {fixture_suite.countTestCases()}")
    print(f"REAL DATASET TESTS: {real_suite.countTestCases()}")
    print(f"TOTAL RUN: {result.testsRun} | FAILURES: {len(result.failures)} | ERRORS: {len(result.errors)}")
    sys.exit(0 if result.wasSuccessful() else 1)
