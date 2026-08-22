<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\TermWindow;
use Illuminate\Support\Collection;

/**
 * The read model shared by the student dashboard and the parent portal.
 *
 * Visibility is gated purely by the admin's term window — grades appear as soon
 * as the teacher saves them, without waiting for the sheet to be submitted.
 */
class StudentGradesService
{
    public function __construct(private readonly GradeCalculationService $grades) {}

    /**
     * Terms this student may currently see, newest configuration first.
     *
     * @return Collection<int, string>
     */
    public function openTermsFor(StudentProfile $studentProfile): Collection
    {
        return collect($this->academicYearsFor($studentProfile))
            ->flatMap(fn (string $year) => TermWindow::openTerms($year))
            ->unique()
            ->values();
    }

    /**
     * Per-subject grades for one term, or an empty course list when the term is
     * closed so callers never leak a term the admin has not released.
     */
    public function forStudent(StudentProfile $studentProfile, string $term): array
    {
        $enrollments = $this->activeEnrollments($studentProfile);

        $courses = $enrollments
            ->filter(fn (Enrollment $enrollment) => TermWindow::isOpen($enrollment->courseSection->academic_year, $term))
            ->flatMap(fn (Enrollment $enrollment) => $enrollment->courseSection->courses
                ->where('has_exam', true)
                ->map(fn (Course $course) => [
                'section' => $enrollment->courseSection,
                'course' => $course,
                'teacher' => $course->teacher,
                'report' => $this->grades->calculateStudentTermGrade(
                    $studentProfile,
                    $course,
                    $term,
                    $enrollment->courseSection->academic_year,
                ),
            ]))
            ->values();

        return [
            'term' => $term,
            'is_open' => $courses->isNotEmpty() || $this->openTermsFor($studentProfile)->contains($term),
            'courses' => $courses->all(),
        ];
    }

    /**
     * @return Collection<int, Enrollment>
     */
    public function activeEnrollments(StudentProfile $studentProfile): Collection
    {
        return $studentProfile->enrollments()
            ->with('courseSection.courses.teacher')
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function academicYearsFor(StudentProfile $studentProfile): array
    {
        return $this->activeEnrollments($studentProfile)
            ->map(fn (Enrollment $enrollment) => $enrollment->courseSection->academic_year)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
