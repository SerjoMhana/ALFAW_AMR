<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = [
        'code',
        'class_section_id',
        'teacher_id',
        'name',
        'grade_level',
        'is_ap',
        'has_exam',
        'credit_hours',
        'periods_per_week',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_ap' => 'boolean',
            'has_exam' => 'boolean',
            'credit_hours' => 'decimal:2',
        ];
    }

    /**
     * Subjects that are actually examined — the ones a report card is made of.
     *
     * An unexamined subject still exists everywhere else: it keeps its class,
     * its teacher and its classroom stream. It simply has no mark to print.
     */
    public function scopeExamined(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('has_exam', true);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class);
    }

    public function classSection(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'class_section_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function gradeSubmissions(): HasMany
    {
        return $this->hasMany(GradeSubmission::class);
    }

    /**
     * The Google Classroom course this subject is mirrored into, if any.
     */
    public function googleClassroomLink(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(GoogleClassroomLink::class);
    }

    /**
     * The scheme this subject is marked under: whatever the admin assigned to
     * its class, provided that scheme is switched on. Falling back to the grade
     * range keeps classes working that were never assigned one explicitly.
     */
    public function gradeTier(): ?GradeTier
    {
        $assigned = $this->classSection?->gradeTier;

        if ($assigned && $assigned->is_active) {
            return $assigned;
        }

        // An assigned but switched-off scheme means "not in use yet" — do not
        // quietly fall through to another scheme and mark against the wrong one.
        if ($assigned) {
            return null;
        }

        if (! preg_match('/G(\d+)/i', (string) $this->grade_level, $matches)) {
            return null;
        }

        return GradeTier::forNumericGrade((int) $matches[1]);
    }
}
