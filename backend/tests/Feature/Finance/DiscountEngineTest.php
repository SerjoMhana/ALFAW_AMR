<?php

namespace Tests\Feature\Finance;

use App\Models\StudentDiscount;
use App\Models\StudentFee;
use App\Services\Finance\DiscountEngine;
use App\Services\Finance\FeeAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\TestCase;

class DiscountEngineTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_sibling_tiers_apply_by_enrolment_order(): void
    {
        $first = $this->student('Ali Hassan', 'S-1', '2024-09-01');
        $second = $this->student('Sara Hassan', 'S-2', '2025-09-01');
        $third = $this->student('Omar Hassan', 'S-3', '2026-09-01');
        $this->guardianFor([$first, $second, $third]);

        $engine = app(DiscountEngine::class);

        $this->assertSame(1, $engine->siblingOrdinal($first));
        $this->assertSame(2, $engine->siblingOrdinal($second));
        $this->assertSame(3, $engine->siblingOrdinal($third));
    }

    public function test_second_child_gets_ten_percent_and_third_fifteen(): void
    {
        $first = $this->student('Ali Hassan', 'S-1', '2024-09-01');
        $second = $this->student('Sara Hassan', 'S-2', '2025-09-01');
        $third = $this->student('Omar Hassan', 'S-3', '2026-09-01');
        $this->guardianFor([$first, $second, $third]);

        $this->feeTemplate('رسوم دراسية', 'tuition', 1000);
        $this->siblingRule(['2' => 10, '3' => 15]);

        $service = app(FeeAssignmentService::class);

        // The first child earns nothing, so their balance is the full charge.
        $this->assertSame(1000.0, $service->assign($first, $this->year)['net_total']);
        $this->assertSame(900.0, $service->assign($second, $this->year)['net_total']);
        $this->assertSame(850.0, $service->assign($third, $this->year)['net_total']);
    }

    public function test_the_discount_touches_tuition_only(): void
    {
        $first = $this->student('Ali Hassan', 'S-1', '2024-09-01');
        $second = $this->student('Sara Hassan', 'S-2', '2025-09-01');
        $this->guardianFor([$first, $second]);

        $this->feeTemplate('رسوم دراسية', 'tuition', 1000);
        $this->feeTemplate('كتب', 'books', 400);
        $this->feeTemplate('نقل', 'transport', 600);
        $this->siblingRule(['2' => 10], ['tuition']);

        app(FeeAssignmentService::class)->assign($second, $this->year);

        $fees = StudentFee::with('discounts')
            ->where('student_profile_id', $second->id)
            ->get()
            ->keyBy('category');

        $this->assertSame(100.0, $fees['tuition']->approvedDiscountTotal());
        $this->assertSame(0.0, $fees['books']->approvedDiscountTotal());
        $this->assertSame(0.0, $fees['transport']->approvedDiscountTotal());
        // 2000 gross - 100 on tuition only.
        $this->assertSame(1900.0, app(FeeAssignmentService::class)->netTotal($second, $this->year));
    }

    public function test_a_student_with_no_siblings_gets_nothing(): void
    {
        $only = $this->student('Ali Hassan', 'S-1', '2024-09-01');
        $this->guardianFor([$only]);
        $this->feeTemplate('رسوم دراسية', 'tuition', 1000);
        $this->siblingRule(['2' => 10, '3' => 15]);

        $this->assertSame(1000.0, app(FeeAssignmentService::class)->assign($only, $this->year)['net_total']);
    }

    public function test_a_manual_discount_waits_for_approval_before_it_reduces_the_balance(): void
    {
        $requester = $this->financeUser('cashier@example.com', ['finance.view', 'finance.discounts.request']);
        $approver = $this->financeUser('manager@example.com', ['finance.view', 'finance.discounts.approve']);
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'tuition', 1000);
        app(FeeAssignmentService::class)->assign($student, $this->year);
        $fee = StudentFee::where('student_profile_id', $student->id)->firstOrFail();

        $this->withUser($requester)
            ->postJson("/api/finance/fees/{$fee->id}/discount-requests", [
                'reason' => 'منحة تفوق',
                'percentage' => 20,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', StudentDiscount::STATUS_PENDING);

        // Still owed in full while the request is pending.
        $this->assertSame(1000.0, app(FeeAssignmentService::class)->netTotal($student, $this->year));

        $discount = StudentDiscount::latest('id')->firstOrFail();

        $this->withUser($approver)
            ->postJson("/api/finance/discount-requests/{$discount->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', StudentDiscount::STATUS_APPROVED);

        $this->assertSame(800.0, app(FeeAssignmentService::class)->netTotal($student, $this->year));
    }

    public function test_requesting_a_discount_does_not_grant_approving_one(): void
    {
        $requester = $this->financeUser('cashier@example.com', ['finance.view', 'finance.discounts.request']);
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'tuition', 1000);
        app(FeeAssignmentService::class)->assign($student, $this->year);
        $fee = StudentFee::where('student_profile_id', $student->id)->firstOrFail();

        $this->withUser($requester)
            ->postJson("/api/finance/fees/{$fee->id}/discount-requests", [
                'reason' => 'منحة',
                'percentage' => 20,
            ])
            ->assertCreated();

        $discount = StudentDiscount::latest('id')->firstOrFail();

        $this->withUser($requester)
            ->postJson("/api/finance/discount-requests/{$discount->id}/approve")
            ->assertForbidden();

        $this->assertSame(StudentDiscount::STATUS_PENDING, $discount->fresh()->status);
    }

    /**
     * Even someone who holds both permissions must not sign off their own request.
     */
    public function test_an_approver_cannot_approve_their_own_request(): void
    {
        $both = $this->financeUser('both@example.com', [
            'finance.view',
            'finance.discounts.request',
            'finance.discounts.approve',
        ]);
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'tuition', 1000);
        app(FeeAssignmentService::class)->assign($student, $this->year);
        $fee = StudentFee::where('student_profile_id', $student->id)->firstOrFail();

        $this->withUser($both)
            ->postJson("/api/finance/fees/{$fee->id}/discount-requests", [
                'reason' => 'منحة',
                'amount' => 100,
            ])
            ->assertCreated();

        $discount = StudentDiscount::latest('id')->firstOrFail();

        $this->withUser($both)
            ->postJson("/api/finance/discount-requests/{$discount->id}/approve")
            ->assertForbidden();
    }

    public function test_a_rejected_discount_never_reduces_the_balance(): void
    {
        $requester = $this->financeUser('cashier@example.com', ['finance.view', 'finance.discounts.request']);
        $approver = $this->financeUser('manager@example.com', ['finance.view', 'finance.discounts.approve']);
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'tuition', 1000);
        app(FeeAssignmentService::class)->assign($student, $this->year);
        $fee = StudentFee::where('student_profile_id', $student->id)->firstOrFail();

        $this->withUser($requester)->postJson("/api/finance/fees/{$fee->id}/discount-requests", [
            'reason' => 'منحة',
            'percentage' => 50,
        ])->assertCreated();

        $discount = StudentDiscount::latest('id')->firstOrFail();

        $this->withUser($approver)
            ->postJson("/api/finance/discount-requests/{$discount->id}/reject")
            ->assertOk();

        $this->assertSame(1000.0, app(FeeAssignmentService::class)->netTotal($student, $this->year));
    }

    public function test_a_discount_cannot_exceed_the_charge(): void
    {
        $requester = $this->financeUser('cashier@example.com', ['finance.view', 'finance.discounts.request']);
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'tuition', 1000);
        app(FeeAssignmentService::class)->assign($student, $this->year);
        $fee = StudentFee::where('student_profile_id', $student->id)->firstOrFail();

        $this->withUser($requester)
            ->postJson("/api/finance/fees/{$fee->id}/discount-requests", [
                'reason' => 'خصم مبالغ فيه',
                'amount' => 1500,
            ])
            ->assertStatus(422);
    }
}
