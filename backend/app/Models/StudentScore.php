<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentScore extends Model
{
    protected $fillable = [
        'student_profile_id',
        'course_section_id',
        'course_id',
        'teacher_id',
        'grading_item_id',
        'term',
        'academic_year',
        'score_obtained',
        'max_score',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'score_obtained' => 'decimal:2',
            'max_score' => 'decimal:2',
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

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function gradingItem(): BelongsTo
    {
        return $this->belongsTo(GradingItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(GradeAuditLog::class);
    }
}
