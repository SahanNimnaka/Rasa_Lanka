"""Validation suite for the Step 6 dataset scanner and master inventory.

Runs small, self-contained fixture tests plus optional checks against the real
master inventory produced by scan_dataset.py. Fixture results and real-dataset
results are reported separately.

Usage:
    python validate_inventory.py
"""

from __future__ import annotations

import csv
import hashlib
import json
import logging
import re
import shutil
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any

from PIL import Image

SCRIPT_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

from scan_dataset import INVENTORY_FIELDS, DatasetScanner, sha256_file  # noqa: E402

PIPELINE_DIR = SCRIPT_DIR.parent
REAL_INVENTORY_JSON = PIPELINE_DIR / "exports" / "master_image_inventory.json"
REAL_INVENTORY_CSV = PIPELINE_DIR / "exports" / "master_image_inventory.csv"
REAL_REPORT_JSON = PIPELINE_DIR / "reports" / "dataset_inventory_report.json"
REAL_DATASET = PIPELINE_DIR.parent / "collected_dataset"


def quiet_logger() -> logging.Logger:
    logger = logging.getLogger(f"inventory-test.{id(object())}")
    logger.setLevel(logging.CRITICAL)
    logger.propagate = False
    logger.addHandler(logging.NullHandler())
    return logger


def write_mapping(pipeline: Path, folders: list[str]) -> None:
    config = pipeline / "config"
    config.mkdir(parents=True, exist_ok=True)
    entries = [
        {
            "folder_name": folder,
            "proposed_class_id": None,
            "mapping_status": "ambiguous_pending_review",
        }
        for folder in folders
    ]
    (config / "folder_class_mapping.json").write_text(
        json.dumps({"mappings": entries}, indent=2), encoding="utf-8"
    )


def make_image(path: Path, size: tuple[int, int] = (32, 32), colour: str = "red") -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    image = Image.new("RGB", size, colour)
    image.save(path)


def read_inventory(pipeline: Path) -> list[dict[str, Any]]:
    payload = json.loads((pipeline / "exports" / "master_image_inventory.json").read_text(encoding="utf-8"))
    return payload["images"]


def scan_fixture(dataset: Path, pipeline: Path, mode: str = "full") -> dict[str, Any]:
    scanner = DatasetScanner(
        dataset_root=dataset,
        pipeline_dir=pipeline,
        mode=mode,
        progress_interval=10000,
        logger=quiet_logger(),
    )
    return scanner.scan()


class FixtureInventoryTests(unittest.TestCase):
    """Tests 2-12 using tiny temporary datasets (not the real 24k images)."""

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.dataset = self.base / "dataset"
        self.pipeline = self.base / "pipeline"
        self.dataset.mkdir()
        for folder in ("Alpha", "Beta"):
            (self.dataset / folder).mkdir()
        for name in ("config", "exports", "reports", "logs"):
            (self.pipeline / name).mkdir(parents=True)
        write_mapping(self.pipeline, ["Alpha", "Beta"])

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_02_all_supported_formats_recognised(self) -> None:
        folder = self.dataset / "Alpha"
        specs = [
            ("one.jpg", "JPEG"),
            ("two.jpeg", "JPEG"),
            ("three.png", "PNG"),
            ("four.webp", "WEBP"),
            ("five.bmp", "BMP"),
            ("six.tif", "TIFF"),
            ("seven.tiff", "TIFF"),
        ]
        for name, _ in specs:
            make_image(folder / name)
        report = scan_fixture(self.dataset, self.pipeline)
        records = read_inventory(self.pipeline)
        self.assertEqual(report["total_discovered_images"], 7)
        self.assertEqual(report["total_successfully_scanned"], 7)
        self.assertEqual(report["total_failed_images"], 0)
        self.assertEqual({r["file_extension"] for r in records}, {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"})
        self.assertTrue({"JPEG", "PNG", "WEBP", "BMP", "TIFF"}.issubset({r["image_format"] for r in records}))

    def test_03_image_ids_globally_unique(self) -> None:
        for folder in ("Alpha", "Beta"):
            for index in range(3):
                make_image(self.dataset / folder / f"image_{index}.png")
        scan_fixture(self.dataset, self.pipeline)
        ids = [r["image_id"] for r in read_inventory(self.pipeline)]
        self.assertEqual(len(ids), len(set(ids)))
        self.assertTrue(all(re.fullmatch(r"IMG-\d{6}", image_id) for image_id in ids))

    def test_04_original_filenames_preserved(self) -> None:
        name = "odd name.v2.JPG"
        path = self.dataset / "Alpha" / name
        make_image(path)
        scan_fixture(self.dataset, self.pipeline)
        record = read_inventory(self.pipeline)[0]
        self.assertEqual(record["original_filename"], name)
        self.assertEqual(record["relative_path"], f"Alpha/{name}")
        self.assertEqual(record["original_folder"], "Alpha")

    def test_05_sha256_correctly_calculated(self) -> None:
        path = self.dataset / "Alpha" / "hash_check.png"
        make_image(path, colour="blue")
        expected = hashlib.sha256(path.read_bytes()).hexdigest()
        scan_fixture(self.dataset, self.pipeline)
        record = read_inventory(self.pipeline)[0]
        self.assertEqual(record["sha256"], expected)
        self.assertEqual(record["sha256"], sha256_file(path))

    def test_06_duplicate_groups_identified(self) -> None:
        make_image(self.dataset / "Alpha" / "a.png", colour="green")
        shutil.copy2(self.dataset / "Alpha" / "a.png", self.dataset / "Beta" / "b.png")
        make_image(self.dataset / "Beta" / "different.png", colour="blue")
        report = scan_fixture(self.dataset, self.pipeline)
        records = {r["relative_path"]: r for r in read_inventory(self.pipeline)}
        self.assertEqual(report["total_exact_duplicate_images"], 1)
        self.assertEqual(report["total_duplicate_groups"], 1)
        group_ids = {r["duplicate_group_id"] for r in records.values() if r["duplicate_group_id"]}
        self.assertEqual(group_ids, {"DUP-000001"})
        canonical = [r for r in records.values() if not r["is_exact_duplicate"]]
        extras = [r for r in records.values() if r["is_exact_duplicate"]]
        self.assertEqual(len(canonical), 2)
        self.assertEqual(len(extras), 1)
        self.assertIn(extras[0]["duplicate_of_image_id"], {r["image_id"] for r in canonical})

    def test_07_corrupted_image_does_not_stop_scan(self) -> None:
        make_image(self.dataset / "Alpha" / "good.png")
        corrupted = self.dataset / "Beta" / "broken.jpg"
        corrupted.write_bytes(b"this is definitely not a jpeg")
        report = scan_fixture(self.dataset, self.pipeline)
        records = read_inventory(self.pipeline)
        self.assertEqual(report["total_discovered_images"], 2)
        self.assertEqual(report["total_successfully_scanned"], 1)
        self.assertEqual(report["total_failed_images"], 1)
        failed = [r for r in records if r["scan_status"] == "failed"]
        self.assertEqual(len(failed), 1)
        self.assertEqual(failed[0]["relative_path"], "Beta/broken.jpg")
        self.assertIsNotNone(failed[0]["error_message"])

    def test_08_incremental_preserves_existing_ids(self) -> None:
        make_image(self.dataset / "Alpha" / "a.png")
        make_image(self.dataset / "Alpha" / "b.png")
        scan_fixture(self.dataset, self.pipeline)
        before = read_inventory(self.pipeline)
        before_ids = {r["relative_path"]: r["image_id"] for r in before}
        make_image(self.dataset / "Beta" / "c.png")
        report = scan_fixture(self.dataset, self.pipeline, mode="incremental")
        after = read_inventory(self.pipeline)
        after_ids = {r["relative_path"]: r["image_id"] for r in after}
        for path, image_id in before_ids.items():
            self.assertEqual(after_ids[path], image_id)
        self.assertEqual(len(report["newly_discovered_files"]), 1)
        self.assertEqual(report["newly_discovered_files"][0], "Beta/c.png")
        self.assertNotIn(after_ids["Beta/c.png"], before_ids.values())

    def test_09_missing_files_reported(self) -> None:
        make_image(self.dataset / "Alpha" / "a.png")
        make_image(self.dataset / "Beta" / "b.png")
        scan_fixture(self.dataset, self.pipeline)
        (self.dataset / "Beta" / "b.png").unlink()
        report = scan_fixture(self.dataset, self.pipeline, mode="incremental")
        self.assertEqual(len(report["missing_previously_indexed_files"]), 1)
        self.assertEqual(report["missing_previously_indexed_files"][0]["relative_path"], "Beta/b.png")
        self.assertEqual(report["total_discovered_images"], 1)
        self.assertEqual(len(read_inventory(self.pipeline)), 1)

    def test_10_changed_files_detected(self) -> None:
        path = self.dataset / "Alpha" / "a.png"
        make_image(path, colour="red")
        scan_fixture(self.dataset, self.pipeline)
        before = read_inventory(self.pipeline)[0]
        make_image(path, colour="purple")
        report = scan_fixture(self.dataset, self.pipeline, mode="incremental")
        after = read_inventory(self.pipeline)[0]
        self.assertEqual(len(report["changed_files"]), 1)
        self.assertEqual(report["changed_files"][0]["relative_path"], "Alpha/a.png")
        self.assertEqual(after["image_id"], before["image_id"])
        self.assertNotEqual(after["sha256"], before["sha256"])

    def test_11_csv_and_json_inventories_agree(self) -> None:
        make_image(self.dataset / "Alpha" / "a.png")
        make_image(self.dataset / "Beta" / "b.png", colour="yellow")
        scan_fixture(self.dataset, self.pipeline)
        json_rows = read_inventory(self.pipeline)
        with (self.pipeline / "exports" / "master_image_inventory.csv").open(encoding="utf-8", newline="") as handle:
            csv_rows = list(csv.DictReader(handle))
        self.assertEqual(len(json_rows), len(csv_rows))
        self.assertEqual(set(csv_rows[0].keys()), set(INVENTORY_FIELDS))
        self.assertEqual(
            {r["image_id"]: r["sha256"] for r in json_rows},
            {r["image_id"]: r["sha256"] for r in csv_rows},
        )
        self.assertEqual(
            {r["relative_path"] for r in json_rows},
            {r["relative_path"] for r in csv_rows},
        )

    def test_12_no_original_images_modified(self) -> None:
        first = self.dataset / "Alpha" / "a.png"
        second = self.dataset / "Beta" / "b.png"
        make_image(first, colour="orange")
        make_image(second, colour="cyan")
        before = {p.relative_to(self.dataset).as_posix(): sha256_file(p) for p in self.dataset.rglob("*") if p.is_file()}
        scan_fixture(self.dataset, self.pipeline)
        after = {p.relative_to(self.dataset).as_posix(): sha256_file(p) for p in self.dataset.rglob("*") if p.is_file()}
        self.assertEqual(before, after)


REAL_FILES_PRESENT = all(p.is_file() for p in (REAL_INVENTORY_JSON, REAL_INVENTORY_CSV, REAL_REPORT_JSON))


@unittest.skipUnless(REAL_FILES_PRESENT, "real master inventory not generated yet")
class RealInventoryTests(unittest.TestCase):
    """Checks 1, 3, 11, 12 against the actual scanned dataset inventory."""

    @classmethod
    def setUpClass(cls) -> None:
        payload = json.loads(REAL_INVENTORY_JSON.read_text(encoding="utf-8"))
        cls.rows = payload["images"]
        cls.report = json.loads(REAL_REPORT_JSON.read_text(encoding="utf-8"))

    def test_01_all_35_folders_discovered(self) -> None:
        self.assertEqual(self.report["total_ingredient_folders"], 35)
        self.assertEqual(len(self.report["image_counts_per_folder"]), 35)
        actual = {p.name for p in REAL_DATASET.iterdir() if p.is_dir()}
        self.assertEqual(set(self.report["image_counts_per_folder"]), actual)

    def test_03_real_image_ids_globally_unique(self) -> None:
        ids = [row["image_id"] for row in self.rows]
        self.assertEqual(len(ids), len(set(ids)))
        self.assertTrue(all(re.fullmatch(r"IMG-\d{6}", image_id) for image_id in ids))

    def test_11_real_csv_and_json_agree(self) -> None:
        with REAL_INVENTORY_CSV.open(encoding="utf-8", newline="") as handle:
            csv_rows = list(csv.DictReader(handle))
        self.assertEqual(len(csv_rows), len(self.rows))
        self.assertEqual(
            {r["image_id"]: r["sha256"] for r in self.rows},
            {r["image_id"]: r["sha256"] for r in csv_rows},
        )

    def test_12_real_images_not_modified_since_scan(self) -> None:
        checked = 0
        for row in self.rows:
            path = REAL_DATASET / row["relative_path"]
            self.assertTrue(path.is_file(), f"missing source: {path}")
            self.assertEqual(path.stat().st_size, row["file_size_bytes"], f"size changed: {path}")
            checked += 1
        self.assertGreater(checked, 0)
        self.assertFalse(self.report["policy"]["source_images_modified"])


if __name__ == "__main__":
    print("=" * 72)
    print("RasaLanka Step 6 inventory validation")
    print("=" * 72)
    suite = unittest.TestSuite()
    suite.addTests(unittest.defaultTestLoader.loadTestsFromTestCase(FixtureInventoryTests))
    suite.addTests(unittest.defaultTestLoader.loadTestsFromTestCase(RealInventoryTests))
    fixture_count = unittest.defaultTestLoader.loadTestsFromTestCase(FixtureInventoryTests).countTestCases()
    real_count = unittest.defaultTestLoader.loadTestsFromTestCase(RealInventoryTests).countTestCases()
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    print("-" * 72)
    print(f"FIXTURE TESTS: {fixture_count}")
    print(f"REAL DATASET TESTS: {real_count}")
    print(f"TOTAL RUN: {result.testsRun} | FAILURES: {len(result.failures)} | ERRORS: {len(result.errors)}")
    sys.exit(0 if result.wasSuccessful() else 1)
