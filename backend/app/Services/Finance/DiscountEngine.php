<?php

namespace App\Services\Finance;

use App\Models\DiscountRule;
use App\Models\FinanceAuditLog;
use App\Models\StudentDiscount;
use App\Models\StudentFee;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class DiscountEngine
{
    /**
     * Applies every active rule that covers this charge.
     *
     * Rule-driven discounts express school policy rather than a person's
     * judgement, so they are approved as they are created. Only a discount
     * entered by hand waits for a second pair of eyes.
     *
     * @return Collection<int, StudentDiscount>
     */
    public function applyAutomaticDiscounts(StudentFee $fee): Collection
    {
        $student = $fee->studentProfile;

        if (! $student) {
            return collect();
        }

        return DiscountRule::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (DiscountRule $rule) => $rule->coversCategory($fee->category))
            ->map(fn (DiscountRule $rule) => $this->applyRule($rule, $fee, $student))
            ->filter()
            ->values();
    }

    private function applyRule(DiscountRule $rule, StudentFee $fee, StudentProfile $student): ?StudentDiscount
    {
        $percentage = match ($rule->type) {
            DiscountRule::TYPE_SIBLING => $rule->siblingPercentageFor($this->siblingOrdinal($student)),
            DiscountRule::TYPE_PERCENTAGE => (float) $rule->value,
            default => null,
        };

        $amount = $rule->type === DiscountRule::TYPE_FIXED
            ? (float) $rule->value
            : ($percentage === null ? null : round((float) $fee->amount * $percentage / 100, 2));

        // A discount can never exceed the charge it sits against.
        $amount = $amount === null ? null : round(min($amount, (float) $fee->amount), 2);

        if ($amount === null || $amount <= 0) {
            return null;
        }

        $discount = StudentDiscount::create([
            'student_fee_id' => $fee->id,
            'discount_rule_id' => $rule->id,
            'reason' => $rule->name,
            'percentage' => $percentage,
            'amount' => $amount,
            'status' => StudentDiscount::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        FinanceAuditLog::record($discount, 'discount.auto_applied', null, [
            'rule' => $rule->name,
            'amount' => $amount,
            'student_fee_id' => $fee->id,
        ]);

        return $discount;
    }

    /**
     * Where this student falls among their siblings, oldest enrolment first.
     *
     * Siblings are resolved through the guardian rather than the sibling pivot,
     * because that is what the admissions flow actually populates.
     */
    public function siblingOrdinal(StudentProfile $student): int
    {
        $guardian = $student->parents()->first();

        if (! $guardian) {
            return 1;
        }

        $siblings = $guardian->students()
            ->where('student_profiles.status', 'active')
            ->whereNull('student_profiles.archived_at')
            ->orderByRaw('student_profiles.admission_date is null')
            ->orderBy('student_profiles.admission_date')
            ->orderBy('student_profiles.id')
            ->get();

        $position = $siblings->search(fn (StudentProfile $row) => $row->id === $student->id);

        return $position === false ? 1 : $position + 1;
    }
}
