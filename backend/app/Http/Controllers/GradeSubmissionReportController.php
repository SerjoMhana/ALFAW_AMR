<?php

namespace App\Http\Controllers;

use App\Services\GradeSubmissionReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class GradeSubmissionReportController extends Controller
{
    public function __construct(private readonly GradeSubmissionReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->report($request)]);
    }

    public function pdf(Request $request): Response
    {
        $report = $this->report($request);

        $pdf = Pdf::loadView('pdf.grade-submission-report', [
            'report' => $report,
            'title' => $this->title($request),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('grade-submission-report-'.$report['academic_year'].'.pdf');
    }

    private function report(Request $request): array
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'type' => ['required', Rule::in(['quarter', 'semester'])],
            'term' => ['required_if:type,quarter', Rule::in(['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'])],
            'semester' => ['required_if:type,semester', Rule::in([1, 2])],
            'class_section_id' => ['nullable', 'integer', 'exists:course_sections,id'],
        ]);

        $terms = GradeSubmissionReportService::resolveTerms(
            $validated['type'],
            $validated['term'] ?? null,
            isset($validated['semester']) ? (int) $validated['semester'] : null,
        );

        abort_if($terms === [], 422, 'Could not resolve the terms for this report.');

        return $this->reports->build(
            $validated['academic_year'],
            $terms,
            $validated['class_section_id'] ?? null,
        );
    }

    private function title(Request $request): string
    {
        return $request->query('type') === 'semester'
            ? 'Semester '.$request->query('semester')
            : (string) $request->query('term');
    }
}
