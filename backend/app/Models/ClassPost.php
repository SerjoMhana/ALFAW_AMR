<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassPost extends Model
{
    use SoftDeletes;

    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const TYPE_MATERIAL = 'material';

    protected $fillable = [
        'course_id', 'author_id', 'type', 'title', 'body',
        'published_at', 'pinned_at', 'comments_enabled', 'academic_year',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'pinned_at' => 'datetime',
            'comments_enabled' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClassPostAttachment::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ClassPostComment::class);
    }

    /**
     * What a student is allowed to see: a draft belongs to whoever wrote it
     * until they publish it.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->lessThanOrEqualTo(now());
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }
}
