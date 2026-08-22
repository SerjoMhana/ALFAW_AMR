<?php

namespace Tests\Feature\Finance;

use App\Models\ParentGuardian;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Finance\FeeAssignmentService;
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\TestCase;

class ParentBalanceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_a_guardian_sees_what_they_owe_for_their_child(): void
    {
        [$guardianUser, $child] = $this->family();

        $this->withUser($guardianUser)
            ->getJson("/api/parent/children/{$child->id}/balance")
            ->assertOk()
            ->assertJsonPath('data.student.id', $child->id)
            ->assertJsonPath('data.totals.net', 1000)
            ->assertJsonPath('data.totals.paid', 0)
            ->assertJsonPath('data.totals.outstanding', 1000)
            ->assertJsonCount(2, 'data.fees')
            ->assertJsonPath('data.fees.0.amount', 500)
            ->assertJsonPath('data.fees.0.due_date', '2099-09-01')
            ->assertJsonPath('data.fees.0.status', 'unpaid');
    }

    public function test_the_balance_reflects_a_payment_immediately(): void
    {
        [$guardianUser, $child] = $this->family();
        $cashier = $this->financeUser('cashier@example.com', ['finance.view', 'finance.payments.record']);

        app(PaymentService::class)->record($child, $this->year, 300, 'نقداً', $cashier);

        $this->withUser($guardianUser)
            ->getJson("/api/parent/children/{$child->id}/balance")
            ->assertOk()
            ->assertJsonPath('data.totals.paid', 300)
            ->assertJsonPath('data.totals.outstanding', 700)
            ->assertJsonCount(1, 'data.receipts')
            ->assertJsonPath('data.receipts.0.receipt_number', 1);
    }

    public function test_a_voided_receipt_disappears_from_the_guardians_view(): void
    {
        [$guardianUser, $child] = $this->family();
        $cashier = $this->financeUser('cashier@example.com', ['finance.view', 'finance.payments.record']);
        $supervisor = $this->financeUser('supervisor@example.com', ['finance.view', 'finance.payments.void']);

        app(PaymentService::class)->record($child, $this->year, 300, 'نقداً', $cashier);
        app(PaymentService::class)->void(Payment::firstOrFail(), $supervisor, 'خطأ');

        $this->withUser($guardianUser)
            ->getJson("/api/parent/children/{$child->id}/balance")
            ->assertOk()
            ->assertJsonPath('data.totals.paid', 0)
            ->assertJsonPath('data.totals.outstanding', 1000)
            ->assertJsonCount(0, 'data.receipts');
    }

    public function test_a_guardian_cannot_see_another_familys_balance(): void
    {
        [$guardianUser] = $this->family();
        [, $otherChild] = $this->family('other');

        $this->withUser($guardianUser)
            ->getJson("/api/parent/children/{$otherChild->id}/balance")
            ->assertForbidden();
    }

    public function test_a_non_parent_cannot_reach_the_balance_route(): void
    {
        [, $child] = $this->family();
        $staff = $this->financeUser('staff@example.com', ['finance.view']);

        $this->withUser($staff)
            ->getJson("/api/parent/children/{$child->id}/balance")
            ->assertForbidden();
    }

    /**
     * A guardian with one child who owes 1000 across two instalments.
     *
     * @return array{0: User, 1: StudentProfile}
     */
    private function family(string $prefix = 'fam'): array
    {
        $guardianUser = User::create([
            'name' => 'Guardian '.$prefix,
            'email' => $prefix.'.guardian@example.com',
            'username' => 'P-'.$prefix,
            'password' => Hash::make('password'),
            'user_type' => 'parent',
            'is_active' => true,
        ]);

        $child = $this->student('Child '.$prefix, 'S-'.$prefix);

        $guardian = ParentGuardian::create([
            'user_id' => $guardianUser->id,
            'parent_admission_no' => 'P-'.$prefix,
            'first_name' => 'Guardian',
            'full_name' => 'Guardian '.$prefix,
            'relation' => 'father',
            'mobile' => '0910000000',
        ]);
        $guardian->students()->attach($child->id, ['relation' => 'father', 'is_primary' => true]);

        // Templates and the plan are shared, so only create them once.
        if (\App\Models\FeeTemplate::count() === 0) {
            $this->feeTemplate('رسوم دراسية', 'رسوم دراسية', 500, 'G10', '2099-09-01');
            $this->feeTemplate('كتب', 'كتب', 500, 'G10', '2099-12-01');
        }

        app(FeeAssignmentService::class)->assign($child, $this->year);

        return [$guardianUser, $child->fresh()];
    }
}
