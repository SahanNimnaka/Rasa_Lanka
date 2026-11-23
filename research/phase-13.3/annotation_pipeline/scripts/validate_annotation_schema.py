"""Validate the machine-generated annotation schema, configuration and sample records.

This script is a configuration check only. It does not read or modify images,
does not generate annotations for the dataset, and does not start classification.

Dependency: jsonschema >= 4.18 (pip install jsonschema)

Usage:
    python research/phase-13.3/annotation_pipeline/scripts/validate_annotation_schema.py
"""

from __future__ import annotations

import copy
import json
import sys
from pathlib import Path
from typing import Any, Callable

from jsonschema import Draft202012Validator, FormatChecker
from jsonschema.exceptions import SchemaError

SCRIPT_DIR = Path(__file__).resolve().parent
PIPELINE_DIR = SCRIPT_DIR.parent
CONFIG_DIR = PIPELINE_DIR / "config"
PHASE_DIR = PIPELINE_DIR.parent
DATASET_DIR = PHASE_DIR / "collected_dataset"

TAXONOMY_PATH = CONFIG_DIR / "approved_taxonomy.json"
SCHEMA_PATH = CONFIG_DIR / "machine_annotation.schema.json"
FORMAT_PATH = CONFIG_DIR / "machine_annotation_format.json"
MAPPING_PATH = CONFIG_DIR / "folder_class_mapping.json"

EXPECTED_IN_SCOPE = 43
EXPECTED_OUT_OF_SCOPE = 20
EXPECTED_STATES = 13
EXPECTED_FOLDERS = 35

SHA256_A = "a" * 64
SHA256_B = "b" * 64


class CheckReport:
    """Collects named checks and their outcomes."""

    def __init__(self) -> None:
        self.results: list[tuple[str, bool, str]] = []

    def record(self, name: str, passed: bool, detail: str = "") -> None:
        self.results.append((name, passed, detail))
        status = "PASS" if passed else "FAIL"
        print(f"[{status}] {name}" + (f" - {detail}" if detail else ""))

    def require(self, name: str, passed: bool, detail: str = "") -> bool:
        self.record(name, passed, detail)
        return passed

    @property
    def failed(self) -> int:
        return sum(1 for _, passed, _ in self.results if not passed)


def load_json(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise TypeError(f"Expected a JSON object in {path}")
    return data


def base_record() -> dict[str, Any]:
    """A valid folder-proposal record with no model prediction."""
    return {
        "schema_version": "machine-annotation-v1",
        "annotation_id": "MA-0123456789abcdef0123456789abcdef",
        "image_id": "IMG-fedcba9876543210fedcba9876543210",
        "batch_id": "BATCH-2026-11-24-001",
        "original_folder": "Cardamom",
        "original_filename": "cardamom_001_jpg.rf.0123456789abcdef0123456789abcdef.jpg",
        "relative_path": "Cardamom/cardamom_001_jpg.rf.0123456789abcdef0123456789abcdef.jpg",
        "image_sha256": SHA256_A,
        "folder_proposed_class": "cardamom_pod",
        "folder_mapping_status": "proposed_pending_review",
        "machine_labels": [
            {"class_id": "cardamom_pod", "confidence": None, "status": "folder_proposed"}
        ],
        "uncertain_labels": [],
        "out_of_scope_labels": [],
        "visible_ingredient_count": 1,
        "multi_ingredient_scene": False,
        "quality_flags": {
            "low_resolution": False,
            "severe_low_resolution": False,
            "blur_suspected": False,
            "text_detected": False,
            "watermark_suspected": False,
            "packaging_dependence_suspected": False,
            "tiny_subject": False,
            "needs_manual_qa": False,
            "screen_method": "none",
        },
        "processing_state_evidence": ["whole"],
        "processing_status": "labelled",
        "human_review": {
            "human_verified": False,
            "review_status": "not_reviewed",
            "verified_by": None,
            "verification_timestamp": None,
            "review_notes": "",
        },
        "annotation_method": "machine_generated",
        "generator": {
            "method": "folder_proposal",
            "pipeline_version": "step5-validation-1",
            "model_id": None,
            "model_version": None,
        },
        "processing_notes": "Folder-based proposal only; no model prediction run.",
    }


def multi_label_record() -> dict[str, Any]:
    """A valid model-backed record with two visible ingredients."""
    record = base_record()
    record["annotation_id"] = "MA-11111111111111111111111111111111"
    record["image_id"] = "IMG-22222222222222222222222222222222"
    record["original_folder"] = "Fish"
    record["original_filename"] = "fish_onion_001.jpg"
    record["relative_path"] = "Fish/fish_onion_001.jpg"
    record["image_sha256"] = SHA256_B
    record["folder_proposed_class"] = "fish"
    record["folder_mapping_status"] = "proposed_pending_review"
    record["machine_labels"] = [
        {"class_id": "fish", "confidence": 0.91, "status": "predicted"},
        {"class_id": "onion", "confidence": 0.73, "status": "predicted"},
    ]
    record["uncertain_labels"] = [
        {"class_id": "small_onion", "confidence": 0.41, "status": "uncertain"}
    ]
    record["out_of_scope_labels"] = [
        {"class_id": "salt", "confidence": None, "status": "out_of_scope"}
    ]
    record["visible_ingredient_count"] = 2
    record["multi_ingredient_scene"] = True
    record["generator"] = {
        "method": "model_prediction",
        "pipeline_version": "step5-validation-1",
        "model_id": "test-model-rf",
        "model_version": "0.1.0-test",
    }
    record["processing_notes"] = "Synthetic example for schema validation only."
    return record


def build_cases() -> list[tuple[str, dict[str, Any] | Any, bool, str]]:
    """Return (name, record, expected_valid, description) test cases."""
    cases: list[tuple[str, dict[str, Any] | Any, bool, str]] = []

    valid = base_record()
    cases.append(("valid_machine_generated_record", valid, True, "folder proposal, null confidence"))

    bad_class = base_record()
    bad_class["machine_labels"][0]["class_id"] = "not_an_approved_class"
    cases.append(("invalid_class_id", bad_class, False, "unapproved in-scope class ID"))

    missing_image_id = base_record()
    del missing_image_id["image_id"]
    cases.append(("missing_image_id", missing_image_id, False, "required image_id removed"))

    missing_filename = base_record()
    del missing_filename["original_filename"]
    cases.append(("missing_original_filename", missing_filename, False, "required filename removed"))

    bad_conf = multi_label_record()
    bad_conf["machine_labels"][0]["confidence"] = 1.5
    cases.append(("invalid_confidence_value", bad_conf, False, "confidence above 1.0"))

    cases.append(("multiple_ingredient_labels", multi_label_record(), True, "two model predictions"))

    null_conf = base_record()
    cases.append(("null_confidence", null_conf, True, "confidence is null, as required without a model"))

    unverified = base_record()
    unverified["human_review"] = {"human_verified": True, "review_status": "reviewed"}
    cases.append(
        (
            "human_verified_true_without_evidence",
            unverified,
            False,
            "missing verifier ID and verification timestamp",
        )
    )

    # Additional guard rails.
    omitted_verified = base_record()
    del omitted_verified["human_review"]["human_verified"]
    cases.append(
        (
            "human_verified_omitted_defaults_to_false",
            omitted_verified,
            True,
            "human_verified omitted; declared default is false",
        )
    )

    human_review_omitted = base_record()
    del human_review_omitted["human_review"]
    cases.append(
        (
            "human_review_block_required",
            human_review_omitted,
            False,
            "human_review block is mandatory on every record",
        )
    )

    fake_conf = base_record()
    fake_conf["machine_labels"][0]["confidence"] = 0.99
    cases.append(
        ("folder_proposal_with_confidence", fake_conf, False, "confidence fabricated without a model")
    )

    wrong_method = base_record()
    wrong_method["annotation_method"] = "human_verified"
    cases.append(("wrong_annotation_method", wrong_method, False, "must be machine_generated"))

    oos_in_machine = base_record()
    oos_in_machine["machine_labels"][0]["class_id"] = "salt"
    cases.append(("out_of_scope_id_in_machine_labels", oos_in_machine, False, "wrong label array"))

    verified_ok = base_record()
    verified_ok["human_review"] = {
        "human_verified": True,
        "review_status": "reviewed",
        "verified_by": "ANNTR-001",
        "verification_timestamp": "2026-11-24T10:15:00Z",
        "review_notes": "Reviewed by human.",
    }
    cases.append(("human_verified_true_with_evidence", verified_ok, True, "evidence present"))

    null_verifier = base_record()
    null_verifier["human_review"] = {
        "human_verified": True,
        "review_status": "reviewed",
        "verified_by": None,
        "verification_timestamp": "2026-11-24T10:15:00Z",
    }
    cases.append(("human_verified_with_null_verifier", null_verifier, False, "verifier must be a real string"))

    multi_flag_false = multi_label_record()
    multi_flag_false["multi_ingredient_scene"] = False
    cases.append(("multi_label_without_scene_flag", multi_flag_false, False, "inconsistent multi-ingredient flags"))

    extra_field = base_record()
    extra_field["unexpected_field"] = "x"
    cases.append(("unknown_top_level_field", extra_field, False, "additionalProperties is false"))

    return cases


def check_schema_consistency(
    report: CheckReport,
    schema: dict[str, Any],
    taxonomy: dict[str, Any],
    format_spec: dict[str, Any],
    mapping: dict[str, Any],
) -> Draft202012Validator:
    """Validate the schema document and its alignment with the approved taxonomy."""
    try:
        Draft202012Validator.check_schema(schema)
        report.record("schema_is_valid_draft_2020_12", True)
    except SchemaError as exc:
        report.record("schema_is_valid_draft_2020_12", False, str(exc).splitlines()[0])
        raise

    defs = schema.get("$defs", {})
    expected = {
        "in_scope": ("inScopeClassId", taxonomy["in_scope_classes"], EXPECTED_IN_SCOPE),
        "out_of_scope": ("outOfScopeClassId", taxonomy["out_of_scope_classes"], EXPECTED_OUT_OF_SCOPE),
        "processing_states": ("processingState", taxonomy["processing_states"], EXPECTED_STATES),
    }
    for label, (def_name, values, count) in expected.items():
        schema_values = defs.get(def_name, {}).get("enum")
        report.require(
            f"taxonomy_alignment_{label}",
            schema_values == values and len(values) == count,
            f"{len(values)} approved values",
        )

    human_default = (
        defs.get("humanReview", {})
        .get("properties", {})
        .get("human_verified", {})
        .get("default", "MISSING")
    )
    report.require("human_verified_defaults_to_false", human_default is False, f"default={human_default!r}")

    conf_default = defs.get("confidence", {}).get("default", "MISSING")
    report.require("confidence_defaults_to_null", conf_default is None, f"default={conf_default!r}")

    report.require(
        "method_discriminator_is_machine_generated",
        schema.get("properties", {}).get("annotation_method", {}).get("const") == "machine_generated",
    )
    report.require(
        "schema_id_differs_from_human_schema",
        schema.get("$id") != "https://rasalanka.local/schemas/pilot_annotation_v1.json",
    )
    report.require(
        "example_record_in_format_spec_is_valid",
        Draft202012Validator(schema, format_checker=FormatChecker()).is_valid(
            format_spec.get("example_valid_record")
        ),
    )
    report.require(
        "folder_mapping_covers_all_folders",
        mapping.get("summary", {}).get("folder_count") == EXPECTED_FOLDERS
        and len(mapping.get("mappings", [])) == EXPECTED_FOLDERS,
        f"mapping entries={len(mapping.get('mappings', []))}",
    )

    approved_in = set(taxonomy["in_scope_classes"])
    approved_out = set(taxonomy["out_of_scope_classes"])
    approved_states = set(taxonomy["processing_states"])
    bad_ids: list[str] = []
    for entry in mapping.get("mappings", []):
        if entry.get("proposed_class_id") is not None and entry["proposed_class_id"] not in approved_in:
            bad_ids.append(f"{entry['folder_name']}:proposed")
        for cid in entry.get("candidate_class_ids", []):
            if cid not in approved_in:
                bad_ids.append(f"{entry['folder_name']}:candidate:{cid}")
        oos = entry.get("out_of_scope_class_id")
        if oos is not None and oos not in approved_out:
            bad_ids.append(f"{entry['folder_name']}:out_of_scope:{oos}")
    report.require("folder_mapping_uses_only_approved_ids", not bad_ids, ", ".join(bad_ids))

    if DATASET_DIR.is_dir():
        actual = {d.name for d in DATASET_DIR.iterdir() if d.is_dir()}
        configured = {e["folder_name"] for e in mapping.get("mappings", [])}
        report.require(
            "folder_mapping_matches_dataset_folders",
            actual == configured,
            f"dataset={len(actual)} configured={len(configured)}",
        )
    else:
        report.record("folder_mapping_matches_dataset_folders", True, "dataset absent; check skipped")

    return Draft202012Validator(schema, format_checker=FormatChecker())


def run_record_tests(report: CheckReport, validator: Draft202012Validator) -> None:
    """Run valid and invalid sample records through the schema."""
    for name, record, expected_valid, description in build_cases():
        try:
            actual_valid = validator.is_valid(record)
        except Exception as exc:  # pragma: no cover - defensive against malformed samples
            actual_valid = False
            description = f"{description}; validator error: {type(exc).__name__}"
        report.require(
            name,
            actual_valid == expected_valid,
            f"expected={'valid' if expected_valid else 'invalid'} got={'valid' if actual_valid else 'invalid'}; {description}",
        )


def main() -> int:
    print("=" * 72)
    print("RasaLanka machine-generated annotation format validation")
    print("=" * 72)
    report = CheckReport()

    for path in (TAXONOMY_PATH, SCHEMA_PATH, FORMAT_PATH, MAPPING_PATH):
        if not path.is_file():
            print(f"[FAIL] required file missing: {path}")
            return 1

    taxonomy = load_json(TAXONOMY_PATH)
    schema = load_json(SCHEMA_PATH)
    format_spec = load_json(FORMAT_PATH)
    mapping = load_json(MAPPING_PATH)

    report.require(
        "taxonomy_counts",
        len(taxonomy["in_scope_classes"]) == EXPECTED_IN_SCOPE
        and len(taxonomy["out_of_scope_classes"]) == EXPECTED_OUT_OF_SCOPE
        and len(taxonomy["processing_states"]) == EXPECTED_STATES,
    )

    try:
        validator = check_schema_consistency(report, schema, taxonomy, format_spec, mapping)
    except SchemaError:
        print("\nVALIDATION ABORTED: schema document is invalid.")
        return 1

    run_record_tests(report, validator)

    total = len(report.results)
    passed = total - report.failed
    print("-" * 72)
    print(f"RESULT: {passed}/{total} checks passed, {report.failed} failed")
    if report.failed:
        print("VALIDATION FAILED - do not commit.")
        return 1
    print("VALIDATION PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
