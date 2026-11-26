<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use LogicException;

class AnnotationRecord extends Model
{
    protected $table = 'annotation_records';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * Explicitly mass assignable fields for trusted persistence services.
     * Immutable identifiers, finalized artifact metadata, and workflow/submission
     * audit-sensitive fields are intentionally excluded.
     *
     * @var list<string>
     */
    protected $fillable = [
        'schema_version',
        'recognition_method',
        'verification_status',
        'workflow_status',
        'artifact_state',
        'current_draft_version_id',
        'current_draft_path',
        'current_draft_sha256',
        'submission_intent_id',
        'submission_state',
        'submission_staging_path',
        'submission_staging_sha256',
        'submission_started_at',
        'created_by_user_id',
        'is_export_eligible_canonical',
        'latest_validation_run_id',
        'latest_b1_valid',
        'lock_version',
        'discarded_at',
        'discard_reason',
    ];

    /**
     * @var list<string>
     */
    protected $guarded = [
        'id',
        'record_id',
        'submitted_json_path',
        'submitted_artifact_sha256',
        'submission_finalized_at',
        'supersedes_record_id',
        'superseded_by_record_id',
        'submitted_by_user_id',
        'reviewed_by_user_id',
        'submitted_at',
        'reviewed_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'current_draft_version_id' => 'string',
            'current_draft_path' => 'string',
            'current_draft_sha256' => 'string',
            'submitted_json_path' => 'string',
            'submitted_artifact_sha256' => 'string',
            'submission_intent_id' => 'string',
            'submission_staging_path' => 'string',
            'submission_staging_sha256' => 'string',
            'submission_started_at' => 'datetime',
            'submission_finalized_at' => 'datetime',
            'created_by_user_id' => 'integer',
            'submitted_by_user_id' => 'integer',
            'reviewed_by_user_id' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'is_export_eligible_canonical' => 'boolean',
            'latest_validation_run_id' => 'integer',
            'latest_b1_valid' => 'boolean',
            'lock_version' => 'integer',
            'discarded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (AnnotationRecord $record): bool {
            throw new LogicException('Annotation records cannot be deleted.');
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function validationRuns(): HasMany
    {
        return $this->hasMany(AnnotationValidationRun::class, 'annotation_record_id');
    }

    public function latestValidationRun(): BelongsTo
    {
        return $this->belongsTo(AnnotationValidationRun::class, 'latest_validation_run_id');
    }

    public function validationIssues(): HasManyThrough
    {
        return $this->hasManyThrough(
            AnnotationValidationIssue::class,
            AnnotationValidationRun::class,
            'annotation_record_id',
            'validation_run_id',
            'id',
            'id'
        );
    }

    public function recordImages(): HasMany
    {
        return $this->hasMany(AnnotationRecordImage::class, 'annotation_record_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(AnnotationLabel::class, 'annotation_record_id');
    }

    public function reviewActions(): HasMany
    {
        return $this->hasMany(AnnotationReviewAction::class, 'annotation_record_id');
    }

    public function supersedesRecord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_record_id', 'record_id');
    }

    public function supersededByRecord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_record_id', 'record_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('workflow_status', 'draft');
    }

    public function scopeForCreator(Builder $query, int $userId): Builder
    {
        return $query->where('created_by_user_id', $userId);
    }
}
