<?php

namespace App\Services\Finance;

use App\Models\FinanceAuditLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\StudentFee;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /**
     * Records a receipt and spreads it over the student's charges.
     *
     * @param  array<int, array{student_fee_id: int, amount: float}>  $allocations
     *                                                                            empty means allocate automatically, oldest due date first
     */
    public function record(
        StudentProfile $student,
        string $academicYear,
        float $amount,
        string $method,
        User $actor,
        array $allocations = [],
        ?string $reference = null,
        ?string $paidOn = null,
        ?string $notes = null,
    ): Payment {
        abort_if($amount <= 0, 422, 'مبلغ الدفعة يجب أن يكون أكبر من صفر.');

        return DB::transaction(function () use (
            $student, $academicYear, $amount, $method, $actor, $allocations, $reference, $paidOn, $notes
        ) {
            $payment = Payment::create([
                'student_profile_id' => $student->id,
                'academic_year' => $academicYear,
                'receipt_number' => $this->nextReceiptNumber($academicYear),
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'paid_on' => $paidOn ?? now()->toDateString(),
                'notes' => $notes,
                'received_by' => $actor->id,
            ]);

            $lines = $allocations === []
                ? $this->autoAllocate($student, $academicYear, $amount)
                : $this->validateManualAllocation($student, $academicYear, $amount, $allocations);

            foreach ($lines as $line) {
                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'student_fee_id' => $line['student_fee_id'],
                    'amount' => $line['amount'],
                ]);

                $this->addToFee((int) $line['student_fee_id'], (float) $line['amount']);
            }

            FinanceAuditLog::record($payment, 'payment.recorded', null, [
                'receipt_number' => $payment->receipt_number,
                'amount' => (float) $payment->amount,
                'method' => $method,
                'allocated' => $lines,
            ], $actor->id);

            return $payment->load('allocations');
        });
    }

    /**
     * Reverses a receipt without destroying it: the row and its serial stay,
     * the allocations are undone, and the reason is recorded.
     */
    public function void(Payment $payment, User $actor, string $reason): Payment
    {
        abort_if($payment->isVoided(), 422, 'هذا الإيصال ملغى بالفعل.');

        return DB::transaction(function () use ($payment, $actor, $reason) {
            $before = [
                'receipt_number' => $payment->receipt_number,
                'amount' => (float) $payment->amount,
                'allocations' => $payment->allocations
                    ->map(fn (PaymentAllocation $row) => [
                        'student_fee_id' => $row->student_fee_id,
                        'amount' => (float) $row->amount,
                    ])->all(),
            ];

            foreach ($payment->allocations as $allocation) {
                $this->addToFee((int) $allocation->student_fee_id, -(float) $allocation->amount);
            }

            $payment->update([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ]);

            FinanceAuditLog::record($payment, 'payment.voided', $before, ['reason' => $reason], $actor->id);

            return $payment->fresh(['allocations']);
        });
    }

    /**
     * Serials are per year and gap-free. The unique index means a concurrent
     * insert fails outright rather than quietly reusing a number.
     */
    private function nextReceiptNumber(string $academicYear): int
    {
        return (int) Payment::query()
            ->where('academic_year', $academicYear)
            ->lockForUpdate()
            ->max('receipt_number') + 1;
    }

    /**
     * Oldest debt first, which is what a cashier expects by default.
     *
     * @return array<int, array{student_fee_id: int, amount: float}>
     */
    private function autoAllocate(StudentProfile $student, string $academicYear, float $amount): array
    {
        $remaining = round($amount, 2);
        $lines = [];

        $fees = StudentFee::query()
            ->with('discounts')
            ->where('student_profile_id', $student->id)
            ->where('academic_year', $academicYear)
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        foreach ($fees as $fee) {
            if ($remaining <= 0) {
                break;
            }

            $due = $fee->outstanding();

            if ($due <= 0) {
                continue;
            }

            $take = round(min($due, $remaining), 2);
            $lines[] = ['student_fee_id' => $fee->id, 'amount' => $take];
            $remaining = round($remaining - $take, 2);
        }

        abort_if(
            $remaining > 0.001,
            422,
            'المبلغ المدفوع يتجاوز إجمالي المتبقي على الطالب بمقدار '.number_format($remaining, 2).'.',
        );

        return $lines;
    }

    /**
     * @param  array<int, array{student_fee_id: int, amount: float}>  $allocations
     * @return array<int, array{student_fee_id: int, amount: float}>
     */
    private function validateManualAllocation(
        StudentProfile $student,
        string $academicYear,
        float $amount,
        array $allocations,
    ): array {
        $ids = collect($allocations)->pluck('student_fee_id')->map(fn ($id) => (int) $id);

        $fees = StudentFee::query()
            ->with('discounts')
            ->whereIn('id', $ids)
            ->where('student_profile_id', $student->id)
            ->where('academic_year', $academicYear)
            ->get()
            ->keyBy('id');

        abort_unless(
            $fees->count() === $ids->unique()->count(),
            422,
            'أحد الرسوم المختارة لا يخص هذا الطالب أو هذه السنة الدراسية.',
        );

        $lines = [];
        $total = 0.0;

        foreach ($allocations as $line) {
            $value = round((float) $line['amount'], 2);

            if ($value <= 0) {
                continue;
            }

            $fee = $fees->get((int) $line['student_fee_id']);

            abort_if(
                $value > $fee->outstanding() + 0.001,
                422,
                "المبلغ المخصص لرسم «{$fee->name}» يتجاوز المتبقي عليه.",
            );

            $lines[] = ['student_fee_id' => $fee->id, 'amount' => $value];
            $total = round($total + $value, 2);
        }

        abort_if(
            abs($total - round($amount, 2)) > 0.001,
            422,
            'مجموع التخصيص لا يساوي المبلغ المدفوع.',
        );

        return $lines;
    }

    private function addToFee(int $feeId, float $delta): void
    {
        $fee = StudentFee::with('discounts')->lockForUpdate()->findOrFail($feeId);
        $fee->paid_amount = round(max((float) $fee->paid_amount + $delta, 0), 2);
        $fee->save();
        $fee->refreshStatus();
    }

    /**
     * @return Collection<int, Payment>
     */
    public function receiptsFor(StudentProfile $student, ?string $academicYear = null): Collection
    {
        return Payment::query()
            ->with(['allocations.fee', 'receivedBy:id,name'])
            ->where('student_profile_id', $student->id)
            ->when($academicYear, fn ($query) => $query->where('academic_year', $academicYear))
            ->orderByDesc('paid_on')
            ->orderByDesc('receipt_number')
            ->get();
    }
}
