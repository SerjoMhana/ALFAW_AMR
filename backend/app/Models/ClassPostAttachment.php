<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClassPostAttachment extends Model
{
    protected $fillable = [
        'class_post_id', 'uploaded_by', 'disk', 'path',
        'original_name', 'mime', 'size', 'checksum',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * The stored file follows the row. Deleting the row without this leaves
     * orphaned bytes on disk, and a school's disk fills up quietly.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(ClassPost::class, 'class_post_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf'
            || str_ends_with(strtolower($this->original_name), '.pdf');
    }
}
