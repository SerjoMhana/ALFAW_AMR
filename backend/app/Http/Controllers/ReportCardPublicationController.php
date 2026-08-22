<?php

namespace App\Http\Controllers;

use App\Models\CourseSection;
use App\Models\ReportCardPublication;
use App\Models\StudentProfile;
use App\Services\ReportCardService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ReportCardPublicationController extends Controller
{
    public function __construct(private readonly ReportCardService $reportCards) {}

    /**
     * Everything the admin has released for a class, so the UI can show which
     * periods are already out.
     */
    public function index(Request $request, CourseSection $courseSection): JsonResponse
    {
        return response()->json([
            'data' => ReportCardPublication::query()
                ->with('publishedBy:id,name')
                ->where('course_section_id', $courseSection->id)
                ->orderByDesc('published_at')
                ->get(),
        ]);
    }

    public function store(Request $request, CourseSection $courseSection): JsonResponse
    {
        $validated = $this->validatePeriod($request);
        $period = ReportCardPublication::periodFor(
            $validated['type'],
            $validated['term'] ?? null,
            isset($validated['semester']) ? (int) $validated['semester'] : null,
        );

        $publication = ReportCardPublication::updateOrCreate(
            [
                'course_section_id' => $courseSection->id,
                'academic_year' => $courseSection->academic_year,
                'period' => $period,
            ],
            [
                'type' => $validated['type'],
                'published_at' => now(),
                'published_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'data' => $publication->fresh()->load('publishedBy:id,name'),
        ], 201);
    }

    public function destroy(Request $request, CourseSection $courseSection): JsonResponse
    {
        $validated = $this->validatePeriod($request);
        $period = ReportCardPublication::periodFor(
            $validated['type'],
            $validated['term'] ?? null,
            isset($validated['semester']) ? (int) $validated['semester'] : null,
        );

        ReportCardPublication::query()
            ->where('course_section_id', $courseSection->id)
            ->where('academic_year', $courseSection->academic_year)
            ->where('period', $period)
            ->delete();

        return response()->json(status: 204);
    }

    /**
     * Report cards released to this student's class.
     */
    public function forStudent(StudentProfile $studentProfile): JsonResponse
    {
        return response()->json([
            'data' => $this->publicationsFor($studentProfile)->values(),
        ]);
    }

    public function downloadFor(StudentProfile $studentProfile, ReportCardPublication $publication): Response
    {
        abort_unless(
            $this->publicationsFor($studentProfile)->contains(fn ($row) => $row->id === $publication->id),
            404,
            'This report card has not been published for this student.',
        );

        $data = $this->reportCards->build($studentProfile, $publication->type, $publication->period);
        $pdf = Pdf::loadView('pdf.report-card', $data)->setPaper('letter');

        return $pdf->download(
            'report-card-'.$studentProfile->student_number.'-'.str_replace(' ', '-', $publication->period).'.pdf',
        );
    }

    /**
     * A publication reaches a student through their active class enrollments.
     */
    private function publicationsFor(StudentProfile $studentProfile)
    {
        $sectionIds = $studentProfile->enrollments()
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->pluck('course_section_id');

        return ReportCardPublication::query()
            ->with('courseSection:id,class_name,section_code,academic_year')
            ->whereIn('course_section_id', $sectionIds)
            ->orderByDesc('published_at')
            ->get();
    }

    private function validatePeriod(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in([ReportCardPublication::TYPE_QUARTER, ReportCardPublication::TYPE_SEMESTER])],
            'term' => ['required_if:type,quarter', Rule::in(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])],
            'semester' => ['required_if:type,semester', Rule::in([1, 2])],
        ]);
    }
}
