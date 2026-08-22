<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseSection extends Model
{
    use \App\Models\Concerns\BelongsToAcademicYear;

    protected $fillable = [
        'course_id',
        'teacher_id',
        'section_code',
        'class_name',
        'academic_year',
        'term',
        'capacity',
        'grade_tier_id',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The one grading scheme this class is marked under.
     */
    public function gradeTier(): BelongsTo
    {
        return $this->belongsTo(GradeTier::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'class_section_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function studentScores(): HasMany
    {
        return $this->hasMany(StudentScore::class);
    }
}
