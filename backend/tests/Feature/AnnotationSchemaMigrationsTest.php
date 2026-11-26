<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnnotationSchemaMigrationsTest extends TestCase
{
    private const TABLES = [
        'annotation_records',
        'annotation_validation_runs',
        'annotation_validation_issues',
        'annotation_record_images',
        'annotation_labels',
        'annotation_review_actions',
    ];

    private string $schemaTestConnection = 'sqlite';

    protected function setUp(): void
    {
        parent::setUp();

        $requestedConnection = strtolower(trim((string) env('ANNOTATION_SCHEMA_TEST_CONNECTION', 'sqlite')));

        if ($requestedConnection === '' || $requestedConnection === 'sqlite') {
            $this->schemaTestConnection = 'sqlite';
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.foreign_key_constraints' => true,
            ]);
            DB::purge('sqlite');
            DB::setDefaultConnection('sqlite');

            $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])
                ->assertExitCode(0);

            return;
        }

        if ($requestedConnection !== 'pgsql') {
            $this->markTestSkipped('AnnotationSchemaMigrationsTest supports sqlite and pgsql only.');
        }

        $this->schemaTestConnection = 'pgsql';
        $this->guardPgsqlDestructiveTesting();

        config(['database.default' => 'pgsql']);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        $this->artisan('migrate:fresh', ['--database' => 'pgsql', '--force' => true])
            ->assertExitCode(0);
    }

    private function guardPgsqlDestructiveTesting(): void
    {
        $allowDestructive = filter_var(
            env('ANNOTATION_SCHEMA_TEST_ALLOW_DESTRUCTIVE', false),
            FILTER_VALIDATE_BOOLEAN
        );
        if (! $allowDestructive) {
            $this->fail(
                'Refusing PostgreSQL migrate:fresh. Set ANNOTATION_SCHEMA_TEST_CONNECTION=pgsql and '.
                'ANNOTATION_SCHEMA_TEST_ALLOW_DESTRUCTIVE=true only for a disposable PostgreSQL test database.'
            );
        }

        $database = trim((string) config('database.connections.pgsql.database', ''));
        if ($database === '') {
            $this->fail('Refusing PostgreSQL migrate:fresh because the configured pgsql database name is empty.');
        }

        $databaseLower = strtolower($database);
        $isAllowedRasalankaTestDb = $databaseLower === 'rasalanka_s2_test'
            || preg_match('/^rasalanka_s2_test(_[a-z0-9]+)*$/', $databaseLower) === 1;
        $isAllowedGenericTestDb = preg_match('/^[a-z0-9][a-z0-9_]*_test$/', $databaseLower) === 1;
        $isAllowed = $isAllowedRasalankaTestDb || $isAllowedGenericTestDb;

        if (str_contains($databaseLower, 'rasalanka') && ! $isAllowedRasalankaTestDb) {
            $isAllowed = false;
        }

        $productionLikePatterns = [
            '/(^|_)(prod|production|live|main|master|primary)(_|$)/',
            '/^rasalanka$/',
            '/supabase/',
        ];
        foreach ($productionLikePatterns as $pattern) {
            if (preg_match($pattern, $databaseLower) === 1) {
                $isAllowed = false;
                break;
            }
        }

        $host = strtolower(trim((string) config('database.connections.pgsql.host', '')));
        if ($host !== '' && str_contains($host, 'supabase')) {
            $isAllowed = false;
        }

        if (! $isAllowed) {
            $this->fail(
                'Refusing PostgreSQL migrate:fresh because the configured pgsql database is not clearly disposable. '.
                'Use a dedicated test database name such as rasalanka_s2_test and explicit opt-in.'
            );
        }
    }

    protected function tearDown(): void
    {
        DB::purge($this->schemaTestConnection);

        parent::tearDown();
    }

    public function test_all_s2_tables_and_specified_columns_are_created_additively(): void
    {
        $expectedColumns = [
            'annotation_records' => [
                'id', 'record_id', 'schema_version', 'recognition_method', 'verification_status',
                'workflow_status', 'artifact_state', 'current_draft_version_id', 'current_draft_path',
                'current_draft_sha256', 'submitted_json_path', 'submitted_artifact_sha256',
                'submission_intent_id', 'submission_state', 'submission_staging_path',
                'submission_staging_sha256', 'submission_started_at', 'submission_finalized_at',
                'supersedes_record_id', 'superseded_by_record_id', 'created_by_user_id',
                'submitted_by_user_id', 'reviewed_by_user_id', 'submitted_at', 'reviewed_at',
                'is_export_eligible_canonical', 'latest_validation_run_id', 'latest_b1_valid',
                'lock_version', 'discarded_at', 'discard_reason', 'created_at', 'updated_at',
            ],
            'annotation_validation_runs' => [
                'id', 'run_id', 'annotation_record_id', 'submission_intent_id', 'batch_run_id',
                'b1_version', 'input_artifact_path', 'input_artifact_sha256', 'input_evidence_state',
                'durable_input_evidence_path', 'durable_input_evidence_sha256', 'report_json_path',
                'report_json_sha256', 'report_csv_path', 'exit_code', 'is_valid',
                'is_export_eligible_canonical', 'error_count', 'warning_count', 'export_block_count',
                'status', 'infra_error_code', 'infra_error_message', 'timed_out', 'duration_ms',
                'executed_by_user_id', 'executed_at', 'created_at', 'updated_at',
            ],
            'annotation_validation_issues' => [
                'id', 'validation_run_id', 'record_id', 'severity', 'code', 'field', 'message',
                'details_json', 'created_at', 'updated_at',
            ],
            'annotation_record_images' => [
                'id', 'annotation_record_id', 'image_id', 'relative_path', 'original_folder',
                'original_filename', 'membership_role', 'created_at', 'updated_at',
            ],
            'annotation_labels' => [
                'id', 'annotation_record_id', 'label_array', 'raw_label', 'resolved_label',
                'label_class', 'is_pending_mapping', 'is_alias_resolved', 'created_at', 'updated_at',
            ],
            'annotation_review_actions' => [
                'id', 'annotation_record_id', 'actor_user_id', 'actor_type', 'action',
                'from_workflow_status', 'to_workflow_status', 'comment', 'evidence_ref',
                'metadata_json', 'created_at',
            ],
        ];

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing S2 table {$table}.");
            $actual = Schema::getColumnListing($table);
            sort($actual);
            $expected = $expectedColumns[$table];
            sort($expected);
            $this->assertSame($expected, $actual, "Unexpected columns in {$table}.");
        }

        $this->assertFalse(Schema::hasTable('annotation_images'));
        foreach ([
            'users',
            'recipes',
            'ingredients',
            'recipe_categories',
            'ingredient_categories',
            'recipe_ingredients',
            'recipe_instructions',
            'recipe_sources',
            'data_quality_issues',
            'saved_recipes',
            'recipe_views',
        ] as $existingTable) {
            $this->assertTrue(Schema::hasTable($existingTable), "Existing table {$existingTable} was removed.");
        }
        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertTrue(Schema::hasColumn('recipes', 'recipe_code'));
        $this->assertTrue(Schema::hasColumn('recipe_ingredients', 'original_requirement_label'));
        $this->assertFalse(Schema::hasColumn('annotation_review_actions', 'updated_at'));
    }

    public function test_required_types_nullability_and_defaults_are_preserved(): void
    {
        $this->assertColumn('annotation_records', 'record_id', 'varchar', false);
        $this->assertColumn('annotation_records', 'schema_version', 'varchar', false, 'human-recognition-v1');
        $this->assertColumn('annotation_records', 'workflow_status', 'varchar', false, 'draft');
        $this->assertColumn('annotation_records', 'artifact_state', 'varchar', false, 'draft');
        $this->assertColumn('annotation_records', 'submission_state', 'varchar', false, 'none');
        $this->assertColumn('annotation_records', 'created_by_user_id', 'integer', false, null, 'int8');
        $this->assertColumn('annotation_records', 'submitted_by_user_id', 'integer', true, null, 'int8');
        $this->assertColumn('annotation_records', 'submitted_json_path', 'varchar', true);
        $this->assertVarcharLength('annotation_records', 'submitted_json_path', 1024);
        $this->assertColumn('annotation_records', 'submitted_artifact_sha256', 'varchar', true, null, 'bpchar');
        $this->assertColumn('annotation_records', 'is_export_eligible_canonical', 'tinyint', false, '0', 'bool');
        $this->assertColumn('annotation_records', 'lock_version', 'integer', false, '1', 'int4');
        $this->assertColumn('annotation_records', 'created_at', 'datetime', false, null, 'timestamp');

        $this->assertColumn('annotation_validation_runs', 'annotation_record_id', 'integer', false, null, 'int8');
        $this->assertColumn('annotation_validation_runs', 'input_artifact_path', 'varchar', false);
        $this->assertColumn('annotation_validation_runs', 'input_artifact_sha256', 'varchar', false, null, 'bpchar');
        $this->assertColumn('annotation_validation_runs', 'input_evidence_state', 'varchar', false, 'staged_path');
        $this->assertColumn('annotation_validation_runs', 'durable_input_evidence_path', 'varchar', true);
        $this->assertColumn('annotation_validation_runs', 'durable_input_evidence_sha256', 'varchar', true, null, 'bpchar');
        $this->assertColumn('annotation_validation_runs', 'error_count', 'integer', false, '0');
        $this->assertColumn('annotation_validation_runs', 'timed_out', 'tinyint', false, '0', 'bool');
        $this->assertColumn('annotation_validation_runs', 'executed_at', 'datetime', false, null, 'timestamp');

        $this->assertColumn('annotation_validation_issues', 'validation_run_id', 'integer', false, null, 'int8');
        $this->assertColumn('annotation_validation_issues', 'record_id', 'varchar', true);
        $this->assertColumn('annotation_validation_issues', 'details_json', 'text', true, null, 'json');
        $this->assertColumn('annotation_record_images', 'annotation_record_id', 'integer', false, null, 'int8');
        $this->assertColumn('annotation_record_images', 'image_id', 'varchar', false);
        $this->assertColumn('annotation_labels', 'annotation_record_id', 'integer', false, null, 'int8');
        $this->assertColumn('annotation_labels', 'is_pending_mapping', 'tinyint', false, '0', 'bool');
        $this->assertColumn('annotation_review_actions', 'actor_user_id', 'integer', true, null, 'int8');
        $this->assertColumn('annotation_review_actions', 'actor_type', 'varchar', false, 'user');
        $this->assertColumn('annotation_review_actions', 'metadata_json', 'text', true, null, 'json');
        $this->assertColumn('annotation_review_actions', 'created_at', 'datetime', false, null, 'timestamp');
    }

    public function test_declared_indexes_and_unique_constraints_exist(): void
    {
        $indexes = [
            'annotation_records' => [
                ['record_id'], ['schema_version'], ['recognition_method'], ['verification_status'],
                ['workflow_status'], ['artifact_state'], ['current_draft_version_id'],
                ['current_draft_sha256'], ['submitted_json_path'], ['submitted_artifact_sha256'],
                ['submission_intent_id'], ['submission_state'], ['submission_staging_sha256'],
                ['submission_started_at'], ['submission_finalized_at'], ['supersedes_record_id'],
                ['superseded_by_record_id'], ['created_by_user_id'], ['submitted_by_user_id'],
                ['reviewed_by_user_id'], ['submitted_at'], ['reviewed_at'],
                ['is_export_eligible_canonical'], ['latest_validation_run_id'], ['latest_b1_valid'],
                ['discarded_at'],
            ],
            'annotation_validation_runs' => [
                ['run_id'], ['annotation_record_id'], ['submission_intent_id'], ['batch_run_id'],
                ['b1_version'], ['input_artifact_sha256'], ['input_evidence_state'],
                ['durable_input_evidence_sha256'], ['report_json_sha256'], ['exit_code'], ['is_valid'],
                ['is_export_eligible_canonical'], ['status'], ['infra_error_code'], ['timed_out'],
                ['executed_by_user_id'], ['executed_at'],
            ],
            'annotation_validation_issues' => [
                ['validation_run_id'], ['record_id'], ['severity'], ['code'], ['field'],
            ],
            'annotation_record_images' => [
                ['annotation_record_id'], ['image_id'], ['relative_path'], ['original_folder'],
                ['membership_role'],
            ],
            'annotation_labels' => [
                ['annotation_record_id'], ['label_array'], ['raw_label'], ['resolved_label'],
                ['label_class'], ['is_pending_mapping'], ['is_alias_resolved'],
            ],
            'annotation_review_actions' => [
                ['annotation_record_id'], ['actor_user_id'], ['actor_type'], ['action'],
                ['from_workflow_status'], ['to_workflow_status'], ['created_at'],
            ],
        ];

        foreach ($indexes as $table => $columnsList) {
            foreach ($columnsList as $columns) {
                $this->assertTrue(
                    Schema::hasIndex($table, $columns),
                    "Missing index on {$table}.".implode('.', $columns)
                );
            }
        }

        $this->assertTrue(Schema::hasIndex('annotation_records', ['record_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('annotation_records', ['submitted_json_path'], 'unique'));
        $this->assertTrue(Schema::hasIndex('annotation_validation_runs', ['run_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex(
            'annotation_record_images',
            ['annotation_record_id', 'image_id'],
            'unique'
        ));
        $this->assertTrue(Schema::hasIndex(
            'annotation_labels',
            ['annotation_record_id', 'label_array', 'raw_label'],
            'unique'
        ));
    }

    public function test_all_required_foreign_keys_use_the_specified_delete_actions(): void
    {
        $foreignKeys = [
            ['annotation_records', 'created_by_user_id', 'users', 'id', 'restrict'],
            ['annotation_records', 'submitted_by_user_id', 'users', 'id', 'restrict'],
            ['annotation_records', 'reviewed_by_user_id', 'users', 'id', 'restrict'],
            ['annotation_records', 'latest_validation_run_id', 'annotation_validation_runs', 'id', 'set null'],
            ['annotation_records', 'supersedes_record_id', 'annotation_records', 'record_id', 'restrict'],
            ['annotation_records', 'superseded_by_record_id', 'annotation_records', 'record_id', 'restrict'],
            ['annotation_validation_runs', 'annotation_record_id', 'annotation_records', 'id', 'restrict'],
            ['annotation_validation_runs', 'executed_by_user_id', 'users', 'id', 'restrict'],
            ['annotation_validation_issues', 'validation_run_id', 'annotation_validation_runs', 'id', 'restrict'],
            ['annotation_record_images', 'annotation_record_id', 'annotation_records', 'id', 'restrict'],
            ['annotation_labels', 'annotation_record_id', 'annotation_records', 'id', 'restrict'],
            ['annotation_review_actions', 'annotation_record_id', 'annotation_records', 'id', 'restrict'],
            ['annotation_review_actions', 'actor_user_id', 'users', 'id', 'restrict'],
        ];

        foreach ($foreignKeys as [$table, $column, $targetTable, $targetColumn, $onDelete]) {
            $foreignKey = collect(Schema::getForeignKeys($table))->first(
                fn (array $candidate): bool => $candidate['columns'] === [$column]
                    && $candidate['foreign_table'] === $targetTable
                    && $candidate['foreign_columns'] === [$targetColumn]
            );

            $this->assertNotNull($foreignKey, "Missing foreign key {$table}.{$column}.");
            $this->assertSame($onDelete, $foreignKey['on_delete']);
        }
    }

    public function test_latest_validation_run_delete_sets_pointer_to_null(): void
    {
        $recordId = $this->createRecord();
        $runId = $this->createValidationRun($recordId);

        DB::table('annotation_records')
            ->where('id', $recordId)
            ->update(['latest_validation_run_id' => $runId]);

        DB::table('annotation_validation_runs')->where('id', $runId)->delete();

        $this->assertNull(DB::table('annotation_records')->where('id', $recordId)->value('latest_validation_run_id'));
    }

    public function test_required_and_restrictive_foreign_keys_are_enforced(): void
    {
        $this->assertQueryException(function (): void {
            $this->createRecord(['created_by_user_id' => 999999]);
        });

        $userRecordId = $this->createRecord();
        $creatorId = DB::table('annotation_records')
            ->where('id', $userRecordId)
            ->value('created_by_user_id');
        $this->assertQueryException(function () use ($creatorId): void {
            DB::table('users')->where('id', $creatorId)->delete();
        });

        $recordId = $this->createRecord();
        $this->assertQueryException(function () use ($recordId): void {
            DB::table('annotation_validation_runs')->insert([
                'run_id' => 'missing-path-'.uniqid(),
                'annotation_record_id' => $recordId,
                'input_artifact_path' => null,
                'input_artifact_sha256' => str_repeat('a', 64),
                'status' => 'completed',
                'executed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->assertQueryException(function () use ($recordId): void {
            DB::table('annotation_validation_runs')->insert([
                'run_id' => 'missing-hash-'.uniqid(),
                'annotation_record_id' => $recordId,
                'input_artifact_path' => 'submitted/test/staging/input.json',
                'input_artifact_sha256' => null,
                'status' => 'completed',
                'executed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $runId = $this->createValidationRun($recordId);

        $this->assertQueryException(function () use ($recordId): void {
            DB::table('annotation_records')->where('id', $recordId)->delete();
        });

        $issueId = DB::table('annotation_validation_issues')->insertGetId([
            'validation_run_id' => $runId,
            'severity' => 'error',
            'code' => 'TEST_ERROR',
            'message' => 'Migration constraint test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, $issueId);
        $this->assertQueryException(function () use ($runId): void {
            DB::table('annotation_validation_runs')->where('id', $runId)->delete();
        });

        $imageRecordId = $this->createRecord();
        DB::table('annotation_record_images')->insert([
            'annotation_record_id' => $imageRecordId,
            'image_id' => 'IMG-000001',
            'relative_path' => 'images/test.jpg',
            'original_folder' => 'test',
            'original_filename' => 'test.jpg',
            'membership_role' => 'individual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertQueryException(function () use ($imageRecordId): void {
            DB::table('annotation_records')->where('id', $imageRecordId)->delete();
        });

        $labelRecordId = $this->createRecord();
        DB::table('annotation_labels')->insert([
            'annotation_record_id' => $labelRecordId,
            'label_array' => 'ingredient_labels',
            'raw_label' => 'ginger',
            'is_pending_mapping' => false,
            'is_alias_resolved' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertQueryException(function () use ($labelRecordId): void {
            DB::table('annotation_records')->where('id', $labelRecordId)->delete();
        });

        $actionRecordId = $this->createRecord();
        DB::table('annotation_review_actions')->insert([
            'annotation_record_id' => $actionRecordId,
            'actor_type' => 'user',
            'actor_user_id' => DB::table('annotation_records')->where('id', $actionRecordId)->value('created_by_user_id'),
            'action' => 'created',
            'created_at' => now(),
        ]);
        $this->assertQueryException(function () use ($actionRecordId): void {
            DB::table('annotation_records')->where('id', $actionRecordId)->delete();
        });
    }

    public function test_supersession_foreign_keys_restrict_deletes_and_reject_missing_records(): void
    {
        $sourceRecordId = $this->createRecord(['record_id' => 'source-record']);
        $this->createRecord([
            'record_id' => 'correction-record',
            'supersedes_record_id' => 'source-record',
        ]);

        $this->assertQueryException(function () use ($sourceRecordId): void {
            DB::table('annotation_records')->where('id', $sourceRecordId)->delete();
        });

        $replacementId = $this->createRecord(['record_id' => 'replacement-record']);
        $this->createRecord([
            'record_id' => 'superseded-record',
            'superseded_by_record_id' => 'replacement-record',
        ]);
        $this->assertQueryException(function () use ($replacementId): void {
            DB::table('annotation_records')->where('id', $replacementId)->delete();
        });

        $this->assertQueryException(function (): void {
            $this->createRecord([
                'record_id' => 'invalid-supersession',
                'supersedes_record_id' => 'not-a-record',
            ]);
        });
    }

    public function test_unique_constraints_reject_duplicate_record_run_image_and_label_keys(): void
    {
        $firstRecordId = $this->createRecord([
            'record_id' => 'unique-record-one',
            'submitted_json_path' => 'submitted/unique/record.json',
        ]);
        $this->assertQueryException(function (): void {
            $this->createRecord(['record_id' => 'unique-record-one']);
        });
        $this->assertQueryException(function (): void {
            $this->createRecord([
                'record_id' => 'unique-record-two',
                'submitted_json_path' => 'submitted/unique/record.json',
            ]);
        });

        $runId = $this->createValidationRun($firstRecordId, ['run_id' => 'unique-run']);
        $this->assertGreaterThan(0, $runId);
        $this->assertQueryException(function () use ($firstRecordId): void {
            $this->createValidationRun($firstRecordId, ['run_id' => 'unique-run']);
        });

        DB::table('annotation_record_images')->insert([
            'annotation_record_id' => $firstRecordId,
            'image_id' => 'IMG-000099',
            'relative_path' => 'images/one.jpg',
            'original_folder' => 'one',
            'original_filename' => 'one.jpg',
            'membership_role' => 'individual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertQueryException(function () use ($firstRecordId): void {
            DB::table('annotation_record_images')->insert([
                'annotation_record_id' => $firstRecordId,
                'image_id' => 'IMG-000099',
                'relative_path' => 'images/duplicate.jpg',
                'original_folder' => 'duplicate',
                'original_filename' => 'duplicate.jpg',
                'membership_role' => 'individual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('annotation_labels')->insert([
            'annotation_record_id' => $firstRecordId,
            'label_array' => 'ingredient_labels',
            'raw_label' => 'pepper',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertQueryException(function () use ($firstRecordId): void {
            DB::table('annotation_labels')->insert([
                'annotation_record_id' => $firstRecordId,
                'label_array' => 'ingredient_labels',
                'raw_label' => 'pepper',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_s2_migrations_roll_back_in_reverse_order_without_dropping_existing_tables(): void
    {
        $usersColumns = Schema::getColumnListing('users');
        $recipesColumns = Schema::getColumnListing('recipes');

        $this->artisan('migrate:rollback', [
            '--database' => $this->schemaTestConnection,
            '--step' => 7,
            '--force' => true,
        ])->assertExitCode(0);

        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "Rollback left {$table} behind.");
        }
        $this->assertSame($usersColumns, Schema::getColumnListing('users'));
        $this->assertSame($recipesColumns, Schema::getColumnListing('recipes'));

        $this->artisan('migrate', ['--database' => $this->schemaTestConnection, '--force' => true])
            ->assertExitCode(0);
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Re-migration did not recreate {$table}.");
        }
    }

    private function assertColumn(
        string $table,
        string $name,
        string $sqliteType,
        bool $nullable,
        ?string $default = null,
        ?string $pgsqlType = null
    ): void {
        $column = collect(Schema::getColumns($table))->firstWhere('name', $name);

        $this->assertNotNull($column, "Missing {$table}.{$name}.");
        $expectedType = $this->schemaTestConnection === 'pgsql'
            ? ($pgsqlType ?? $this->expectedPgsqlType($sqliteType))
            : $sqliteType;
        $this->assertSame($expectedType, $column['type_name'], "Wrong type for {$table}.{$name}.");
        $this->assertSame($nullable, $column['nullable'], "Wrong nullability for {$table}.{$name}.");
        $this->assertSame(
            $this->normalizeDefault($default),
            $this->normalizeDefault($column['default']),
            "Wrong default for {$table}.{$name}."
        );
    }

    private function assertVarcharLength(string $table, string $name, int $length): void
    {
        $actualLength = $this->schemaTestConnection === 'pgsql'
            ? $this->pgsqlVarcharLength($table, $name)
            : $this->sqliteVarcharLength($table, $name);

        $this->assertSame($length, $actualLength, "Wrong varchar length for {$table}.{$name}.");
    }

    private function pgsqlVarcharLength(string $table, string $name): ?int
    {
        $row = DB::selectOne(
            'select character_maximum_length
             from information_schema.columns
             where table_schema = current_schema()
               and table_name = ?
               and column_name = ?',
            [$table, $name]
        );

        if ($row === null || $row->character_maximum_length === null) {
            return null;
        }

        return (int) $row->character_maximum_length;
    }

    private function sqliteVarcharLength(string $table, string $name): ?int
    {
        $row = DB::selectOne(
            "select sql from sqlite_master where type = 'table' and name = ?",
            [$table]
        );

        if ($row === null || ! isset($row->sql)) {
            return null;
        }

        $pattern = '/["`\[]?'.preg_quote($name, '/').'["`\]]?\s+varchar\s*\(\s*(\d+)\s*\)/i';
        if (preg_match($pattern, (string) $row->sql, $matches) === 1) {
            return (int) $matches[1];
        }

        // SQLite's schema SQL may omit explicit varchar lengths; fall back to
        // the migration definition for this specific S2 schema assertion.
        $migrationPath = base_path('database/migrations/2026_11_26_000001_create_annotation_records_table.php');
        if (is_file($migrationPath)) {
            $migration = (string) file_get_contents($migrationPath);
            $migrationPattern = '/->string\(\s*[\'"]'.preg_quote($name, '/').'[\'\"]\s*,\s*(\d+)\s*\)/';
            if (preg_match($migrationPattern, $migration, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function expectedPgsqlType(string $sqliteType): string
    {
        return match ($sqliteType) {
            'varchar' => 'varchar',
            'integer' => 'int4',
            'tinyint' => 'bool',
            'datetime' => 'timestamp',
            'text' => 'text',
            default => $sqliteType,
        };
    }

    private function normalizeDefault(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value, "'\"");

        if ($this->schemaTestConnection === 'pgsql') {
            $normalized = preg_replace('/::[a-zA-Z0-9_ ]+$/', '', $normalized) ?? $normalized;
            $normalized = trim($normalized, "'\"");

            return match (strtolower($normalized)) {
                'true' => '1',
                'false' => '0',
                default => $normalized,
            };
        }

        return $normalized;
    }

    private function createUser(): int
    {
        return (int) DB::table('users')->insertGetId([
            'name' => 'Schema Test User',
            'email' => uniqid('schema-test-', true).'@example.test',
            'password' => 'not-used',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRecord(array $overrides = []): int
    {
        $attributes = array_merge([
            'record_id' => 'record-'.uniqid(),
            'recognition_method' => 'individual',
            'verification_status' => 'pending_confirmation',
            'created_by_user_id' => $this->createUser(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        return (int) DB::table('annotation_records')->insertGetId($attributes);
    }

    private function createValidationRun(int $recordId, array $overrides = []): int
    {
        $inputHash = str_repeat('a', 64);
        $attributes = array_merge([
            'run_id' => 'run-'.uniqid(),
            'annotation_record_id' => $recordId,
            'input_artifact_path' => 'submitted/test/staging/input.json',
            'input_artifact_sha256' => $inputHash,
            'durable_input_evidence_path' => 'submitted/test/staging/input.json',
            'durable_input_evidence_sha256' => $inputHash,
            'status' => 'completed',
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        return (int) DB::table('annotation_validation_runs')->insertGetId($attributes);
    }

    private function assertQueryException(callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            return;
        }

        $this->fail('Expected the database to reject the invalid or restricted operation.');
    }
}
