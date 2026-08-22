<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\StudentProfile;
use App\Services\Finance\PaymentService;
use App\Services\Finance\StudentLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Receipts are written once. There is deliberately no update or destroy action
 * here — correcting a mistake means voiding, which leaves the original intact.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly StudentLedgerService $ledger,
    ) {}

    public function store(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(PaymentMethod::activeNames())],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['sometimes', 'array'],
            'allocations.*.student_fee_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $payment = $this->payments->record(
            $studentProfile,
            $validated['academic_year'],
            (float) $validated['amount'],
            $validated['method'],
            $request->user(),
            $validated['allocations'] ?? [],
            $validated['reference'] ?? null,
            $validated['paid_on'] ?? null,
            $validated['notes'] ?? null,
        );

        return response()->json([
            'data' => [
                'payment' => $payment,
                'statement' => $this->ledger->statement($studentProfile, $validated['academic_year']),
            ],
        ], 201);
    }

    public function void(Request $request, Payment $payment): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $voided = $this->payments->void($payment, $request->user(), $validated['reason']);

        return response()->json([
            'data' => [
                'payment' => $voided,
                'statement' => $this->ledger->statement(
                    $payment->studentProfile,
                    $payment->academic_year,
                ),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json([
            'data' => $payment->load(['allocations.fee', 'receivedBy:id,name', 'voidedBy:id,name', 'studentProfile.user:id,name']),
        ]);
    }
}
