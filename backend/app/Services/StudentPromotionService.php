<?php

namespace App\Services;

use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves a class of students up one grade at the end of the year.
 *
 * Students in the final grade graduate instead: they are archived with the same
 * semantics as a manual archive, so the existing student archive page can list
 * and restore them unchanged.
 */
class StudentPromotionService
{
    public const FINAL_GRADE = 12;

    /**
     * Students currently sitting in a class, with what would happen to each.
     */
    public function preview(CourseSection $section, string $targetAcademicYear, ?CourseSection $targetSection = null): array
    {
        $currentGrade = $this->gradeNumberFor($section);
        $graduating = $currentGrade !== null && $currentGrade >= self::FINAL_GRADE;
        $targetSection = $graduating ? null : ($targetSection ?? $this->suggestTargetSection($section, $targetAcademicYear));

        $students = $this->studentsIn($section);

        return [
            'source_section' => [
                'id' => $section->id,
                'class_name' => $section->class_name ?: $section->section_code,
                'section_code' => $section->section_code,
                'academic_year' => $section->academic_year,
                'grade_number' => $currentGrade,
            ],
            'action' => $graduating ? 'graduate' : 'promote',
            'target_academic_year' => $targetAcademicYear,
            'target_section' => $targetSection ? [
                'id' => $targetSection->id,
                'class_name' => $targetSection->class_name ?: $targetSection->section_code,
                'section_code' => $targetSection->section_code,
                'academic_year' => $targetSection->academic_year,
            ] : null,
            // Nothing to move into: the admin has to create next year's class first.
            'blocked_reason' => (! $graduating && ! $targetSection)
                ? 'لا يوجد فصل للصف التالي في السنة الدراسية المختارة. أنشئ الفصل أولاً من صفحة الفصول.'
                : null,
            'students' => $students->map(fn (StudentProfile $student) => [
                'id' => $student->id,
                'name' => $student->full_name ?: $student->user?->name,
                'admission_no' => $student->admission_no ?: $student->student_number,
                'grade_level' => $student->grade_level,
                'current_class' => $section->class_name ?: $section->section_code,
                'target_class' => $graduating ? null : ($targetSection?->class_name ?: $targetSection?->section_code),
            ])->values(),
        ];
    }

    /**
     * @param  array<int, int>  $studentProfileIds  subset to act on; empty means the whole class
     * @return array{action: string, moved: int, students: array<int, string>}
     */
    public function apply(
        CourseSection $section,
        string $targetAcademicYear,
        User $actor,
        array $studentProfileIds = [],
        ?CourseSection $targetSection = null,
    ): array {
        $currentGrade = $this->gradeNumberFor($section);
        $graduating = $currentGrade !== null && $currentGrade >= self::FINAL_GRADE;

        $students = $this->studentsIn($section)
            ->when(
                $studentProfileIds !== [],
                fn (Collection $rows) => $rows->whereIn('id', $studentProfileIds),
            );

        abort_if($students->isEmpty(), 422, 'لا يوجد طلبة نشطون في هذا الفصل.');

        if ($graduating) {
            DB::transaction(fn () => $students->each(fn (StudentProfile $student) => $this->graduate($student, $section, $actor)));

            return [
                'action' => 'graduate',
                'moved' => $students->count(),
                'students' => $students->map(fn ($s) => $s->full_name ?: $s->user?->name)->values()->all(),
            ];
        }

        $targetSection ??= $this->suggestTargetSection($section, $targetAcademicYear);

        abort_unless(
            $targetSection,
            422,
            'لا يوجد فصل للصف التالي في السنة الدراسية المختارة. أنشئ الفصل أولاً من صفحة الفصول.',
        );

        abort_if(
            $targetSection->id === $section->id,
            422,
            'لا يمكن نقل الطلبة إلى نفس الفصل الحالي.',
        );

        DB::transaction(fn () => $students->each(
            fn (StudentProfile $student) => $this->promote($student, $targetSection),
        ));

        return [
            'action' => 'promote',
            'moved' => $students->count(),
            'students' => $students->map(fn ($s) => $s->full_name ?: $s->user?->name)->values()->all(),
        ];
    }

    private function promote(StudentProfile $student, CourseSection $target): void
    {
        $className = $target->class_name ?: $target->section_code;

        $student->update([
            'grade_level' => $className,
            'current_grade_level' => $this->gradeNumberFor($target),
            'course' => $className,
            'academic_year' => $target->academic_year,
            'section_id' => $target->id,
            'batch' => trim($className.' '.$target->academic_year),
        ]);

        Enrollment::query()
            ->where('student_profile_id', $student->id)
            ->where('status', 'active')
            ->where('course_section_id', '!=', $target->id)
            ->update(['status' => 'completed']);

        Enrollment::updateOrCreate(
            ['student_profile_id' => $student->id, 'course_section_id' => $target->id],
            ['status' => 'active', 'enrolled_at' => now()->toDateString()],
        );
    }

    /**
     * Mirrors StudentProfileController::archive so graduates behave like any
     * other archived student and can be restored from the archive page.
     */
    private function graduate(StudentProfile $student, CourseSection $section, User $actor): void
    {
        $student->update([
            'status' => 'archived',
            'archived_at' => now(),
            'archived_by' => $actor->id,
            'archive_reason' => 'تخرج - دفعة '.$section->academic_year,
            'previous_section_id' => $student->section_id ?: $section->id,
        ]);

        $student->user?->update(['is_active' => false]);
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    private function studentsIn(CourseSection $section): Collection
    {
        return Enrollment::query()
            ->with('studentProfile.user')
            ->where('course_section_id', $section->id)
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->get()
            ->map(fn (Enrollment $enrollment) => $enrollment->studentProfile)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * The same grade number one year up, in the chosen academic year.
     */
    private function suggestTargetSection(CourseSection $section, string $targetAcademicYear): ?CourseSection
    {
        $currentGrade = $this->gradeNumberFor($section);

        if ($currentGrade === null) {
            return null;
        }

        $nextGrade = $currentGrade + 1;

        return CourseSection::query()
            ->where('academic_year', $targetAcademicYear)
            ->get()
            ->first(fn (CourseSection $candidate) => $this->gradeNumberFor($candidate) === $nextGrade);
    }

    public function gradeNumberFor(CourseSection $section): ?int
    {
        $label = $section->class_name ?: $section->section_code;

        return preg_match('/(\d{1,2})/', (string) $label, $matches) ? (int) $matches[1] : null;
    }
}
