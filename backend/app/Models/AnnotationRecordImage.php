<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AnnotationRecordImage extends Model
{
    protected $table = 'annotation_record_images';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'annotation_record_id',
        'image_id',
        'relative_path',
        'original_folder',
        'original_filename',
        'membership_role',
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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (AnnotationRecordImage $image): bool {
            throw new LogicException('Annotation record images cannot be deleted.');
        });
    }

    public function annotationRecord(): BelongsTo
    {
        return $this->belongsTo(AnnotationRecord::class, 'annotation_record_id');
    }
}
