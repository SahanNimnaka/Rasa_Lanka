<?php

return [
    'b1_python_bin' => env('B1_PYTHON_BIN', 'python'),
    'b1_script_path' => env('B1_SCRIPT_PATH', base_path('../research/phase-13.3/annotation_pipeline_step8b/scripts/validate_step8b_records.py')),
    'b1_schema_path' => env('B1_SCHEMA_PATH', base_path('../research/phase-13.3/annotation_pipeline_step8a/config/human_recognition.schema.json')),
    'b1_inventory_path' => env('B1_INVENTORY_PATH', base_path('../research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv')),
    'b1_taxonomy_path' => env('B1_TAXONOMY_PATH', base_path('../research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json')),
    'b1_decisions_path' => env('B1_DECISIONS_PATH', base_path('../research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json')),
    'b1_timeout_seconds' => (int) env('B1_TIMEOUT_SECONDS', 60),
    'b1_batch_timeout_seconds' => (int) env('B1_BATCH_TIMEOUT_SECONDS', 300),
    'b1_workdir' => env('B1_WORKDIR', base_path('..')),
    'b1_temp_dir' => env('B1_TEMP_DIR', storage_path('app/b1-validation')),

    // B2-S1 read-only registry endpoints (metadata only; server-controlled paths).
    'registry_taxonomy_path' => env('ANNOTATION_REGISTRY_TAXONOMY_PATH', env('B1_TAXONOMY_PATH', base_path('../research/phase-13.3/annotation_pipeline/config/approved_taxonomy.json'))),
    'registry_inventory_path' => env('ANNOTATION_REGISTRY_INVENTORY_PATH', env('B1_INVENTORY_PATH', base_path('../research/phase-13.3/annotation_pipeline/exports/master_image_inventory.csv'))),
    'registry_decisions_path' => env('ANNOTATION_REGISTRY_DECISIONS_PATH', env('B1_DECISIONS_PATH', base_path('../research/phase-13.3/annotation_pipeline_step8a/config/label_mapping_decisions.json'))),
    'registry_image_id_registry_path' => env('ANNOTATION_REGISTRY_IMAGE_ID_REGISTRY_PATH', base_path('../research/phase-13.3/annotation_pipeline/config/image_id_registry.json')),
    'registry_cache_ttl_seconds' => (int) env('ANNOTATION_REGISTRY_CACHE_TTL_SECONDS', 30),
    'registry_inventory_default_per_page' => 25,
    'registry_inventory_max_per_page' => 100,

    // B2-S2-I4 protected local artifact storage (metadata/filesystem only; no DB lifecycle).
    'annotation_storage_disk' => env('ANNOTATION_STORAGE_DISK', 'annotation'),
    'annotation_storage_driver' => env('ANNOTATION_STORAGE_DRIVER', 'local'),
    'annotation_artifact_root' => env('ANNOTATION_ARTIFACT_ROOT', storage_path('app/private/annotation-artifacts')),
    'annotation_draft_versions_path' => env('ANNOTATION_DRAFT_VERSIONS_PATH', 'drafts'),
    'annotation_submitted_path' => env('ANNOTATION_SUBMITTED_PATH', 'submitted'),
    'annotation_validation_report_path' => env('ANNOTATION_VALIDATION_REPORT_PATH', 'validation-runs'),
    'annotation_temp_path' => env('ANNOTATION_TEMP_PATH', 'tmp'),
    'annotation_draft_retention_grace_days' => (int) env('ANNOTATION_DRAFT_RETENTION_GRACE_DAYS', 30),
    'annotation_retention_days' => (int) env('ANNOTATION_RETENTION_DAYS', 0),
    'annotation_promotion_lock_wait_seconds' => (int) env('ANNOTATION_PROMOTION_LOCK_WAIT_SECONDS', 5),
];
