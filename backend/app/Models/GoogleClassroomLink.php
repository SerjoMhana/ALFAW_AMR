<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleClassroomLink extends Model
{
    public const STATE_PENDING = 'PENDING';

    protected $fillable = [
        'course_id', 'alias', 'google_course_id', 'simulated', 'name', 'section',
        'state', 'enrollment_code', 'alternate_link', 'last_synced_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime', 'simulated' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GoogleClassroomMember::class);
    }

    public function isLinked(): bool
    {
        return $this->google_course_id !== null && ! $this->isStale();
    }

    /**
     * A row left over from the stand-in after the school connected Google.
     *
     * The invented id points at nothing, so the subject counts as unlinked and
     * the page offers to create it for real.
     */
    public function isStale(): bool
    {
        return $this->simulated && (bool) config('google.classroom.enabled');
    }
}
