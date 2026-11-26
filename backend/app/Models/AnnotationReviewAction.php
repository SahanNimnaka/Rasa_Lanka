<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AnnotationReviewAction extends Model
{
    protected $table = 'annotation_review_actions';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'annotation_record_id',
        'actor_user_id',
        'actor_type',
        'action',
        'from_workflow_status',
        'to_workflow_status',
        'comment',
        'evidence_ref',
        'metadata_json',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'annotation_record_id' => 'integer',
            'actor_user_id' => 'integer',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AnnotationReviewAction $action): bool {
            throw new LogicException('Annotation review actions are append-only.');
        });

        static::deleting(function (AnnotationReviewAction $action): bool {
            throw new LogicException('Annotation review actions cannot be deleted.');
        });
    }

    public function annotationRecord(): BelongsTo
    {
        return $this->belongsTo(AnnotationRecord::class, 'annotation_record_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
