<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\StudentProfile;
use App\Models\TermWindow;
use App\Services\GradeCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeReportController extends Controller
{
    public function __construct(private readonly GradeCalculationService $grades) {}

    public function studentReport(Request $request, Course $course): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        abort_unless($studentProfile && ! $studentProfile->is_archived, 404, 'Student profile not found.');
        $this->ensureStudentEnrolled($studentProfile, $course);

        $term = $request->query('term', 'Quarter 1');
        $academicYear = $request->query('academic_year', $course->classSection?->academic_year);

        abort_unless(
            TermWindow::isOpen($academicYear, $term),
            403,
            'هذا الفصل الدراسي غير متاح للاطلاع بعد.',
        );

        return response()->json([
            'data' => $this->grades->calculateStudentTermGrade(
                $studentProfile,
                $course,
                $term,
                $academicYear,
            ),
        ]);
    }

    public function studentCourses(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        abort_unless($studentProfile && ! $studentProfile->is_archived, 404, 'Student profile not found.');

        $classSectionIds = $studentProfile->enrollments()
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->pluck('course_section_id');

        return response()->json([
            'data' => Course::query()
                ->with(['classSection', 'teacher'])
                ->whereIn('class_section_id', $classSectionIds)
                ->get(),
        ]);
    }

    public function teacherCourseSummary(Request $request, Course $course): JsonResponse
    {
        abort_unless($request->user()->can('viewGradeSummary', $course), 403);

        return response()->json([
            'data' => $this->grades->calculateCourseSummary(
                $course,
                $request->query('term', 'Quarter 1'),
                $request->query('academic_year', $course->classSection?->academic_year),
            ),
        ]);
    }

    public function adminReport(Request $request, StudentProfile $studentProfile, Course $course): JsonResponse
    {
        abort_unless($request->user()->can('view', $studentProfile), 403);
        abort_if($studentProfile->is_archived, 403, 'Archived students are excluded from grade reports.');
        $this->ensureStudentEnrolled($studentProfile, $course);

        return response()->json([
            'data' => $this->grades->calculateStudentTermGrade(
                $studentProfile,
                $course,
                $request->query('term', 'Quarter 1'),
                $request->query('academic_year', $course->classSection?->academic_year),
            ),
        ]);
    }

    private function ensureStudentEnrolled(StudentProfile $studentProfile, Course $course): void
    {
        // Members rather than active-only: a promoted student's enrollment in
        // last year's class is `completed`, and that report must stay readable.
        abort_unless(
            $course->class_section_id
                && $studentProfile->enrollments()
                    ->members()
                    ->where('course_section_id', $course->class_section_id)
                    ->exists(),
            403,
        );
    }
}
