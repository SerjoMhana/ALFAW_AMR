<?php

namespace Tests\Feature;

use App\Models\CashAdvance;
use App\Models\FinanceAuditLog;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cash advances — العهد.
 *
 * The arithmetic is the point: an advance closes on what the receipts say, and
 * the difference goes one way or the other. Nobody types the closing figure.
 */
class CashAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin@school.test');
    }

    public function test_an_advance_is_issued_and_numbered(): void
    {
        $body = $this->withUser($this->admin)
            ->postJson('/api/finance/advances', $this->payload())
            ->assertCreated()
            ->json('data');

        $this->assertSame(1, $body['advance_number']);
        $this->assertSame('open', $body['status']);
        $this->assertEquals(1000.0, $body['amount']);
        // Nothing spent, so all of it is still unaccounted for.
        $this->assertEquals(1000.0, $body['outstanding']);
    }

    public function test_numbers_run_in_sequence_within_a_year_and_restart_in_the_next(): void
    {
        $this->withUser($this->admin)->postJson('/api/finance/advances', $this->payload())->assertCreated();
        $this->withUser($this->admin)->postJson('/api/finance/advances', $this->payload())->assertCreated();

        $this->assertSame([1, 2], CashAdvance::where('academic_year', '2026-2027')
            ->orderBy('id')->pluck('advance_number')->all());

        $this->withUser($this->admin)
            ->postJson('/api/finance/advances', $this->payload(['academic_year' => '2027-2028']))
            ->assertCreated()
            ->assertJsonPath('data.advance_number', 1);
    }

    public function test_an_advance_of_nothing_is_refused(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/finance/advances', $this->payload(['amount' => 0]))
            ->assertStatus(422);
    }

    public function test_spending_reduces_what_is_unaccounted_for(): void
    {
        $advance = $this->issue(1000);

        $body = $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/expenses", [
                'description' => 'أحبار طابعة',
                'amount' => 250.50,
                'spent_on' => '2026-09-05',
                'reference' => 'INV-77',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertEquals(250.5, $body['spent']);
        $this->assertEquals(749.5, $body['outstanding']);
        $this->assertCount(1, $body['expenses']);
    }

    public function test_a_line_is_removed_while_the_advance_is_open(): void
    {
        $advance = $this->issue(1000);
        $expenseId = $this->spend($advance, 400)['expenses'][0]['id'];

        $body = $this->withUser($this->admin)
            ->deleteJson("/api/finance/advance-expenses/{$expenseId}")
            ->assertOk()
            ->json('data');

        $this->assertEquals(0.0, $body['spent']);
        $this->assertEquals(1000.0, $body['outstanding']);
    }

    // ---- closing ---------------------------------------------------------------

    public function test_closing_an_underspent_advance_records_what_comes_back(): void
    {
        $advance = $this->issue(1000);
        $this->spend($advance, 850);

        $body = $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/settle")
            ->assertOk()
            ->json();

        $this->assertSame('settled', $body['data']['status']);
        $this->assertEquals(150.0, $body['data']['returned_amount']);
        $this->assertEquals(0.0, $body['data']['reimbursed_amount']);
        // Fully accounted for once the difference is recorded.
        $this->assertEquals(0.0, $body['data']['outstanding']);
        $this->assertStringContainsString('150', $body['message']);
    }

    public function test_closing_an_overspent_advance_records_what_the_school_owes(): void
    {
        $advance = $this->issue(1000);
        $this->spend($advance, 1120);

        $body = $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/settle")
            ->assertOk()
            ->json('data');

        $this->assertEquals(0.0, $body['returned_amount']);
        $this->assertEquals(120.0, $body['reimbursed_amount']);
        $this->assertEquals(0.0, $body['outstanding']);
    }

    public function test_closing_an_exact_advance_leaves_no_difference(): void
    {
        $advance = $this->issue(500);
        $this->spend($advance, 500);

        $body = $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/settle")
            ->assertOk()
            ->json('data');

        $this->assertEquals(0.0, $body['returned_amount']);
        $this->assertEquals(0.0, $body['reimbursed_amount']);
    }

    public function test_a_closed_advance_takes_no_more_spending_and_cannot_be_closed_twice(): void
    {
        $advance = $this->issue(1000);
        $this->spend($advance, 400);
        $this->withUser($this->admin)->postJson("/api/finance/advances/{$advance->id}/settle")->assertOk();

        $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/expenses", ['description' => 'late', 'amount' => 50])
            ->assertStatus(422);

        $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/settle")
            ->assertStatus(422);

        $this->withUser($this->admin)
            ->putJson("/api/finance/advances/{$advance->id}", ['purpose' => 'شيء آخر'])
            ->assertStatus(422);
    }

    /**
     * The receipt that turns up a week late.
     */
    public function test_reopening_clears_the_settlement_and_lets_a_late_receipt_in(): void
    {
        $advance = $this->issue(1000);
        $this->spend($advance, 850);
        $this->withUser($this->admin)->postJson("/api/finance/advances/{$advance->id}/settle")->assertOk();

        $body = $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/reopen")
            ->assertOk()
            ->json('data');

        $this->assertSame('open', $body['status']);
        $this->assertEquals(0.0, $body['returned_amount']);
        $this->assertEquals(150.0, $body['outstanding']);

        $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/expenses", ['description' => 'فاتورة متأخرة', 'amount' => 150])
            ->assertCreated();

        $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/settle")
            ->assertOk()
            ->assertJsonPath('data.returned_amount', 0);
    }

    public function test_an_open_advance_cannot_be_reopened(): void
    {
        $advance = $this->issue(1000);

        $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/reopen")
            ->assertStatus(422);
    }

    // ---- cancelling ------------------------------------------------------------

    public function test_an_untouched_advance_is_cancelled(): void
    {
        $advance = $this->issue(1000);

        $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/cancel", ['reason' => 'صُرفت بالخطأ'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'صُرفت بالخطأ');
    }

    /**
     * Money that has already been spent really did leave the school; pretending
     * the advance never happened would lose it.
     */
    public function test_an_advance_with_spending_against_it_cannot_be_cancelled(): void
    {
        $advance = $this->issue(1000);
        $this->spend($advance, 100);

        $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/cancel")
            ->assertStatus(422);

        $this->assertSame('open', $advance->fresh()->status);
    }

    // ---- who may do what --------------------------------------------------------

    public function test_the_one_who_spends_is_not_the_one_who_signs_it_off(): void
    {
        $advance = $this->issue(1000);
        $clerk = $this->staffWith('finance.advances.manage', 'clerk@school.test');

        // The clerk records what was bought...
        $this->withUser($clerk)
            ->postJson("/api/finance/advances/{$advance->id}/expenses", ['description' => 'قرطاسية', 'amount' => 300])
            ->assertCreated();

        // ...but closing the file is somebody else's signature.
        $this->withUser($clerk)
            ->postJson("/api/finance/advances/{$advance->id}/settle")
            ->assertForbidden();

        $auditor = $this->staffWith('finance.advances.settle', 'auditor@school.test');
        $auditor->permissions()->attach(
            Permission::firstOrCreate(['name' => 'finance.view'], ['label' => 'View finance'])->id,
        );

        $this->withUser($auditor)
            ->postJson("/api/finance/advances/{$advance->id}/settle")
            ->assertOk();
    }

    public function test_someone_who_only_signs_off_cannot_issue_or_spend(): void
    {
        $advance = $this->issue(1000);
        $auditor = $this->staffWith('finance.advances.settle', 'auditor@school.test');

        $this->withUser($auditor)->postJson('/api/finance/advances', $this->payload())->assertForbidden();

        $this->withUser($auditor)
            ->postJson("/api/finance/advances/{$advance->id}/expenses", ['description' => 'x', 'amount' => 10])
            ->assertForbidden();
    }

    public function test_someone_outside_finance_sees_nothing(): void
    {
        $this->issue(1000);
        $teacher = $this->user('teacher', 'teacher@school.test');

        $this->withUser($teacher)->getJson('/api/finance/advances')->assertForbidden();
    }

    public function test_the_list_totals_what_is_still_out(): void
    {
        $first = $this->issue(1000);
        $this->spend($first, 400);
        $second = $this->issue(500);
        $this->withUser($this->admin)->postJson("/api/finance/advances/{$second->id}/settle")->assertOk();

        $body = $this->withUser($this->admin)->getJson('/api/finance/advances?status=open')->assertOk()->json();

        $this->assertSame(1, $body['totals']['open_count']);
        $this->assertEquals(1000.0, $body['totals']['open_amount']);
        // 1000 issued less 400 spent is still in the holder's pocket.
        $this->assertEquals(600.0, $body['totals']['open_outstanding']);
    }

    public function test_every_step_leaves_a_trail(): void
    {
        $advance = $this->issue(1000);
        $this->spend($advance, 400);
        $this->withUser($this->admin)->postJson("/api/finance/advances/{$advance->id}/settle")->assertOk();

        $actions = FinanceAuditLog::pluck('action')->all();

        $this->assertContains('advance.issued', $actions);
        $this->assertContains('advance.expense.added', $actions);
        $this->assertContains('advance.settled', $actions);
    }

    // ---- fixtures ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'academic_year' => '2026-2027',
            'holder_name' => 'محمد الأمين',
            'purpose' => 'مستلزمات مكتبية',
            'amount' => 1000,
            'method' => 'نقداً',
            'issued_on' => '2026-09-01',
        ], $overrides);
    }

    private function issue(float $amount): CashAdvance
    {
        $id = $this->withUser($this->admin)
            ->postJson('/api/finance/advances', $this->payload(['amount' => $amount]))
            ->assertCreated()
            ->json('data.id');

        return CashAdvance::findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function spend(CashAdvance $advance, float $amount): array
    {
        return $this->withUser($this->admin)
            ->postJson("/api/finance/advances/{$advance->id}/expenses", [
                'description' => 'شراء',
                'amount' => $amount,
            ])
            ->assertCreated()
            ->json('data');
    }

    private function staffWith(string $permission, string $email): User
    {
        $user = $this->user('staff', $email);
        $user->permissions()->attach(
            Permission::firstOrCreate(['name' => $permission], ['label' => $permission])->id,
        );

        return $user;
    }

    private function user(string $type, string $email): User
    {
        return User::create([
            'name' => ucfirst($type),
            'email' => $email,
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => $type,
            'is_active' => true,
        ]);
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
