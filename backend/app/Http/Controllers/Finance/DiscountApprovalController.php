<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceAuditLog;
use App\Models\StudentDiscount;
use App\Services\Finance\FeeAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountApprovalController extends Controller
{
    public function __construct(private readonly FeeAssignmentService $fees) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => StudentDiscount::query()
                ->with([
                    'studentFee.studentProfile.user:id,name',
                    'requestedBy:id,name',
                ])
                ->where('status', StudentDiscount::STATUS_PENDING)
                ->latest()
                ->get()
                ->map(fn (StudentDiscount $discount) => [
                    'id' => $discount->id,
                    'student' => $discount->studentFee?->studentProfile?->full_name
                        ?: $discount->studentFee?->studentProfile?->user?->name,
                    'student_profile_id' => $discount->studentFee?->student_profile_id,
                    'fee_name' => $discount->studentFee?->name,
                    'fee_amount' => (float) ($discount->studentFee?->amount ?? 0),
                    'reason' => $discount->reason,
                    'percentage' => $discount->percentage !== null ? (float) $discount->percentage : null,
                    'amount' => (float) $discount->amount,
                    'requested_by' => $discount->requestedBy?->name,
                    'requested_at' => $discount->created_at?->toDateTimeString(),
                ])
                ->values(),
        ]);
    }

    public function approve(Request $request, StudentDiscount $studentDiscount): JsonResponse
    {
        return $this->decide($request, $studentDiscount, StudentDiscount::STATUS_APPROVED);
    }

    public function reject(Request $request, StudentDiscount $studentDiscount): JsonResponse
    {
        return $this->decide($request, $studentDiscount, StudentDiscount::STATUS_REJECTED);
    }

    private function decide(Request $request, StudentDiscount $discount, string $status): JsonResponse
    {
        abort_unless(
            $discount->status === StudentDiscount::STATUS_PENDING,
            422,
            'تمت معالجة هذا الطلب مسبقاً.',
        );

        // The approver must not be the person who asked for it.
        abort_if(
            $discount->requested_by === $request->user()->id,
            403,
            'لا يمكنك اعتماد خصم طلبته بنفسك.',
        );

        $before = ['status' => $discount->status];

        $discount->update([
            'status' => $status,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        FinanceAuditLog::record($discount, 'discount.'.$status, $before, [
            'status' => $status,
            'amount' => (float) $discount->amount,
        ], $request->user()->id);

        // An approved discount changes what is owed, so the charge's status follows.
        if ($status === StudentDiscount::STATUS_APPROVED) {
            $discount->studentFee?->load('discounts')->refreshStatus();
        }

        return response()->json(['data' => $discount->fresh()]);
    }
}
