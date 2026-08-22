<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceAuditLog;
use App\Models\Payment;
use App\Models\StudentFee;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Finance\FeeAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_receipt_numbers_run_in_sequence_without_gaps(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();

        foreach ([100, 200, 300] as $amount) {
            $this->withUser($cashier)
                ->postJson("/api/finance/students/{$student->id}/payments", [
                    'academic_year' => $this->year,
                    'amount' => $amount,
                    'method' => 'نقداً',
                ])
                ->assertCreated();
        }

        $this->assertSame(
            [1, 2, 3],
            Payment::orderBy('receipt_number')->pluck('receipt_number')->all(),
        );
    }

    public function test_automatic_allocation_settles_the_earliest_due_charge_first(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();

        $this->withUser($cashier)
            ->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => 1200,
                'method' => 'نقداً',
            ])
            ->assertCreated();

        $fees = $this->feesOf($student);

        // Tuition falls due first, so it clears before anything spills over.
        $this->assertSame(1000.0, (float) $fees['رسوم دراسية']->paid_amount);
        $this->assertSame(200.0, (float) $fees['نقل']->paid_amount);
        $this->assertSame(0.0, (float) $fees['كتب']->paid_amount);
    }

    public function test_partial_payment_marks_the_charge_partially_paid(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();

        $this->withUser($cashier)
            ->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => 400,
                'method' => 'نقداً',
            ])
            ->assertCreated()
            ->assertJsonPath('data.statement.totals.paid', 400)
            ->assertJsonPath('data.statement.totals.outstanding', 1400);

        $this->assertSame(
            StudentFee::STATUS_PARTIALLY_PAID,
            $this->feesOf($student)['رسوم دراسية']->status,
        );
    }

    /**
     * The case the school asked for by name: paying transport only.
     */
    public function test_manual_allocation_can_pay_one_service_only(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();
        $transport = $this->feesOf($student)['نقل'];

        $this->withUser($cashier)
            ->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => 600,
                'method' => 'حوالة مصرفية',
                'allocations' => [
                    ['student_fee_id' => $transport->id, 'amount' => 600],
                ],
            ])
            ->assertCreated();

        $fees = $this->feesOf($student);

        $this->assertSame(600.0, (float) $fees['نقل']->paid_amount);
        $this->assertSame(StudentFee::STATUS_PAID, $fees['نقل']->status);
        // Tuition is untouched even though it was due earlier.
        $this->assertSame(0.0, (float) $fees['رسوم دراسية']->paid_amount);
    }

    public function test_manual_allocation_must_add_up_to_the_amount_paid(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();
        $tuition = $this->feesOf($student)['رسوم دراسية'];

        $this->withUser($cashier)
            ->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => 500,
                'method' => 'نقداً',
                'allocations' => [
                    ['student_fee_id' => $tuition->id, 'amount' => 300],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }

    public function test_allocating_more_than_a_charge_owes_is_refused(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();
        $books = $this->feesOf($student)['كتب'];

        $this->withUser($cashier)
            ->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => 900,
                'method' => 'نقداً',
                'allocations' => [
                    ['student_fee_id' => $books->id, 'amount' => 900],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_paying_more_than_is_owed_is_refused(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();

        $this->withUser($cashier)
            ->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => 5000,
                'method' => 'نقداً',
            ])
            ->assertStatus(422);

        $this->assertSame(0, Payment::count());
    }

    public function test_an_unknown_payment_method_is_refused(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();

        $this->withUser($cashier)
            ->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => 100,
                'method' => 'طريقة غير معرّفة',
            ])
            ->assertStatus(422);
    }

    /**
     * The core immutability guarantee: a receipt has no edit or delete route.
     */
    public function test_a_receipt_cannot_be_edited_or_deleted(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();

        $this->withUser($cashier)->postJson("/api/finance/students/{$student->id}/payments", [
            'academic_year' => $this->year,
            'amount' => 500,
            'method' => 'نقداً',
        ])->assertCreated();

        $payment = Payment::firstOrFail();

        foreach (['putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->withUser($cashier)
                ->{$verb}("/api/finance/payments/{$payment->id}", ['amount' => 1])
                ->assertStatus(405);
        }

        $this->assertSame(500.0, (float) $payment->fresh()->amount);
    }

    public function test_voiding_reverses_the_allocation_but_keeps_the_receipt(): void
    {
        $cashier = $this->cashier();
        $supervisor = $this->supervisor();
        $student = $this->studentOwing();

        $this->withUser($cashier)->postJson("/api/finance/students/{$student->id}/payments", [
            'academic_year' => $this->year,
            'amount' => 400,
            'method' => 'نقداً',
        ])->assertCreated();

        $payment = Payment::firstOrFail();

        $this->withUser($supervisor)
            ->postJson("/api/finance/payments/{$payment->id}/void", ['reason' => 'خطأ في الإدخال'])
            ->assertOk()
            ->assertJsonPath('data.statement.totals.paid', 0)
            ->assertJsonPath('data.statement.totals.outstanding', 1800);

        $payment->refresh();

        // The original row, its amount and its serial all survive.
        $this->assertNotNull($payment->voided_at);
        $this->assertSame('خطأ في الإدخال', $payment->void_reason);
        $this->assertSame(400.0, (float) $payment->amount);
        $this->assertSame(1, $payment->receipt_number);
        $this->assertSame(1, Payment::count());
        $this->assertSame(0.0, (float) $this->feesOf($student)['رسوم دراسية']->paid_amount);
    }

    public function test_a_receipt_cannot_be_voided_twice(): void
    {
        $cashier = $this->cashier();
        $supervisor = $this->supervisor();
        $student = $this->studentOwing();

        $this->withUser($cashier)->postJson("/api/finance/students/{$student->id}/payments", [
            'academic_year' => $this->year,
            'amount' => 400,
            'method' => 'نقداً',
        ])->assertCreated();

        $payment = Payment::firstOrFail();

        $this->withUser($supervisor)
            ->postJson("/api/finance/payments/{$payment->id}/void", ['reason' => 'مرة أولى'])
            ->assertOk();

        $this->withUser($supervisor)
            ->postJson("/api/finance/payments/{$payment->id}/void", ['reason' => 'مرة ثانية'])
            ->assertStatus(422);
    }

    public function test_recording_a_payment_does_not_grant_voiding_one(): void
    {
        $cashier = $this->cashier();
        $student = $this->studentOwing();

        $this->withUser($cashier)->postJson("/api/finance/students/{$student->id}/payments", [
            'academic_year' => $this->year,
            'amount' => 400,
            'method' => 'نقداً',
        ])->assertCreated();

        $payment = Payment::firstOrFail();

        $this->withUser($cashier)
            ->postJson("/api/finance/payments/{$payment->id}/void", ['reason' => 'محاولة'])
            ->assertForbidden();

        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_every_payment_and_void_is_written_to_the_audit_trail(): void
    {
        $cashier = $this->cashier();
        $supervisor = $this->supervisor();
        $student = $this->studentOwing();

        $this->withUser($cashier)->postJson("/api/finance/students/{$student->id}/payments", [
            'academic_year' => $this->year,
            'amount' => 400,
            'method' => 'نقداً',
        ])->assertCreated();

        $payment = Payment::firstOrFail();

        $this->withUser($supervisor)
            ->postJson("/api/finance/payments/{$payment->id}/void", ['reason' => 'خطأ'])
            ->assertOk();

        $trail = FinanceAuditLog::where('entity_type', 'Payment')->pluck('action')->all();

        $this->assertContains('payment.recorded', $trail);
        $this->assertContains('payment.voided', $trail);
        $this->assertSame(
            $supervisor->id,
            FinanceAuditLog::where('action', 'payment.voided')->first()->user_id,
        );
    }

    public function test_a_voided_receipt_is_excluded_from_collections(): void
    {
        $cashier = $this->cashier();
        $supervisor = $this->supervisor();
        $student = $this->studentOwing();

        foreach ([300, 200] as $amount) {
            $this->withUser($cashier)->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => $amount,
                'method' => 'نقداً',
            ])->assertCreated();
        }

        $first = Payment::orderBy('receipt_number')->first();

        $this->withUser($supervisor)
            ->postJson("/api/finance/payments/{$first->id}/void", ['reason' => 'خطأ'])
            ->assertOk();

        $this->withUser($supervisor)
            ->getJson('/api/finance/reports/collections?from=2000-01-01&to=2100-01-01')
            ->assertOk()
            ->assertJsonPath('data.totals.collected', 200)
            ->assertJsonPath('data.totals.receipts', 1)
            ->assertJsonPath('data.totals.voided', 1);
    }

    /**
     * Collections can be read day by day, or rolled up by week, month or year.
     */
    public function test_collections_can_be_grouped_by_period(): void
    {
        $cashier = $this->cashier();
        $supervisor = $this->supervisor();
        $student = $this->studentOwing();

        foreach ([['2026-09-01', 100], ['2026-09-02', 200], ['2026-10-05', 300]] as [$date, $amount]) {
            $this->withUser($cashier)->postJson("/api/finance/students/{$student->id}/payments", [
                'academic_year' => $this->year,
                'amount' => $amount,
                'method' => 'نقداً',
                'paid_on' => $date,
            ])->assertCreated();
        }

        $url = '/api/finance/reports/collections?from=2026-01-01&to=2027-01-01&period=';

        // Three separate days.
        $this->withUser($supervisor)->getJson($url.'day')
            ->assertOk()
            ->assertJsonCount(3, 'data.series');

        // Two months: September holds 300, October 300.
        $this->withUser($supervisor)->getJson($url.'month')
            ->assertOk()
            ->assertJsonCount(2, 'data.series')
            ->assertJsonPath('data.series.0.label', '2026-09')
            ->assertJsonPath('data.series.0.collected', 300)
            ->assertJsonPath('data.series.1.label', '2026-10')
            ->assertJsonPath('data.series.1.collected', 300);

        // One year holding everything.
        $this->withUser($supervisor)->getJson($url.'year')
            ->assertOk()
            ->assertJsonCount(1, 'data.series')
            ->assertJsonPath('data.series.0.label', '2026')
            ->assertJsonPath('data.series.0.collected', 600);
    }

    private function cashier(): User
    {
        return $this->financeUser('cashier@example.com', ['finance.view', 'finance.payments.record']);
    }

    private function supervisor(): User
    {
        return $this->financeUser('supervisor@example.com', [
            'finance.view',
            'finance.payments.void',
            'finance.reports.view',
        ]);
    }

    /**
     * @return array<string, StudentFee>
     */
    private function feesOf(StudentProfile $student): array
    {
        return StudentFee::with('discounts')
            ->where('student_profile_id', $student->id)
            ->get()
            ->keyBy('category')
            ->all();
    }

    /**
     * A student owing 1,800 across three charges with staggered due dates.
     */
    private function studentOwing(): StudentProfile
    {
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'رسوم دراسية', 1000, 'G10', '2099-09-01');
        $this->feeTemplate('نقل', 'نقل', 600, 'G10', '2099-10-01');
        $this->feeTemplate('كتب', 'كتب', 200, 'G10', '2099-11-01');

        app(FeeAssignmentService::class)->assign($student, $this->year);

        return $student->fresh();
    }
}
