<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeSubmission extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'course_id',
        'term',
        'academic_year',
        'status',
        'submitted_at',
        'submitted_by',
        'unlocked_at',
        'unlocked_by',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function unlockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }
}
