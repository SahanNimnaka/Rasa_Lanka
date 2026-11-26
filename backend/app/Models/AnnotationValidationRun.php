<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AnnotationValidationRun extends Model
{
    protected $table = 'annotation_validation_runs';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'run_id',
        'annotation_record_id',
        'submission_intent_id',
        'batch_run_id',
        'b1_version',
        'input_artifact_path',
        'input_artifact_sha256',
        'input_evidence_state',
        'durable_input_evidence_path',
        'durable_input_evidence_sha256',
        'report_json_path',
        'report_json_sha256',
        'report_csv_path',
        'exit_code',
        'is_valid',
        'is_export_eligible_canonical',
        'error_count',
        'warning_count',
        'export_block_count',
        'status',
        'infra_error_code',
        'infra_error_message',
        'timed_out',
        'duration_ms',
        'executed_by_user_id',
        'executed_at',
    ];

    /**
     * @var list<string>
     */
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'annotation_record_id' => 'integer',
            'exit_code' => 'integer',
            'is_valid' => 'boolean',
            'is_export_eligible_canonical' => 'boolean',
            'error_count' => 'integer',
            'warning_count' => 'integer',
            'export_block_count' => 'integer',
            'timed_out' => 'boolean',
            'duration_ms' => 'integer',
            'executed_by_user_id' => 'integer',
            'executed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (AnnotationValidationRun $run): bool {
            throw new LogicException('Annotation validation runs cannot be deleted.');
        });
    }

    public function annotationRecord(): BelongsTo
    {
        return $this->belongsTo(AnnotationRecord::class, 'annotation_record_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(AnnotationValidationIssue::class, 'validation_run_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeInfraFailed(Builder $query): Builder
    {
        return $query->where('status', 'infra_failed');
    }
}
