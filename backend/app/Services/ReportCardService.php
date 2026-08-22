<?php

namespace App\Services;

use App\Models\StudentProfile;

class ReportCardService
{
    public function __construct(
        private readonly GradeCalculationService $grades,
        private readonly GPAService $gpa,
    ) {}

    public function build(StudentProfile $studentProfile, string $type, ?string $term = null): array
    {
        abort_if($studentProfile->is_archived, 403, 'Archived students are excluded from report cards.');

        $studentProfile->load('user');
        $enrollments = $studentProfile->enrollments()
            ->with('courseSection.courses.teacher')
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->get();

        return [
            'student_profile' => $studentProfile,
            'type' => $type,
            'title' => $this->titleFor($type, $term),
            'terms' => collect($this->termsFor($type, $term))->map(fn (string $termName) => [
                'term' => $termName,
                // Unexamined subjects have no mark, so they are not printed.
                'courses' => $enrollments->flatMap(fn ($enrollment) => $enrollment->courseSection->courses
                    ->where('has_exam', true)
                    ->map(fn ($course) => [
                    'section' => $enrollment->courseSection,
                    'course' => $course,
                    'teacher' => $course->teacher,
                    'report' => $this->grades->calculateStudentTermGrade($studentProfile, $course, $termName, $enrollment->courseSection->academic_year),
                ]))->values(),
                'gpa' => $this->gpa->termGpa($studentProfile, $termName),
            ])->values(),
        ];
    }

    private function termsFor(string $type, ?string $term): array
    {
        return match ($type) {
            'quarter' => [$term ?: 'Quarter 1'],
            'semester' => in_array($term, ['Semester 2', 'Quarter 3', 'Quarter 4'], true)
                ? ['Quarter 3', 'Quarter 4']
                : ['Quarter 1', 'Quarter 2'],
            'final' => ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'],
            default => [$term ?: 'Quarter 1'],
        };
    }

    private function titleFor(string $type, ?string $term): string
    {
        return match ($type) {
            'quarter' => ($term ?: 'Quarter 1').' Report Card',
            'semester' => in_array($term, ['Semester 2', 'Quarter 3', 'Quarter 4'], true)
                ? 'Semester 2 Report Card'
                : 'Semester 1 Report Card',
            'final' => 'Final Report Card',
            default => 'Report Card',
        };
    }
}
