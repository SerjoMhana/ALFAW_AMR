<?php

namespace App\Http\Controllers;

use App\Models\CourseSection;
use App\Models\SchoolSetting;
use App\Models\StudentProfile;
use App\Services\ClassReportCardService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ClassReportCardController extends Controller
{
    public function __construct(private readonly ClassReportCardService $reports) {}

    public function quarter(Request $request, CourseSection $courseSection): Response
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'term' => ['required', Rule::in(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])],
            'student_profile_id' => ['nullable', 'integer', 'exists:student_profiles,id'],
        ]);

        $students = $this->resolveStudents($courseSection, $validated['student_profile_id'] ?? null);
        $this->ensureComplete($courseSection, $students, [$validated['term']]);

        $reports = $students
            ->map(fn (StudentProfile $student) => $this->reports->quarterReport($courseSection, $student, $validated['term']))
            ->all();

        $pdf = Pdf::loadView('pdf.quarter-report', ['reports' => $reports])->setPaper('a4');

        return $pdf->download($this->fileName($courseSection, $students, str_replace(' ', '-', strtolower($validated['term']))));
    }

    public function semester(Request $request, CourseSection $courseSection): Response
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'semester' => ['required', 'integer', Rule::in([1, 2])],
            'student_profile_id' => ['nullable', 'integer', 'exists:student_profiles,id'],
        ]);

        $semester = (int) $validated['semester'];
        $students = $this->resolveStudents($courseSection, $validated['student_profile_id'] ?? null);

        $this->ensureComplete($courseSection, $students, ClassReportCardService::SEMESTER_TERMS[$semester]);

        $reports = $students
            ->map(fn (StudentProfile $student) => $this->reports->semesterReport($courseSection, $student, $semester))
            ->all();

        $pdf = Pdf::loadView('pdf.semester-report', [
            'reports' => $reports,
            'semester' => $semester,
            'reportMessage' => SchoolSetting::semesterReportMessage(),
        ])->setPaper('a4');

        return $pdf->download($this->fileName($courseSection, $students, "semester-{$semester}"));
    }

    public function final(Request $request, CourseSection $courseSection): Response
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'student_profile_id' => ['nullable', 'integer', 'exists:student_profiles,id'],
        ]);
        $students = $this->resolveStudents($courseSection, $validated['student_profile_id'] ?? null);
        $this->ensureComplete($courseSection, $students, [
            ...ClassReportCardService::SEMESTER_TERMS[1],
            ...ClassReportCardService::SEMESTER_TERMS[2],
        ]);
        $reports = $students
            ->map(fn (StudentProfile $student) => $this->reports->finalReport($courseSection, $student))
            ->all();
        $pdf = Pdf::loadView('pdf.semester-report', [
            'reports' => $reports,
            'semester' => null,
            'isFinal' => true,
            'reportMessage' => SchoolSetting::semesterReportMessage(),
        ])->setPaper('a4');

        return $pdf->download($this->fileName($courseSection, $students, 'final'));
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user->isAdmin() || $user->hasPermission('admin_manage_grades') || $user->hasPermission('students.view'),
            403,
        );
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    private function resolveStudents(CourseSection $courseSection, ?int $studentProfileId): Collection
    {
        // Members rather than active-only, so report cards can still be produced
        // for an earlier year after the class has moved up.
        $students = $courseSection->enrollments()
            ->members()
            ->with('studentProfile.user')
            ->when($studentProfileId, fn ($query) => $query->where('student_profile_id', $studentProfileId))
            ->orderBy('student_profile_id')
            ->get()
            ->map(fn ($enrollment) => $enrollment->studentProfile);

        abort_if($students->isEmpty(), 422, $studentProfileId
            ? 'This student is not enrolled in the selected class.'
            : 'No active students are enrolled in the selected class.');

        return $students;
    }

    private function ensureComplete(CourseSection $courseSection, Collection $students, array $terms): void
    {
        abort_if($courseSection->courses()->count() === 0, 422, 'The selected class has no subjects.');

        $missing = $this->reports->findMissingScores($courseSection, $students, $terms);

        if (! empty($missing)) {
            abort(response()->json([
                'message' => 'Grades are incomplete. Enter all missing scores before generating the report.',
                'missing' => $missing,
            ], 422));
        }
    }

    private function fileName(CourseSection $courseSection, Collection $students, string $suffix): string
    {
        $scope = $students->count() === 1
            ? ($students->first()->admission_no ?: $students->first()->student_number)
            : ($courseSection->class_name ?: $courseSection->section_code);

        return 'report-card-'.preg_replace('/[^A-Za-z0-9\-]+/', '-', $scope)."-{$suffix}.pdf";
    }
}
