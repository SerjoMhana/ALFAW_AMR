<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleClassroomMember extends Model
{
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_STUDENT = 'student';

    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_INVITED = 'INVITED';
    public const STATE_FAILED = 'FAILED';

    protected $fillable = [
        'google_classroom_link_id', 'user_id', 'role', 'state', 'simulated',
        'google_email', 'last_error', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime', 'simulated' => 'boolean'];
    }

    /**
     * Recorded against the stand-in, so it says nothing about the real roster.
     */
    public function isStale(): bool
    {
        return $this->simulated && (bool) config('google.classroom.enabled');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(GoogleClassroomLink::class, 'google_classroom_link_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
