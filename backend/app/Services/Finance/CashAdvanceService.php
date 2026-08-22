<?php

namespace App\Services\Finance;

use App\Models\CashAdvance;
use App\Models\CashAdvanceExpense;
use App\Models\FinanceAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The life of an advance: issued, spent against, then closed.
 *
 * Closing takes no amount from the caller. What is handed back — or what the
 * school owes — follows from the receipts already recorded, so an advance can
 * never be signed off on a figure nobody can account for.
 */
class CashAdvanceService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function issue(array $data, User $actor): CashAdvance
    {
        abort_if((float) $data['amount'] <= 0, 422, 'مبلغ العهدة يجب أن يكون أكبر من صفر.');

        return DB::transaction(function () use ($data, $actor) {
            $advance = CashAdvance::create([
                'academic_year' => $data['academic_year'],
                'advance_number' => $this->nextNumber($data['academic_year']),
                'holder_id' => $data['holder_id'] ?? null,
                'holder_name' => $data['holder_name'],
                'purpose' => $data['purpose'],
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'issued_on' => $data['issued_on'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'status' => CashAdvance::STATUS_OPEN,
                'issued_by' => $actor->id,
            ]);

            FinanceAuditLog::record($advance, 'advance.issued', null, $advance->toArray(), $actor->id);

            return $advance;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addExpense(CashAdvance $advance, array $data, User $actor): CashAdvanceExpense
    {
        $this->assertOpen($advance);
        abort_if((float) $data['amount'] <= 0, 422, 'مبلغ الصرف يجب أن يكون أكبر من صفر.');

        return DB::transaction(function () use ($advance, $data, $actor) {
            $expense = CashAdvanceExpense::create([
                'cash_advance_id' => $advance->id,
                'description' => $data['description'],
                'amount' => $data['amount'],
                'spent_on' => $data['spent_on'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            FinanceAuditLog::record($expense, 'advance.expense.added', null, $expense->toArray(), $actor->id);

            return $expense;
        });
    }

    public function removeExpense(CashAdvanceExpense $expense, User $actor): void
    {
        $this->assertOpen($expense->advance);

        DB::transaction(function () use ($expense, $actor): void {
            FinanceAuditLog::record($expense, 'advance.expense.removed', $expense->toArray(), null, $actor->id);
            $expense->delete();
        });
    }

    /**
     * Closes the advance and records which way the difference went.
     *
     * @return array{returned: float, reimbursed: float}
     */
    public function settle(CashAdvance $advance, User $actor): array
    {
        $this->assertOpen($advance);

        $spent = $advance->spent();
        // Whatever was not spent comes back; whatever was overspent is owed.
        $returned = round(max(0, (float) $advance->amount - $spent), 2);
        $reimbursed = round(max(0, $spent - (float) $advance->amount), 2);

        DB::transaction(function () use ($advance, $actor, $returned, $reimbursed): void {
            $before = $advance->toArray();

            $advance->update([
                'status' => CashAdvance::STATUS_SETTLED,
                'returned_amount' => $returned,
                'reimbursed_amount' => $reimbursed,
                'settled_at' => now(),
                'settled_by' => $actor->id,
            ]);

            FinanceAuditLog::record($advance, 'advance.settled', $before, $advance->fresh()->toArray(), $actor->id);
        });

        return ['returned' => $returned, 'reimbursed' => $reimbursed];
    }

    /**
     * Puts a closed advance back into play — for the receipt that turns up a
     * week late. Logged, and reserved to whoever signs advances off.
     */
    public function reopen(CashAdvance $advance, User $actor): CashAdvance
    {
        abort_unless(
            $advance->status === CashAdvance::STATUS_SETTLED,
            422,
            'لا يمكن إعادة فتح عهدة غير مقفلة.',
        );

        $before = $advance->toArray();

        $advance->update([
            'status' => CashAdvance::STATUS_OPEN,
            'returned_amount' => 0,
            'reimbursed_amount' => 0,
            'settled_at' => null,
            'settled_by' => null,
        ]);

        FinanceAuditLog::record($advance, 'advance.reopened', $before, $advance->fresh()->toArray(), $actor->id);

        return $advance->fresh();
    }

    /**
     * Cancelling is for an advance issued by mistake, before any of it was
     * spent. Once there is spending against it, it has to be settled instead —
     * the money really did leave the school.
     */
    public function cancel(CashAdvance $advance, User $actor, ?string $reason = null): CashAdvance
    {
        $this->assertOpen($advance);

        abort_if(
            $advance->expenses()->exists(),
            422,
            'لا يمكن إلغاء عهدة صُرف منها. أقفلها بالتسوية بدل ذلك.',
        );

        $before = $advance->toArray();

        $advance->update([
            'status' => CashAdvance::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ]);

        FinanceAuditLog::record($advance, 'advance.cancelled', $before, $advance->fresh()->toArray(), $actor->id);

        return $advance->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CashAdvance $advance, bool $withExpenses = false): array
    {
        $advance->loadMissing(['holder:id,name', 'issuedBy:id,name', 'settledBy:id,name']);
        $spent = $advance->spent();

        $data = [
            'id' => $advance->id,
            'advance_number' => $advance->advance_number,
            'academic_year' => $advance->academic_year,
            'holder_id' => $advance->holder_id,
            'holder_name' => $advance->holder_name,
            'purpose' => $advance->purpose,
            'amount' => (float) $advance->amount,
            'method' => $advance->method,
            'reference' => $advance->reference,
            'issued_on' => $advance->issued_on?->toDateString(),
            'notes' => $advance->notes,
            'status' => $advance->status,
            'spent' => $spent,
            'outstanding' => $advance->outstanding(),
            'returned_amount' => (float) $advance->returned_amount,
            'reimbursed_amount' => (float) $advance->reimbursed_amount,
            'issued_by' => $advance->issuedBy?->name,
            'settled_at' => $advance->settled_at?->toDateTimeString(),
            'settled_by' => $advance->settledBy?->name,
            'cancel_reason' => $advance->cancel_reason,
            'expenses_count' => $advance->expenses()->count(),
        ];

        if ($withExpenses) {
            $data['expenses'] = $advance->expenses()
                ->with('recordedBy:id,name')
                ->orderBy('spent_on')
                ->orderBy('id')
                ->get()
                ->map(fn (CashAdvanceExpense $expense) => [
                    'id' => $expense->id,
                    'description' => $expense->description,
                    'amount' => (float) $expense->amount,
                    'spent_on' => $expense->spent_on?->toDateString(),
                    'reference' => $expense->reference,
                    'recorded_by' => $expense->recordedBy?->name,
                ])
                ->values();
        }

        return $data;
    }

    private function assertOpen(CashAdvance $advance): void
    {
        abort_unless($advance->isOpen(), 422, 'هذه العهدة مقفلة ولا يمكن تعديلها.');
    }

    /**
     * Serial per academic year, taken inside the transaction so two clerks
     * issuing at once cannot land on the same number.
     */
    private function nextNumber(string $academicYear): int
    {
        return (int) CashAdvance::where('academic_year', $academicYear)
            ->lockForUpdate()
            ->max('advance_number') + 1;
    }
}
