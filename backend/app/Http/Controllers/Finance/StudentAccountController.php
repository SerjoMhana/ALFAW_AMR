<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceAuditLog;
use App\Models\StudentDiscount;
use App\Models\StudentFee;
use App\Models\StudentProfile;
use App\Services\Finance\FeeAssignmentService;
use App\Services\Finance\StudentLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAccountController extends Controller
{
    public function __construct(
        private readonly StudentLedgerService $ledger,
        private readonly FeeAssignmentService $fees,
    ) {}

    /**
     * Statement of account for one student.
     */
    public function show(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $academicYear = $this->academicYear($request, $studentProfile);
        $this->ledger->refreshOverdue($studentProfile, $academicYear);

        return response()->json([
            'data' => $this->ledger->statement($studentProfile, $academicYear),
        ]);
    }

    /**
     * Raise the year's charges and build the schedule.
     */
    public function assignFees(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'extra_template_ids' => ['sometimes', 'array'],
            'extra_template_ids.*' => ['integer', 'exists:fee_templates,id'],
        ]);

        $summary = $this->fees->assign(
            $studentProfile,
            $validated['academic_year'],
            $validated['extra_template_ids'] ?? [],
        );

        return response()->json([
            'data' => [
                'summary' => $summary,
                'statement' => $this->ledger->statement($studentProfile, $validated['academic_year']),
            ],
        ]);
    }

    /**
     * A one-off charge that has no template behind it.
     */
    public function addFee(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'academic_year' => ['required', 'string', 'max:20'],
            'due_date' => ['nullable', 'date'],
        ]);

        $fee = StudentFee::create([
            'student_profile_id' => $studentProfile->id,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'academic_year' => $validated['academic_year'],
            'amount' => $validated['amount'],
            'due_date' => $validated['due_date'] ?? null,
        ]);

        FinanceAuditLog::record($fee, 'fee.added_manually', null, [
            'name' => $fee->name,
            'amount' => (float) $fee->amount,
        ], $request->user()->id);

        $fee->load('discounts')->refreshStatus();

        return response()->json([
            'data' => $this->ledger->statement($studentProfile, $validated['academic_year']),
        ], 201);
    }

    /**
     * Requesting a discount is deliberately separate from approving one, so a
     * manual discount is created pending and someone else must sign it off.
     */
    public function requestDiscount(Request $request, StudentFee $studentFee): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'percentage' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        abort_if(
            ! isset($validated['percentage']) && ! isset($validated['amount']),
            422,
            'حدد نسبة الخصم أو مبلغه.',
        );

        $amount = isset($validated['percentage'])
            ? round((float) $studentFee->amount * (float) $validated['percentage'] / 100, 2)
            : round((float) $validated['amount'], 2);

        abort_if(
            $amount > (float) $studentFee->amount,
            422,
            'الخصم لا يمكن أن يتجاوز قيمة الرسم.',
        );

        $discount = StudentDiscount::create([
            'student_fee_id' => $studentFee->id,
            'reason' => $validated['reason'],
            'percentage' => $validated['percentage'] ?? null,
            'amount' => $amount,
            'status' => StudentDiscount::STATUS_PENDING,
            'requested_by' => $request->user()->id,
        ]);

        FinanceAuditLog::record($discount, 'discount.requested', null, [
            'amount' => $amount,
            'reason' => $validated['reason'],
        ], $request->user()->id);

        return response()->json(['data' => $discount], 201);
    }

    private function academicYear(Request $request, StudentProfile $student): string
    {
        return $request->query('academic_year')
            ?: $student->academic_year
            ?: (string) \App\Models\AcademicYear::where('is_active', true)->value('name');
    }
}
