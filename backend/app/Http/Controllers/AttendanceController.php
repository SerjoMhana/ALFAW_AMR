<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkSaveAttendanceRequest;
use App\Models\AttendanceRecord;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Services\AttendanceMonthService;
use App\Services\ReportCardTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AttendanceController extends Controller
{
    public function section(Request $request, CourseSection $courseSection): JsonResponse
    {
        $this->authorizeSection($request, $courseSection);
        $date = $request->query('date', now()->toDateString());
        $records = AttendanceRecord::where('course_section_id', $courseSection->id)
            ->whereDate('attendance_date', $date)
            ->get()
            ->keyBy('student_profile_id');

        return response()->json([
            'section' => $courseSection,
            'attendance_date' => $date,
            'students' => Enrollment::with('studentProfile.user')
                ->where('course_section_id', $courseSection->id)
                ->where('status', 'active')
                ->whereHas('studentProfile', fn ($query) => $query->active())
                ->get()
                ->map(fn (Enrollment $enrollment) => [
                    'student_profile' => $enrollment->studentProfile,
                    'record' => $records->get($enrollment->student_profile_id),
                ])
                ->values(),
        ]);
    }

    public function bulkSave(BulkSaveAttendanceRequest $request, CourseSection $courseSection): JsonResponse
    {
        $records = collect($request->validated('records'))
            ->map(fn (array $record) => AttendanceRecord::updateOrCreate(
                [
                    'course_section_id' => $courseSection->id,
                    'student_profile_id' => $record['student_profile_id'],
                    'attendance_date' => $request->date('attendance_date')->toDateString(),
                ],
                [
                    'status' => $record['status'],
                    'notes' => $record['notes'] ?? null,
                    'recorded_by' => $request->user()->id,
                ],
            ))
            ->values();

        return response()->json(['data' => $records]);
    }

    /**
     * The month's register on screen: the same grid that gets printed.
     */
    public function month(Request $request, CourseSection $courseSection): JsonResponse
    {
        $this->authorizeSection($request, $courseSection);

        [$year, $month] = $this->monthOf($request);

        return response()->json([
            'data' => app(AttendanceMonthService::class)->build($courseSection, $year, $month),
        ]);
    }

    /**
     * The printed sheet.
     *
     * Blank is the default because that is the order of work: the office prints
     * the month, the supervisors mark it by hand through the month, and the
     * paper comes back to be entered. Printing it filled is for the copy kept
     * afterwards.
     */
    public function monthPdf(Request $request, CourseSection $courseSection): Response
    {
        $this->authorizeSection($request, $courseSection);

        [$year, $month] = $this->monthOf($request);
        $sheet = app(AttendanceMonthService::class)->build($courseSection, $year, $month);
        $filled = $request->boolean('filled');

        $pdf = Pdf::loadView('pdf.attendance-month', [
            'sheet' => $sheet,
            'filled' => $filled,
            'school' => ReportCardTemplate::get()['school_name'] ?? '',
            'generatedAt' => now()->format('Y-m-d'),
        ])->setPaper('a4', 'landscape');

        $name = sprintf(
            'attendance-%s-%04d-%02d%s.pdf',
            $sheet['section']['section_code'] ?: $courseSection->id,
            $year,
            $month,
            $filled ? '' : '-blank',
        );

        return $pdf->download($name);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function monthOf(Request $request): array
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return [
            (int) ($validated['year'] ?? now()->year),
            (int) ($validated['month'] ?? now()->month),
        ];
    }

    public function student(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile && ! $studentProfile->is_archived, 404);

        return response()->json([
            'data' => AttendanceRecord::with('courseSection')
                ->where('student_profile_id', $studentProfile->id)
                ->latest('attendance_date')
                ->get(),
        ]);
    }

    /**
     * The register belongs to the school office, not to the classroom.
     *
     * Attendance is kept by whoever runs the system, or by a member of staff
     * given the permission explicitly — a subject teacher gets no say in it by
     * virtue of teaching the class.
     */
    private function authorizeSection(Request $request, CourseSection $courseSection): void
    {
        $user = $request->user();

        abort_unless(
            $user->isAdmin()
            || $user->hasPermission('attendance.view')
            || $user->hasPermission('attendance.manage'),
            403,
        );
    }
}
