<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AnnotationValidationIssue extends Model
{
    protected $table = 'annotation_validation_issues';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'validation_run_id',
        'record_id',
        'severity',
        'code',
        'field',
        'message',
        'details_json',
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
            'validation_run_id' => 'integer',
            'details_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (AnnotationValidationIssue $issue): bool {
            throw new LogicException('Annotation validation issues cannot be deleted.');
        });
    }

    public function validationRun(): BelongsTo
    {
        return $this->belongsTo(AnnotationValidationRun::class, 'validation_run_id');
    }
}
