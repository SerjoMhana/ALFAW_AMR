<?php

namespace App\Http\Controllers;

use App\Models\ReportCardPublication;
use App\Models\StudentProfile;
use App\Services\GPAService;
use App\Services\GradeCalculationService;
use App\Services\StudentGradesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudentDashboardController extends Controller
{
    public function __construct(
        private readonly GradeCalculationService $grades,
        private readonly GPAService $gpa,
        private readonly StudentGradesService $studentGrades,
    ) {}

    /**
     * Terms the admin has released to this student, for the period pickers.
     */
    public function terms(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        abort_unless($studentProfile && ! $studentProfile->is_archived, 404, 'Student profile not found.');

        return response()->json([
            'data' => $this->studentGrades->openTermsFor($studentProfile),
        ]);
    }

    /**
     * Report cards the admin has released to this student's class.
     */
    public function reportCards(Request $request, ReportCardPublicationController $publications): JsonResponse
    {
        return $publications->forStudent($this->profile($request));
    }

    public function reportCardPdf(
        Request $request,
        ReportCardPublication $publication,
        ReportCardPublicationController $publications,
    ): Response {
        return $publications->downloadFor($this->profile($request), $publication);
    }

    private function profile(Request $request): StudentProfile
    {
        $studentProfile = $request->user()->studentProfile;

        abort_unless($studentProfile && ! $studentProfile->is_archived, 404, 'Student profile not found.');

        return $studentProfile;
    }

    public function dashboard(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        abort_unless($studentProfile && ! $studentProfile->is_archived, 404, 'Student profile not found.');

        $studentProfile->load('user');
        $openTerms = $this->studentGrades->openTermsFor($studentProfile);
        $term = $request->query('term') ?: $openTerms->first();

        // A student with no active enrollment simply has nothing to show — that
        // is not the same as being blocked from a term the admin has closed.
        if ($this->studentGrades->activeEnrollments($studentProfile)->isEmpty()) {
            return response()->json([
                'student_profile' => $studentProfile,
                'term' => $term,
                'open_terms' => $openTerms,
                'courses' => [],
                'gpa' => ['term' => null],
            ]);
        }

        abort_if($term === null, 422, 'لم تفتح الإدارة أي فصل دراسي بعد.');
        abort_unless($openTerms->contains($term), 403, 'هذا الفصل الدراسي غير متاح للاطلاع بعد.');

        return response()->json([
            'student_profile' => $studentProfile,
            'term' => $term,
            'open_terms' => $openTerms,
            'courses' => $this->studentGrades->forStudent($studentProfile, $term)['courses'],
            'gpa' => [
                'term' => $this->gpa->termGpa($studentProfile, $term),
            ],
        ]);
    }

    public function reportCard(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        abort_unless($studentProfile && ! $studentProfile->is_archived, 404, 'Student profile not found.');

        // Only quarters the admin has released — never the whole year.
        $terms = $this->studentGrades->openTermsFor($studentProfile)->all();
        $studentProfile->load('user');
        $enrollments = $studentProfile->enrollments()
            ->with('courseSection.courses')
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->get();

        return response()->json([
            'student_profile' => $studentProfile,
            'terms' => collect($terms)->map(fn (string $term) => [
                'term' => $term,
                'courses' => $enrollments->flatMap(fn ($enrollment) => $enrollment->courseSection->courses->map(fn ($course) => [
                    'section' => $enrollment->courseSection,
                    'course' => $course,
                    'report' => $this->grades->calculateStudentTermGrade($studentProfile, $course, $term, $enrollment->courseSection->academic_year),
                ]))->values(),
                'gpa' => $this->gpa->termGpa($studentProfile, $term),
            ])->values(),
        ]);
    }
}
