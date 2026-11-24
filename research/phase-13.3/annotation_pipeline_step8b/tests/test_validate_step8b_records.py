import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
SCRIPT = ROOT / "research/phase-13.3/annotation_pipeline_step8b/scripts/validate_step8b_records.py"


def inventory_row(image_id="IMG-000002", rel="Black Pepper/black_pepper_00002.jpg", folder="Black Pepper", filename="black_pepper_00002.jpg"):
    return {
        "image_id": image_id,
        "relative_path": rel,
        "original_folder": folder,
        "original_filename": filename,
    }


def valid_individual():
    return {
        "schema_version": "human-recognition-v1",
        "record_id": "HR-IND-000001",
        "recognition_method": "individual",
        "verification_status": "confirmed",
        "ingredient_labels": ["cardamom_pod"],
        "uncertain_labels": [],
        "out_of_scope_labels": [],
        "reviewer_id": "reviewer_a",
        "recognition_date": "2026-11-24",
        "notes": "confirmed from explicit evidence",
        "provenance": {
            "source_type": "historical_individual_inspection",
            "source_reference": "doc-1",
            "evidence_summary": "explicit visual confirmation",
            "is_human_ground_truth": True,
        },
        "individual": inventory_row(),
    }


def valid_group():
    return {
        "schema_version": "human-recognition-v1",
        "record_id": "HR-GRP-000001",
        "recognition_method": "group",
        "verification_status": "pending_confirmation",
        "ingredient_labels": ["cardamom_pod"],
        "uncertain_labels": [],
        "out_of_scope_labels": [],
        "reviewer_id": None,
        "recognition_date": None,
        "notes": "group inspected; needs confirmation",
        "provenance": {
            "source_type": "historical_group_inspection",
            "source_reference": "session-x",
            "evidence_summary": "owner reported group review",
            "is_human_ground_truth": False,
        },
        "group": {
            "group_id": "GRP-cardamom",
            "original_folder": "Black Pepper",
            "proposed_ingredient": "cardamom_pod",
            "image_ids": ["IMG-000002"],
            "image_count": 1,
        },
    }


class TestStep8BValidator(unittest.TestCase):
    def run_validator(self, records, extra_rows=None, records_as_dir=False, malformed_files=None):
        with tempfile.TemporaryDirectory() as td:
            td_path = Path(td)
            inv_path = td_path / "inventory.csv"
            rows = [inventory_row(), inventory_row("IMG-000003", "Black Pepper/black_pepper_00003.jpg", "Black Pepper", "black_pepper_00003.jpg")]
            if extra_rows:
                rows.extend(extra_rows)
            with inv_path.open("w", encoding="utf-8", newline="") as f:
                w = json_import_csv_DictWriter(f, ["image_id", "relative_path", "original_folder", "original_filename"])
                w.writeheader()
                for r in rows:
                    w.writerow(r)
            out_dir = td_path / "out"
            if records_as_dir:
                rec_path = td_path / "records"
                rec_path.mkdir()
                for i, rec in enumerate(records):
                    (rec_path / f"rec_{i:02d}.json").write_text(json.dumps(rec), encoding="utf-8")
                for name, content in (malformed_files or {}).items():
                    (rec_path / name).write_text(content, encoding="utf-8")
            else:
                rec_path = td_path / "records.json"
                rec_path.write_text(json.dumps(records), encoding="utf-8")
            cmd = [sys.executable, str(SCRIPT), "--records", str(rec_path), "--inventory", str(inv_path), "--out-dir", str(out_dir)]
            proc = subprocess.run(cmd, capture_output=True, text=True, cwd=str(ROOT))
            report = {}
            report_path = out_dir / "step8b_validation_report.json"
            if report_path.exists():
                report = json.loads(report_path.read_text(encoding="utf-8"))
            return proc, report

    def assert_has_code(self, report, code):
        codes = {i["code"] for i in report.get("issues", [])}
        self.assertIn(code, codes, f"Expected {code} in {codes}\n{json.dumps(report.get('issues', []), indent=2)}")

    def assert_no_code(self, report, code):
        codes = {i["code"] for i in report.get("issues", [])}
        self.assertNotIn(code, codes, f"Did not expect {code} in {codes}\n{json.dumps(report.get('issues', []), indent=2)}")


# helper to avoid top-level csv import conflict in generated file
import csv as _csv

def json_import_csv_DictWriter(f, fieldnames):
    return _csv.DictWriter(f, fieldnames=fieldnames)


class TestCases(TestStep8BValidator):
    def test_valid_individual_annotation(self):
        proc, report = self.run_validator([valid_individual()])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assertEqual(report["summary"]["valid_records"], 1)
        self.assertEqual(report["summary"]["export_eligible_canonical_records"], 1)

    def test_valid_group_annotation(self):
        proc, report = self.run_validator([valid_group()])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assertEqual(report["summary"]["valid_records"], 1)
        self.assert_has_code(report, "X-GROUP-SCOPE")
        self.assertEqual(report["summary"]["export_eligible_canonical_records"], 0)

    def test_invalid_taxonomy_id(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["not_a_real_label"]
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-TAXONOMY")

    def test_out_of_scope_label_in_wrong_field(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["salt"]
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-TAXONOMY-PLACEMENT")

    def test_pending_garlic_canonical_label_blocked(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["garlic_clove"]
        rec["individual"]["relative_path"] = "Gralic/gralic_00001.jpg"
        rec["individual"]["original_folder"] = "Gralic"
        rec["individual"]["original_filename"] = "gralic_00001.jpg"
        rec["individual"]["image_id"] = "IMG-009001"
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PENDING-AS-CANONICAL")
        self.assert_has_code(report, "X-PENDING-CANONICAL")

    def test_pending_pepper_corn_canonical_label_blocked(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["black_pepper_whole"]
        rec["individual"]["relative_path"] = "Paper Corn/paper_corn_00001.jpg"
        rec["individual"]["original_folder"] = "Paper Corn"
        rec["individual"]["original_filename"] = "paper_corn_00001.jpg"
        rec["individual"]["image_id"] = "IMG-009002"
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009002", "Paper Corn/paper_corn_00001.jpg", "Paper Corn", "paper_corn_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PENDING-AS-CANONICAL")
        self.assert_has_code(report, "X-PENDING-CANONICAL")

    def test_approved_curry_leaf_alias_resolves(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["Curry Leaf"]
        rec["individual"]["relative_path"] = "Curry Leavf/curry_leavf_00001.jpg"
        rec["individual"]["original_folder"] = "Curry Leavf"
        rec["individual"]["original_filename"] = "curry_leavf_00001.jpg"
        rec["individual"]["image_id"] = "IMG-009003"
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009003", "Curry Leavf/curry_leavf_00001.jpg", "Curry Leavf", "curry_leavf_00001.jpg")])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assertEqual(report["results"][0]["resolved_ingredient_labels"], ["curry_leaves"])
        self.assertTrue(report["results"][0]["alias_resolutions"])

    def test_duplicate_record_id(self):
        r1 = valid_individual()
        r2 = valid_individual()
        r2["individual"] = inventory_row("IMG-000003", "Black Pepper/black_pepper_00003.jpg", "Black Pepper", "black_pepper_00003.jpg")
        proc, report = self.run_validator([r1, r2])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-DUPLICATE-ID")

    def test_conflicting_image_annotations(self):
        r1 = valid_individual()
        r2 = valid_individual()
        r2["record_id"] = "HR-IND-000002"
        r2["ingredient_labels"] = ["cinnamon_bark"]
        proc, report = self.run_validator([r1, r2])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-CONFLICT")
        self.assert_has_code(report, "X-CONFLICT")

    def test_invalid_image_id_path_combination(self):
        rec = valid_individual()
        rec["individual"]["relative_path"] = "Cardamom/cardamom_00001.jpg"
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-LINK")

    def test_invalid_group_image_count(self):
        rec = valid_group()
        rec["group"]["image_count"] = 2
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-GROUP-COUNT")

    def test_confirmed_annotation_without_valid_human_provenance(self):
        rec = valid_individual()
        rec["provenance"]["is_human_ground_truth"] = False
        rec["provenance"]["evidence_summary"] = ""
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PROVENANCE")
        self.assert_has_code(report, "X-PROVENANCE-INCOMPLETE")

    # --- B1 review critical checks ---

    def test_pending_garlic_observed_label_blocked(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["Garlic"]
        rec["individual"].update(inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg"))
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PENDING-AS-CANONICAL")
        self.assert_has_code(report, "X-PENDING-CANONICAL")
        self.assertEqual(report["results"][0]["export_eligible_canonical"], False)

    def test_pending_pepper_corn_observed_label_blocked(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["Pepper Corn"]
        rec["individual"].update(inventory_row("IMG-009002", "Paper Corn/paper_corn_00001.jpg", "Paper Corn", "paper_corn_00001.jpg"))
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009002", "Paper Corn/paper_corn_00001.jpg", "Paper Corn", "paper_corn_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PENDING-AS-CANONICAL")
        self.assert_has_code(report, "X-PENDING-CANONICAL")

    def test_pending_label_in_uncertain_field_blocks_export_not_placement(self):
        rec = valid_individual()
        rec["ingredient_labels"] = []
        rec["uncertain_labels"] = ["Garlic"]
        rec["individual"].update(inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg"))
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg")])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assert_has_code(report, "X-PENDING-CANONICAL")
        self.assert_has_code(report, "X-UNRESOLVED-UNCERTAIN")
        self.assert_no_code(report, "E-TAXONOMY")
        self.assert_no_code(report, "E-TAXONOMY-PLACEMENT")
        self.assertEqual(report["results"][0]["export_eligible_canonical"], False)

    def test_group_pending_label_cannot_be_canonical_approved(self):
        rec = valid_group()
        rec["verification_status"] = "confirmed"
        rec["provenance"]["is_human_ground_truth"] = True
        rec["reviewer_id"] = "reviewer_a"
        rec["recognition_date"] = "2026-11-24"
        rec["ingredient_labels"] = ["Garlic"]
        rec["group"]["original_folder"] = "Gralic"
        rec["group"]["image_ids"] = ["IMG-009001"]
        rec["group"]["image_count"] = 1
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PENDING-AS-CANONICAL")
        self.assert_has_code(report, "X-PENDING-CANONICAL")
        self.assert_has_code(report, "X-GROUP-SCOPE")
        self.assertEqual(report["results"][0]["export_eligible_canonical"], False)

    def test_curry_leaf_alias_preserves_source_and_no_eligibility_bypass(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["Curry Leaf"]
        rec["verification_status"] = "pending_confirmation"
        rec["provenance"]["is_human_ground_truth"] = False
        rec["individual"].update(inventory_row("IMG-009003", "Curry Leavf/curry_leavf_00001.jpg", "Curry Leavf", "curry_leavf_00001.jpg"))
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009003", "Curry Leavf/curry_leavf_00001.jpg", "Curry Leavf", "curry_leavf_00001.jpg")])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        res = report["results"][0]
        self.assertEqual(res["resolved_ingredient_labels"], ["curry_leaves"])
        self.assertEqual(res["alias_resolutions"][0]["display_label"], "Curry Leaf")
        self.assertEqual(res["alias_resolutions"][0]["canonical_class_id"], "curry_leaves")
        self.assertEqual(res["export_eligible_canonical"], False)
        self.assert_has_code(report, "X-NOT-CONFIRMED")
        # source record file was not mutated
        # (validator reads input; no write-back path exists)

    def test_identical_duplicate_active_records_block_export(self):
        r1 = valid_individual()
        r2 = valid_individual()
        r2["record_id"] = "HR-IND-000002"
        proc, report = self.run_validator([r1, r2])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-DUPLICATE-CONTENT")
        self.assert_has_code(report, "X-CONFLICT")
        self.assertTrue(all(r["export_eligible_canonical"] is False for r in report["results"]))

    def test_superseded_record_excluded_from_active_conflict(self):
        r1 = valid_individual()
        r1["record_id"] = "HR-IND-000001"
        r1["ingredient_labels"] = ["cinnamon_bark"]
        r2 = valid_individual()
        r2["record_id"] = "HR-IND-000002"
        r2["ingredient_labels"] = ["cardamom_pod"]
        r2["supersedes_record_id"] = "HR-IND-000001"
        proc, report = self.run_validator([r1, r2])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assert_no_code(report, "E-CONFLICT")
        eligible = [r for r in report["results"] if r["export_eligible_canonical"]]
        self.assertEqual(len(eligible), 1)
        self.assertEqual(eligible[0]["record_id"], "HR-IND-000002")

    def test_duplicate_record_id_across_separate_files(self):
        r1 = valid_individual()
        r2 = valid_individual()
        r2["individual"] = inventory_row("IMG-000003", "Black Pepper/black_pepper_00003.jpg", "Black Pepper", "black_pepper_00003.jpg")
        proc, report = self.run_validator([r1, r2], records_as_dir=True)
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-DUPLICATE-ID")

    def test_conflicting_records_across_separate_files(self):
        r1 = valid_individual()
        r2 = valid_individual()
        r2["record_id"] = "HR-IND-000002"
        r2["ingredient_labels"] = ["cinnamon_bark"]
        proc, report = self.run_validator([r1, r2], records_as_dir=True)
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-CONFLICT")

    def test_malformed_json_reported_without_terminating_batch(self):
        good = valid_individual()
        proc, report = self.run_validator([good], records_as_dir=True, malformed_files={"broken.json": "{not-json"})
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-LOAD")
        self.assertEqual(report["summary"]["records_checked"], 2)
        self.assertEqual(report["summary"]["valid_records"], 1)

    def test_deterministic_report_ordering(self):
        r1 = valid_individual()
        r2 = valid_individual()
        r2["record_id"] = "HR-IND-000002"
        r2["individual"] = inventory_row("IMG-000003", "Black Pepper/black_pepper_00003.jpg", "Black Pepper", "black_pepper_00003.jpg")
        proc1, report1 = self.run_validator([r2, r1])
        proc2, report2 = self.run_validator([r1, r2])
        ids1 = [r["record_id"] for r in report1["results"]]
        ids2 = [r["record_id"] for r in report2["results"]]
        self.assertEqual(ids1, ids2)
        codes1 = [(i["severity"], i["code"], i["record_id"]) for i in report1["issues"]]
        codes2 = [(i["severity"], i["code"], i["record_id"]) for i in report2["issues"]]
        self.assertEqual(codes1, codes2)

    # --- Supersession validation ---

    def test_supersedes_nonexistent_record_id(self):
        rec = valid_individual()
        rec["supersedes_record_id"] = "HR-IND-999999"
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SUPERSESSION")
        self.assertEqual(report["results"][0]["export_eligible_canonical"], False)

    def test_self_referencing_supersession(self):
        rec = valid_individual()
        rec["supersedes_record_id"] = rec["record_id"]
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SUPERSESSION")

    def test_supersession_other_image_rejected(self):
        r1 = valid_individual()
        r1["record_id"] = "HR-IND-000001"
        r2 = valid_individual()
        r2["record_id"] = "HR-IND-000002"
        r2["individual"] = inventory_row("IMG-000003", "Black Pepper/black_pepper_00003.jpg", "Black Pepper", "black_pepper_00003.jpg")
        r2["supersedes_record_id"] = "HR-IND-000001"
        proc, report = self.run_validator([r1, r2])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SUPERSESSION")
        by_id = {r["record_id"]: r for r in report["results"]}
        # Referring record is invalid; target remains governed by its own validity.
        self.assertFalse(by_id["HR-IND-000002"]["export_eligible_canonical"])
        self.assertTrue(by_id["HR-IND-000001"]["export_eligible_canonical"])

    def test_multiple_records_superseding_same_record(self):
        base = valid_individual()
        base["record_id"] = "HR-IND-000001"
        r2 = valid_individual()
        r2["record_id"] = "HR-IND-000002"
        r2["supersedes_record_id"] = "HR-IND-000001"
        r3 = valid_individual()
        r3["record_id"] = "HR-IND-000003"
        r3["supersedes_record_id"] = "HR-IND-000001"
        proc, report = self.run_validator([base, r2, r3])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SUPERSESSION")

    def test_circular_supersession_chain(self):
        r1 = valid_individual()
        r1["record_id"] = "HR-IND-000001"
        r1["supersedes_record_id"] = "HR-IND-000002"
        r2 = valid_individual()
        r2["record_id"] = "HR-IND-000002"
        r2["supersedes_record_id"] = "HR-IND-000001"
        proc, report = self.run_validator([r1, r2])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SUPERSESSION")
        self.assertTrue(all(not r["export_eligible_canonical"] for r in report["results"]))

    def test_superseded_record_not_active_ground_truth(self):
        base = valid_individual()
        base["record_id"] = "HR-IND-000001"
        base["ingredient_labels"] = ["cinnamon_bark"]
        new = valid_individual()
        new["record_id"] = "HR-IND-000002"
        new["ingredient_labels"] = ["cardamom_pod"]
        new["supersedes_record_id"] = "HR-IND-000001"
        proc, report = self.run_validator([base, new])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        by_id = {r["record_id"]: r for r in report["results"]}
        self.assertFalse(by_id["HR-IND-000001"]["export_eligible_canonical"])
        self.assertIn("X-SUPERSEDED", by_id["HR-IND-000001"]["export_block_codes"])
        self.assertTrue(by_id["HR-IND-000002"]["export_eligible_canonical"])

    # --- Pending-label enforcement edge cases ---

    def test_pending_garlic_in_out_of_scope_blocked(self):
        rec = valid_individual()
        rec["ingredient_labels"] = []
        rec["out_of_scope_labels"] = ["Garlic"]
        rec["individual"].update(inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg"))
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PENDING-AS-OUT-OF-SCOPE")
        self.assert_has_code(report, "X-PENDING-CANONICAL")
        self.assertEqual(report["results"][0]["export_eligible_canonical"], False)

    def test_pending_pepper_corn_in_out_of_scope_blocked(self):
        rec = valid_individual()
        rec["ingredient_labels"] = []
        rec["out_of_scope_labels"] = ["Pepper Corn"]
        rec["individual"].update(inventory_row("IMG-009002", "Paper Corn/paper_corn_00001.jpg", "Paper Corn", "paper_corn_00001.jpg"))
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009002", "Paper Corn/paper_corn_00001.jpg", "Paper Corn", "paper_corn_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PENDING-AS-OUT-OF-SCOPE")
        self.assert_has_code(report, "X-PENDING-CANONICAL")

    def test_pending_pepper_candidate_in_uncertain_blocks_export_not_invalid(self):
        rec = valid_individual()
        rec["ingredient_labels"] = []
        rec["uncertain_labels"] = ["black_pepper_ground"]
        rec["individual"].update(inventory_row("IMG-009002", "Paper Corn/paper_corn_00001.jpg", "Paper Corn", "paper_corn_00001.jpg"))
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009002", "Paper Corn/paper_corn_00001.jpg", "Paper Corn", "paper_corn_00001.jpg")])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assert_has_code(report, "X-PENDING-CANONICAL")
        self.assert_has_code(report, "X-UNRESOLVED-UNCERTAIN")
        self.assert_no_code(report, "E-PENDING-AS-CANONICAL")
        self.assertEqual(report["results"][0]["export_eligible_canonical"], False)

    def test_group_pending_pepper_candidate_blocked(self):
        rec = valid_group()
        rec["verification_status"] = "confirmed"
        rec["provenance"]["is_human_ground_truth"] = True
        rec["reviewer_id"] = "reviewer_a"
        rec["recognition_date"] = "2026-11-24"
        rec["ingredient_labels"] = ["black_pepper_whole"]
        rec["group"]["original_folder"] = "Paper Corn"
        rec["group"]["image_ids"] = ["IMG-009002"]
        rec["group"]["image_count"] = 1
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009002", "Paper Corn/paper_corn_00001.jpg", "Paper Corn", "paper_corn_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-PENDING-AS-CANONICAL")
        self.assert_has_code(report, "X-PENDING-CANONICAL")
        self.assert_has_code(report, "X-GROUP-SCOPE")

    # --- B1 final audit additions ---

    def test_supersession_non_string_target_rejected(self):
        rec = valid_individual()
        rec["supersedes_record_id"] = 123
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SCHEMA")

    def test_supersession_target_duplicate_record_id_rejected(self):
        r1 = valid_individual(); r1["record_id"] = "HR-IND-000001"
        r2 = valid_individual(); r2["record_id"] = "HR-IND-000001"; r2["individual"] = inventory_row("IMG-000003", "Black Pepper/black_pepper_00003.jpg", "Black Pepper", "black_pepper_00003.jpg")
        r3 = valid_individual(); r3["record_id"] = "HR-IND-000002"; r3["supersedes_record_id"] = "HR-IND-000001"
        proc, report = self.run_validator([r1, r2, r3])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-DUPLICATE-ID")
        self.assert_has_code(report, "E-SUPERSESSION")

    def test_supersession_partial_group_overlap_rejected(self):
        g1 = valid_group(); g1["record_id"] = "HR-GRP-000001"; g1["group"]["image_ids"] = ["IMG-000002","IMG-000003"]; g1["group"]["image_count"] = 2
        g2 = valid_group(); g2["record_id"] = "HR-GRP-000002"; g2["group"]["image_ids"] = ["IMG-000002"]; g2["group"]["image_count"] = 1; g2["supersedes_record_id"] = "HR-GRP-000001"
        proc, report = self.run_validator([g1, g2])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SUPERSESSION")
        by_id = {r["record_id"]: r for r in report["results"]}
        self.assertFalse(by_id["HR-GRP-000001"]["export_eligible_canonical"])

    def test_supersession_group_exact_match_allowed(self):
        g1 = valid_group(); g1["record_id"] = "HR-GRP-000001"; g1["group"]["image_ids"] = ["IMG-000002","IMG-000003"]; g1["group"]["image_count"] = 2
        g2 = valid_group(); g2["record_id"] = "HR-GRP-000002"; g2["group"]["image_ids"] = ["IMG-000002","IMG-000003"]; g2["group"]["image_count"] = 2; g2["supersedes_record_id"] = "HR-GRP-000001"
        proc, report = self.run_validator([g1, g2])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        by_id = {r["record_id"]: r for r in report["results"]}
        self.assertIn("X-SUPERSEDED", by_id["HR-GRP-000001"]["export_block_codes"])
        self.assertIn("X-GROUP-SCOPE", by_id["HR-GRP-000002"]["export_block_codes"])

    def test_supersession_individual_group_cross_scope_rejected(self):
        g1 = valid_group(); g1["record_id"] = "HR-GRP-000001"; g1["group"]["image_ids"] = ["IMG-000002","IMG-000003"]; g1["group"]["image_count"] = 2
        i2 = valid_individual(); i2["record_id"] = "HR-IND-000002"; i2["individual"] = inventory_row("IMG-000002", "Black Pepper/black_pepper_00002.jpg", "Black Pepper", "black_pepper_00002.jpg"); i2["supersedes_record_id"] = "HR-GRP-000001"
        proc, report = self.run_validator([g1, i2])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SUPERSESSION")

    def test_supersession_longer_circular_chain_rejected(self):
        r1 = valid_individual(); r1["record_id"] = "HR-IND-000001"; r1["supersedes_record_id"] = "HR-IND-000002"
        r2 = valid_individual(); r2["record_id"] = "HR-IND-000002"; r2["supersedes_record_id"] = "HR-IND-000003"
        r3 = valid_individual(); r3["record_id"] = "HR-IND-000003"; r3["supersedes_record_id"] = "HR-IND-000001"
        proc, report = self.run_validator([r1, r2, r3])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SUPERSESSION")
        self.assertTrue(all(not r["export_eligible_canonical"] for r in report["results"]))

    def test_supersession_valid_chain_middle_record_not_active(self):
        # A <- B <- C : B supersedes A, C supersedes B. A and B superseded; C eligible.
        r1 = valid_individual(); r1["record_id"] = "HR-IND-000001"; r1["ingredient_labels"]=["cinnamon_bark"]
        r2 = valid_individual(); r2["record_id"] = "HR-IND-000002"; r2["ingredient_labels"]=["cardamom_pod"]; r2["supersedes_record_id"] = "HR-IND-000001"
        r3 = valid_individual(); r3["record_id"] = "HR-IND-000003"; r3["ingredient_labels"]=["cardamom_pod"]; r3["supersedes_record_id"] = "HR-IND-000002"
        proc, report = self.run_validator([r1, r2, r3])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        by_id = {r["record_id"]: r for r in report["results"]}
        self.assertIn("X-SUPERSEDED", by_id["HR-IND-000001"]["export_block_codes"])
        self.assertIn("X-SUPERSEDED", by_id["HR-IND-000002"]["export_block_codes"])
        self.assertTrue(by_id["HR-IND-000003"]["export_eligible_canonical"])

    def test_supersession_empty_target_rejected(self):
        rec = valid_individual(); rec["supersedes_record_id"] = "   "
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SCHEMA")

    def test_supersession_group_to_individual_same_single_image_allowed(self):
        g1 = valid_group(); g1["record_id"] = "HR-GRP-000001"; g1["group"]["image_ids"] = ["IMG-000002"]; g1["group"]["image_count"] = 1
        i2 = valid_individual(); i2["record_id"] = "HR-IND-000002"; i2["supersedes_record_id"] = "HR-GRP-000001"
        proc, report = self.run_validator([g1, i2])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        by_id = {r["record_id"]: r for r in report["results"]}
        self.assertIn("X-SUPERSEDED", by_id["HR-GRP-000001"]["export_block_codes"])
        self.assertTrue(by_id["HR-IND-000002"]["export_eligible_canonical"])

    def test_alias_does_not_resolve_pending_mapping(self):
        rec = valid_individual()
        rec["ingredient_labels"] = ["Garlic"]
        rec["individual"].update(inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg"))
        proc, report = self.run_validator([rec], extra_rows=[inventory_row("IMG-009001", "Gralic/gralic_00001.jpg", "Gralic", "gralic_00001.jpg")])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "X-PENDING-CANONICAL")
        self.assertEqual(report["results"][0]["alias_resolutions"], [])

    def test_unknown_extra_field_rejected_except_b1_compat(self):
        rec = valid_individual()
        rec["unexpected_field"] = "x"
        proc, report = self.run_validator([rec])
        self.assertNotEqual(proc.returncode, 0)
        self.assert_has_code(report, "E-SCHEMA")

    def test_malformed_record_does_not_block_unrelated_valid_record(self):
        good = valid_individual(); good["record_id"] = "HR-IND-000010"
        # bad record targets different image so it does not create a same-image conflict
        bad = valid_individual(); bad["record_id"] = "HR-IND-000011"
        bad["individual"] = inventory_row("IMG-000003", "Black Pepper/black_pepper_00003.jpg", "Black Pepper", "black_pepper_00003.jpg")
        bad["ingredient_labels"] = ["not_a_real_label"]
        proc, report = self.run_validator([bad, good])
        self.assertNotEqual(proc.returncode, 0)
        by_id = {r["record_id"]: r for r in report["results"]}
        self.assertTrue(by_id["HR-IND-000010"]["export_eligible_canonical"])
        self.assertFalse(by_id["HR-IND-000011"]["export_eligible_canonical"])

    def test_report_paths_are_not_absolute_temp_paths(self):
        rec = valid_individual()
        proc, report = self.run_validator([rec])
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        for r in report["results"]:
            self.assertNotIn(":\\Users\\", r["source"])
        for i in report["issues"]:
            self.assertNotIn(":\\Users\\", i["source"])


if __name__ == "__main__":
    unittest.main()
