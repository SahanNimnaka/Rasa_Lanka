<?php

namespace Tests\Feature;

use App\Models\AnnotationLabel;
use App\Models\AnnotationRecord;
use App\Models\AnnotationRecordImage;
use App\Models\AnnotationReviewAction;
use App\Models\AnnotationValidationIssue;
use App\Models\AnnotationValidationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use LogicException;
use Tests\TestCase;

class AnnotationModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);

        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])
            ->assertExitCode(0);
    }

    public function test_annotation_models_have_expected_table_and_timestamp_configuration(): void
    {
        $this->assertSame('annotation_records', (new AnnotationRecord)->getTable());
        $this->assertSame('annotation_validation_runs', (new AnnotationValidationRun)->getTable());
        $this->assertSame('annotation_validation_issues', (new AnnotationValidationIssue)->getTable());
        $this->assertSame('annotation_record_images', (new AnnotationRecordImage)->getTable());
        $this->assertSame('annotation_labels', (new AnnotationLabel)->getTable());
        $this->assertSame('annotation_review_actions', (new AnnotationReviewAction)->getTable());

        $this->assertTrue((new AnnotationRecord)->usesTimestamps());
        $this->assertTrue((new AnnotationValidationRun)->usesTimestamps());
        $this->assertTrue((new AnnotationValidationIssue)->usesTimestamps());
        $this->assertTrue((new AnnotationRecordImage)->usesTimestamps());
        $this->assertTrue((new AnnotationLabel)->usesTimestamps());
        $this->assertTrue((new AnnotationReviewAction)->timestamps);
        $this->assertNull((new AnnotationReviewAction)->getUpdatedAtColumn());
        $this->assertSame('created_at', (new AnnotationReviewAction)->getCreatedAtColumn());
    }

    public function test_annotation_record_relationships_and_self_references_use_record_id(): void
    {
        [$record, $run, $issue, $image, $label, $action] = $this->createPersistedRecordGraph();

        $this->assertInstanceOf(BelongsTo::class, $record->creator());
        $this->assertInstanceOf(BelongsTo::class, $record->submitter());
        $this->assertInstanceOf(BelongsTo::class, $record->reviewer());
        $this->assertInstanceOf(HasMany::class, $record->validationRuns());
        $this->assertInstanceOf(BelongsTo::class, $record->latestValidationRun());
        $this->assertInstanceOf(HasManyThrough::class, $record->validationIssues());
        $this->assertInstanceOf(HasMany::class, $record->recordImages());
        $this->assertInstanceOf(HasMany::class, $record->labels());
        $this->assertInstanceOf(HasMany::class, $record->reviewActions());
        $this->assertInstanceOf(BelongsTo::class, $record->supersedesRecord());
        $this->assertInstanceOf(BelongsTo::class, $record->supersededByRecord());

        $this->assertSame('created_by_user_id', $record->creator()->getForeignKeyName());
        $this->assertSame('submitted_by_user_id', $record->submitter()->getForeignKeyName());
        $this->assertSame('reviewed_by_user_id', $record->reviewer()->getForeignKeyName());
        $this->assertSame('latest_validation_run_id', $record->latestValidationRun()->getForeignKeyName());

        $this->assertSame('supersedes_record_id', $record->supersedesRecord()->getForeignKeyName());
        $this->assertSame('record_id', $record->supersedesRecord()->getOwnerKeyName());
        $this->assertSame('superseded_by_record_id', $record->supersededByRecord()->getForeignKeyName());
        $this->assertSame('record_id', $record->supersededByRecord()->getOwnerKeyName());

        $record->load([
            'creator',
            'submitter',
            'reviewer',
            'validationRuns',
            'latestValidationRun',
            'validationIssues',
            'recordImages',
            'labels',
            'reviewActions',
            'supersedesRecord',
            'supersededByRecord',
        ]);

        $this->assertNotNull($record->creator);
        $this->assertNull($record->submitter);
        $this->assertNull($record->reviewer);
        $this->assertCount(1, $record->validationRuns);
        $this->assertTrue($record->latestValidationRun->is($run));
        $this->assertCount(1, $record->validationIssues);
        $this->assertTrue($record->validationIssues->first()->is($issue));
        $this->assertCount(1, $record->recordImages);
        $this->assertTrue($record->recordImages->first()->is($image));
        $this->assertCount(1, $record->labels);
        $this->assertTrue($record->labels->first()->is($label));
        $this->assertCount(1, $record->reviewActions);
        $this->assertTrue($record->reviewActions->first()->is($action));

        $this->assertTrue($run->annotationRecord->is($record));
        $this->assertCount(1, $run->issues);
        $this->assertTrue($issue->validationRun->is($run));
        $this->assertTrue($image->annotationRecord->is($record));
        $this->assertTrue($label->annotationRecord->is($record));
        $this->assertTrue($action->annotationRecord->is($record));
        $this->assertNull($action->actor);
    }

    public function test_self_referencing_record_relationships_resolve_by_record_id(): void
    {
        $source = $this->createRecord(['record_id' => 'source-record']);
        $replacement = $this->createRecord(['record_id' => 'replacement-record']);
        $correction = $this->createRecord([
            'record_id' => 'correction-record',
            'supersedes_record_id' => $source->record_id,
            'superseded_by_record_id' => $replacement->record_id,
        ]);

        $correction->load(['supersedesRecord', 'supersededByRecord']);

        $this->assertTrue($correction->supersedesRecord->is($source));
        $this->assertTrue($correction->supersededByRecord->is($replacement));
    }

    public function test_casts_preserve_nullable_values_and_types(): void
    {
        $record = $this->createRecord([
            'is_export_eligible_canonical' => 1,
            'latest_b1_valid' => null,
            'lock_version' => '3',
            'submitted_by_user_id' => null,
        ]);
        $record->refresh();

        $this->assertIsBool($record->is_export_eligible_canonical);
        $this->assertTrue($record->is_export_eligible_canonical);
        $this->assertNull($record->latest_b1_valid);
        $this->assertIsInt($record->lock_version);
        $this->assertSame(3, $record->lock_version);
        $this->assertNull($record->submitted_by_user_id);

        $run = $this->createValidationRun($record, [
            'exit_code' => '0',
            'is_valid' => 1,
            'is_export_eligible_canonical' => null,
            'timed_out' => 0,
            'error_count' => '2',
            'duration_ms' => '1500',
        ]);
        $run->refresh();

        $this->assertIsInt($run->exit_code);
        $this->assertTrue($run->is_valid);
        $this->assertNull($run->is_export_eligible_canonical);
        $this->assertFalse($run->timed_out);
        $this->assertSame(2, $run->error_count);
        $this->assertSame(1500, $run->duration_ms);

        $issue = AnnotationValidationIssue::query()->create([
            'validation_run_id' => $run->id,
            'record_id' => $record->record_id,
            'severity' => 'warning',
            'code' => 'TEST_WARNING',
            'field' => 'labels',
            'message' => 'Test warning',
            'details_json' => ['reason' => 'example', 'count' => 1],
        ])->fresh();

        $this->assertIsArray($issue->details_json);
        $this->assertSame('example', $issue->details_json['reason']);

        $action = AnnotationReviewAction::query()->create([
            'annotation_record_id' => $record->id,
            'actor_user_id' => null,
            'actor_type' => 'system',
            'action' => 'submission_infra_failed',
            'metadata_json' => ['code' => 'B1_TIMEOUT'],
            'created_at' => now(),
        ])->fresh();

        $this->assertIsArray($action->metadata_json);
        $this->assertSame('B1_TIMEOUT', $action->metadata_json['code']);
        $this->assertNotNull($action->created_at);
    }

    public function test_mass_assignment_protects_sensitive_and_immutable_fields(): void
    {
        $record = new AnnotationRecord;
        $record->fill([
            'record_id' => 'injected-record-id',
            'submitted_json_path' => 'submitted/injected.json',
            'submitted_artifact_sha256' => str_repeat('b', 64),
            'submission_finalized_at' => now(),
            'supersedes_record_id' => 'source-record',
            'superseded_by_record_id' => 'replacement-record',
            'submitted_by_user_id' => 999,
            'reviewed_by_user_id' => 999,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'workflow_status' => 'approved',
            'artifact_state' => 'submitted_finalized',
            'lock_version' => 9,
        ]);

        $this->assertNull($record->record_id);
        $this->assertNull($record->submitted_json_path);
        $this->assertNull($record->submitted_artifact_sha256);
        $this->assertNull($record->submission_finalized_at);
        $this->assertNull($record->supersedes_record_id);
        $this->assertNull($record->superseded_by_record_id);
        $this->assertNull($record->submitted_by_user_id);
        $this->assertNull($record->reviewed_by_user_id);
        $this->assertNull($record->submitted_at);
        $this->assertNull($record->reviewed_at);

        // Explicitly allowed service-managed fields remain fillable.
        $this->assertSame('approved', $record->workflow_status);
        $this->assertSame('submitted_finalized', $record->artifact_state);
        $this->assertSame(9, $record->lock_version);
    }

    public function test_normal_eloquent_deletes_are_rejected_for_all_s2_models(): void
    {
        [$record, $run, $issue, $image, $label, $action] = $this->createPersistedRecordGraph();

        foreach ([
            [$record, 'Annotation records cannot be deleted.'],
            [$run, 'Annotation validation runs cannot be deleted.'],
            [$issue, 'Annotation validation issues cannot be deleted.'],
            [$image, 'Annotation record images cannot be deleted.'],
            [$label, 'Annotation labels cannot be deleted.'],
            [$action, 'Annotation review actions cannot be deleted.'],
        ] as [$model, $message]) {
            try {
                $model->delete();
                $this->fail('Expected delete to be rejected for '.get_class($model));
            } catch (LogicException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertDatabaseHas('annotation_records', ['id' => $record->id]);
        $this->assertDatabaseHas('annotation_validation_runs', ['id' => $run->id]);
        $this->assertDatabaseHas('annotation_validation_issues', ['id' => $issue->id]);
        $this->assertDatabaseHas('annotation_record_images', ['id' => $image->id]);
        $this->assertDatabaseHas('annotation_labels', ['id' => $label->id]);
        $this->assertDatabaseHas('annotation_review_actions', ['id' => $action->id]);
    }

    public function test_annotation_review_actions_are_append_only_but_creatable(): void
    {
        $record = $this->createRecord();
        $action = AnnotationReviewAction::query()->create([
            'annotation_record_id' => $record->id,
            'actor_user_id' => $record->created_by_user_id,
            'actor_type' => 'user',
            'action' => 'created',
            'from_workflow_status' => null,
            'to_workflow_status' => 'draft',
            'comment' => 'created',
            'metadata_json' => ['source' => 'test'],
            'created_at' => now(),
        ]);

        $this->assertTrue($action->exists);
        $this->assertDatabaseHas('annotation_review_actions', ['id' => $action->id, 'action' => 'created']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Annotation review actions are append-only.');
        $action->update(['action' => 'changed']);
    }

    /**
     * @return array{0:AnnotationRecord,1:AnnotationValidationRun,2:AnnotationValidationIssue,3:AnnotationRecordImage,4:AnnotationLabel,5:AnnotationReviewAction}
     */
    private function createPersistedRecordGraph(): array
    {
        $record = $this->createRecord();
        $run = $this->createValidationRun($record);
        $record->forceFill(['latest_validation_run_id' => $run->id])->save();

        $issue = AnnotationValidationIssue::query()->create([
            'validation_run_id' => $run->id,
            'record_id' => $record->record_id,
            'severity' => 'error',
            'code' => 'TEST_ERROR',
            'field' => 'record_id',
            'message' => 'Test error',
            'details_json' => ['k' => 'v'],
        ]);
        $image = AnnotationRecordImage::query()->create([
            'annotation_record_id' => $record->id,
            'image_id' => 'IMG-000001',
            'relative_path' => 'images/test.jpg',
            'original_folder' => 'test',
            'original_filename' => 'test.jpg',
            'membership_role' => 'individual',
        ]);
        $label = AnnotationLabel::query()->create([
            'annotation_record_id' => $record->id,
            'label_array' => 'ingredient_labels',
            'raw_label' => 'ginger',
            'resolved_label' => 'ginger',
            'label_class' => 'canonical_in_scope',
            'is_pending_mapping' => false,
            'is_alias_resolved' => false,
        ]);
        $action = AnnotationReviewAction::query()->create([
            'annotation_record_id' => $record->id,
            'actor_user_id' => null,
            'actor_type' => 'system',
            'action' => 'created',
            'metadata_json' => ['seed' => true],
            'created_at' => now(),
        ]);

        return [$record->fresh(), $run->fresh(), $issue->fresh(), $image->fresh(), $label->fresh(), $action->fresh()];
    }

    private function createRecord(array $overrides = []): AnnotationRecord
    {
        $creator = User::factory()->create();

        $record = new AnnotationRecord;
        $record->forceFill(array_merge([
            'record_id' => 'record-'.uniqid('', true),
            'schema_version' => 'human-recognition-v1',
            'recognition_method' => 'individual',
            'verification_status' => 'pending_confirmation',
            'workflow_status' => 'draft',
            'artifact_state' => 'draft',
            'submission_state' => 'none',
            'created_by_user_id' => $creator->id,
            'is_export_eligible_canonical' => false,
            'lock_version' => 1,
        ], $overrides));
        $record->save();

        return $record->fresh();
    }

    private function createValidationRun(AnnotationRecord $record, array $overrides = []): AnnotationValidationRun
    {
        $hash = str_repeat('a', 64);

        $run = new AnnotationValidationRun;
        $run->forceFill(array_merge([
            'run_id' => 'run-'.uniqid('', true),
            'annotation_record_id' => $record->id,
            'input_artifact_path' => 'submitted/test/staging/input.json',
            'input_artifact_sha256' => $hash,
            'input_evidence_state' => 'staged_path',
            'durable_input_evidence_path' => 'submitted/test/staging/input.json',
            'durable_input_evidence_sha256' => $hash,
            'status' => 'completed',
            'error_count' => 0,
            'warning_count' => 0,
            'export_block_count' => 0,
            'timed_out' => false,
            'executed_at' => now(),
        ], $overrides));
        $run->save();

        return $run->fresh();
    }
}
