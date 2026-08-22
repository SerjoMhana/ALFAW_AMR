<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Services\ReportCardService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportCardPdfController extends Controller
{
    public function __construct(private readonly ReportCardService $reportCards) {}

    public function student(Request $request): Response
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile, 404);

        return $this->download($studentProfile, $request);
    }

    public function admin(Request $request, StudentProfile $studentProfile): Response
    {
        abort_unless($request->user()->can('view', $studentProfile), 403);

        return $this->download($studentProfile, $request);
    }

    private function download(StudentProfile $studentProfile, Request $request): Response
    {
        $type = $request->query('type', 'quarter');
        abort_unless(in_array($type, ['quarter', 'semester', 'final'], true), 422, 'Invalid report card type.');

        $data = $this->reportCards->build($studentProfile, $type, $request->query('term'));
        $pdf = Pdf::loadView('pdf.report-card', $data)->setPaper('letter');

        return $pdf->download('report-card-'.$studentProfile->student_number.'-'.$type.'.pdf');
    }
}
