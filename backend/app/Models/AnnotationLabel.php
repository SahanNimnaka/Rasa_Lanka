<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AnnotationLabel extends Model
{
    protected $table = 'annotation_labels';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'annotation_record_id',
        'label_array',
        'raw_label',
        'resolved_label',
        'label_class',
        'is_pending_mapping',
        'is_alias_resolved',
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
            'is_pending_mapping' => 'boolean',
            'is_alias_resolved' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (AnnotationLabel $label): bool {
            throw new LogicException('Annotation labels cannot be deleted.');
        });
    }

    public function annotationRecord(): BelongsTo
    {
        return $this->belongsTo(AnnotationRecord::class, 'annotation_record_id');
    }
}
