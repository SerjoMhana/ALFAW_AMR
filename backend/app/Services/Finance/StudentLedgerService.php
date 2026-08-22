<?php

namespace App\Services\Finance;

use App\Models\Payment;
use App\Models\StudentDiscount;
use App\Models\StudentFee;
use App\Models\StudentProfile;

/**
 * One statement of account, shared by the cashier screen and the parent portal
 * so both always quote the same numbers.
 */
class StudentLedgerService
{
    public function __construct(private readonly FeeAssignmentService $fees) {}

    public function statement(StudentProfile $student, string $academicYear): array
    {
        $fees = StudentFee::query()
            ->with(['discounts.rule', 'discounts.requestedBy:id,name', 'discounts.approvedBy:id,name'])
            ->where('student_profile_id', $student->id)
            ->where('academic_year', $academicYear)
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        // Voided receipts are shown for the record but never counted.
        $payments = Payment::query()
            ->with('receivedBy:id,name')
            ->where('student_profile_id', $student->id)
            ->where('academic_year', $academicYear)
            ->orderByDesc('receipt_number')
            ->get();

        $gross = round((float) $fees->sum(fn (StudentFee $fee) => (float) $fee->amount), 2);
        $discounts = round((float) $fees->sum(fn (StudentFee $fee) => $fee->approvedDiscountTotal()), 2);
        $net = round($gross - $discounts, 2);
        $paid = round((float) $fees->sum(fn (StudentFee $fee) => (float) $fee->paid_amount), 2);
        $overdue = $fees
            ->filter(fn (StudentFee $fee) => $fee->status === StudentFee::STATUS_OVERDUE)
            ->sum(fn (StudentFee $fee) => $fee->outstanding());

        return [
            'student' => [
                'id' => $student->id,
                'name' => $student->full_name ?: $student->user?->name,
                'admission_no' => $student->admission_no ?: $student->student_number,
                'grade_level' => $student->grade_level,
            ],
            'academic_year' => $academicYear,
            'totals' => [
                'gross' => $gross,
                'discounts' => $discounts,
                'net' => $net,
                'paid' => $paid,
                'outstanding' => round($net - $paid, 2),
                'overdue' => round((float) $overdue, 2),
            ],
            'fees' => $fees->map(fn (StudentFee $fee) => [
                'id' => $fee->id,
                'name' => $fee->name,
                'category' => $fee->category,
                'amount' => (float) $fee->amount,
                'discount_total' => $fee->approvedDiscountTotal(),
                'net' => $fee->netAmount(),
                'paid_amount' => (float) $fee->paid_amount,
                'outstanding' => $fee->outstanding(),
                'due_date' => $fee->due_date?->toDateString(),
                'status' => $fee->status,
                'discounts' => $fee->discounts->map(fn (StudentDiscount $discount) => [
                    'id' => $discount->id,
                    'reason' => $discount->reason,
                    'percentage' => $discount->percentage !== null ? (float) $discount->percentage : null,
                    'amount' => (float) $discount->amount,
                    'status' => $discount->status,
                    'requested_by' => $discount->requestedBy?->name,
                    'approved_by' => $discount->approvedBy?->name,
                ])->values(),
            ])->values(),
            'payments' => $payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'amount' => (float) $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'paid_on' => $payment->paid_on?->toDateString(),
                'received_by' => $payment->receivedBy?->name,
                'is_voided' => $payment->isVoided(),
                'void_reason' => $payment->void_reason,
            ])->values(),
        ];
    }

    /**
     * Marks anything past its due date that still owes money, so reports and
     * the parent portal reflect reality without a scheduled job.
     */
    public function refreshOverdue(StudentProfile $student, string $academicYear): void
    {
        $this->fees->refreshStatuses($student, $academicYear);
    }
}
