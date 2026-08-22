<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    protected $appends = [
        'parent_login_username',
        'parent_initial_password',
    ];

    protected $fillable = [
        'user_id',
        'student_code',
        'admission_no',
        'student_number',
        'admission_date',
        'grade_level',
        'current_grade_level',
        'grade_tier',
        'student_category',
        'batch',
        'course',
        'section_id',
        'academic_year',
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'arabic_name',
        'date_of_birth',
        'gender',
        'blood_group',
        'mother_tongue',
        'religion',
        'country',
        'nationality',
        'nationality_ar',
        'national_id',
        'birth_place',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'pin_code',
        'phone',
        'mobile',
        'roll_number',
        'biometric_id',
        'guardian_name',
        'guardian_phone',
        'parent_first_name',
        'parent_last_name',
        'parent_full_name',
        'parent_relation',
        'parent_nationality',
        'parent_username',
        'parent_date_of_birth',
        'parent_education',
        'parent_occupation',
        'parent_income',
        'parent_email',
        'parent_office_address_1',
        'parent_office_address_2',
        'parent_city',
        'parent_state',
        'parent_office_phone',
        'parent_mobile_phone',
        'second_parent_full_name',
        'second_parent_relation',
        'second_parent_nationality',
        'second_parent_phone',
        'second_parent_email',
        'status',
        'archived_at',
        'archived_by',
        'archive_reason',
        'previous_section_id',
        'restored_at',
        'restored_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admission_date' => 'date',
            'parent_date_of_birth' => 'date',
            'archived_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->status === 'archived' || $this->archived_at !== null;
    }

    public function getParentLoginUsernameAttribute(): ?string
    {
        return $this->parent_username;
    }

    public function getParentInitialPasswordAttribute(): ?string
    {
        return $this->parent_username ? $this->parent_username.'123' : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', 'archived')->orWhereNotNull('archived_at');
    }

    /**
     * Everyone who belonged to the school in a given year.
     *
     * A promotion leaves the old enrollment behind as `completed`, so the
     * enrollments are what say which year a student was here for. The profile's
     * own year is the fallback for a student who was never put in a class.
     */
    public function scopeInAcademicYear(Builder $query, string $academicYear): Builder
    {
        return $query->where(function (Builder $inner) use ($academicYear): void {
            $inner->whereHas(
                'enrollments',
                fn (Builder $enrollment) => $enrollment->members()->whereHas(
                    'courseSection',
                    fn (Builder $section) => $section->where('academic_year', $academicYear),
                ),
            )->orWhere(function (Builder $orphan) use ($academicYear): void {
                $orphan->where('academic_year', $academicYear)->doesntHave('enrollments');
            });
        });
    }

    /**
     * The class this student sat in during a given year, which is where their
     * grade level for that year comes from — a student now in G2 was in G1.
     */
    public function sectionForYear(string $academicYear): ?CourseSection
    {
        return $this->enrollments
            ->filter(fn (Enrollment $enrollment) => in_array($enrollment->status, ['active', 'completed'], true))
            ->map(fn (Enrollment $enrollment) => $enrollment->courseSection)
            ->filter(fn (?CourseSection $section) => $section?->academic_year === $academicYear)
            // The latest enrollment wins if a student moved class mid-year.
            ->sortByDesc(fn (CourseSection $section) => $section->id)
            ->first();
    }

    /**
     * The grade label to show for a year: that year's class, falling back to
     * the profile for a student who has no enrollment to read.
     */
    public function gradeLevelForYear(string $academicYear): ?string
    {
        $section = $this->sectionForYear($academicYear);

        return $section
            ? ($section->class_name ?: $section->section_code)
            : $this->grade_level;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(StudentScore::class);
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(ParentGuardian::class, 'parent_student', 'student_id', 'parent_id')
            ->withPivot(['relation', 'is_primary'])
            ->withTimestamps();
    }

    public function gradeTier(): ?GradeTier
    {
        if (! preg_match('/G(\d+)/i', (string) $this->grade_level, $matches)) {
            return null;
        }

        return GradeTier::forNumericGrade((int) $matches[1]);
    }
}
