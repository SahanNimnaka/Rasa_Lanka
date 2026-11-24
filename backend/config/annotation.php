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
];
