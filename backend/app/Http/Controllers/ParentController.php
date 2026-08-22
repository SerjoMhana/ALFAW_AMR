<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\ParentGuardian;
use App\Models\ReportCardPublication;
use App\Models\StudentProfile;
use App\Services\Finance\StudentLedgerService;
use App\Services\StudentGradesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ParentController extends Controller
{
    public function __construct(
        private readonly StudentGradesService $studentGrades,
        private readonly ReportCardPublicationController $publications,
        private readonly StudentLedgerService $ledger,
    ) {}

    /**
     * The children linked to the signed-in guardian, one card each.
     */
    public function children(Request $request): JsonResponse
    {
        $guardian = $this->guardian($request);

        return response()->json([
            'data' => $guardian->students()
                ->with(['user:id,name,username', 'section:id,class_name,section_code,academic_year'])
                ->get()
                ->filter(fn (StudentProfile $student) => ! $student->is_archived)
                ->map(fn (StudentProfile $student) => [
                    'id' => $student->id,
                    'name' => $student->full_name ?: $student->user?->name,
                    'admission_no' => $student->admission_no ?: $student->student_number,
                    'grade_level' => $student->grade_level,
                    'class_name' => $student->section?->class_name ?: $student->section?->section_code,
                    'academic_year' => $student->academic_year ?: $student->section?->academic_year,
                    'open_terms' => $this->studentGrades->openTermsFor($student),
                ])
                ->values(),
        ]);
    }

    public function childGrades(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->authorizeChild($request, $studentProfile);

        $openTerms = $this->studentGrades->openTermsFor($studentProfile);
        $term = $request->query('term') ?: $openTerms->first();

        abort_if($term === null, 422, 'لم يفتح المدير أي فصل دراسي بعد.');
        abort_unless($openTerms->contains($term), 403, 'هذا الفصل الدراسي غير متاح للاطلاع بعد.');

        return response()->json([
            'data' => [
                'student' => [
                    'id' => $studentProfile->id,
                    'name' => $studentProfile->full_name ?: $studentProfile->user?->name,
                    'admission_no' => $studentProfile->admission_no ?: $studentProfile->student_number,
                ],
                'open_terms' => $openTerms,
                ...$this->studentGrades->forStudent($studentProfile, $term),
            ],
        ]);
    }

    /**
     * What a guardian owes for one child.
     *
     * Deliberately narrower than the cashier's statement: amounts, due dates
     * and receipts, but none of the internal approval trail.
     */
    public function childBalance(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->authorizeChild($request, $studentProfile);

        $academicYear = $request->query('academic_year')
            ?: $studentProfile->academic_year
            ?: (string) AcademicYear::where('is_active', true)->value('name');

        $this->ledger->refreshOverdue($studentProfile, $academicYear);
        $statement = $this->ledger->statement($studentProfile, $academicYear);

        return response()->json([
            'data' => [
                'student' => $statement['student'],
                'academic_year' => $statement['academic_year'],
                'totals' => $statement['totals'],
                'fees' => collect($statement['fees'])->map(fn (array $fee) => [
                    'name' => $fee['name'],
                    'category' => $fee['category'],
                    'amount' => $fee['amount'],
                    'discount_total' => $fee['discount_total'],
                    'net' => $fee['net'],
                    'paid_amount' => $fee['paid_amount'],
                    'outstanding' => $fee['outstanding'],
                    'due_date' => $fee['due_date'],
                    'status' => $fee['status'],
                ])->values(),
                'receipts' => collect($statement['payments'])
                    ->reject(fn (array $payment) => $payment['is_voided'])
                    ->values(),
            ],
        ]);
    }

    public function childReportCards(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->authorizeChild($request, $studentProfile);

        return $this->publications->forStudent($studentProfile);
    }

    public function childReportCardPdf(
        Request $request,
        StudentProfile $studentProfile,
        ReportCardPublication $publication,
    ): Response {
        $this->authorizeChild($request, $studentProfile);

        return $this->publications->downloadFor($studentProfile, $publication);
    }

    private function guardian(Request $request): ParentGuardian
    {
        $guardian = $request->user()->parentGuardian;

        abort_unless($guardian, 404, 'لا يوجد ملف ولي أمر مرتبط بهذا الحساب.');

        return $guardian;
    }

    /**
     * A guardian may only ever reach a student they are linked to on the
     * parent_student pivot. Everything else is a 403.
     */
    private function authorizeChild(Request $request, StudentProfile $studentProfile): void
    {
        $guardian = $this->guardian($request);

        abort_unless(
            $guardian->students()->whereKey($studentProfile->id)->exists(),
            403,
            'لا تملك صلاحية الاطلاع على بيانات هذا الطالب.',
        );

        abort_if($studentProfile->is_archived, 403, 'هذا الطالب مؤرشف.');
    }
}
