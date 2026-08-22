<?php

namespace Tests\Unit;

use App\Models\StudentFee;
use PHPUnit\Framework\TestCase;

/**
 * The money arithmetic on a single charge, held to the cent. These are the
 * numbers a receipt is printed from, so a drifting fraction is a real error.
 */
class MoneyRulesTest extends TestCase
{
    public function test_the_outstanding_amount_is_the_net_less_what_was_paid(): void
    {
        $fee = $this->fee(amount: 1000, paid: 250);

        $this->assertSame(750.0, $fee->outstanding());
    }

    public function test_a_charge_cannot_go_below_zero_when_overpaid(): void
    {
        $fee = $this->fee(amount: 1000, paid: 1200);

        $this->assertSame(0.0, $fee->outstanding());
    }

    public function test_thirds_of_a_currency_unit_do_not_drift(): void
    {
        $fee = $this->fee(amount: 100, paid: 33.33);

        $this->assertSame(66.67, $fee->outstanding());
    }

    public function test_repeated_part_payments_settle_exactly(): void
    {
        $paid = 0.0;

        foreach (range(1, 3) as $ignored) {
            $paid += 33.33;
        }

        $fee = $this->fee(amount: 99.99, paid: $paid);

        $this->assertSame(0.0, $fee->outstanding());
    }

    public function test_a_charge_with_nothing_paid_is_outstanding_in_full(): void
    {
        $this->assertSame(450.0, $this->fee(amount: 450, paid: 0)->outstanding());
    }

    private function fee(float $amount, float $paid): StudentFee
    {
        $fee = new StudentFee;
        $fee->amount = $amount;
        $fee->paid_amount = $paid;
        // No discounts attached, so netAmount() is the amount itself.
        $fee->setRelation('discounts', collect());

        return $fee;
    }
}
