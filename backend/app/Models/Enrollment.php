<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    /**
     * Everyone who belongs — or belonged — to a class.
     *
     * A promotion marks the old enrollment `completed` rather than deleting it,
     * so this is what a past year's roster must be read through; only students
     * who actually left the school are `dropped` and excluded.
     */
    public function scopeMembers(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'completed']);
    }

    protected $fillable = [
        'student_profile_id',
        'course_section_id',
        'status',
        'enrolled_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'date',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function courseSection(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class);
    }
}
