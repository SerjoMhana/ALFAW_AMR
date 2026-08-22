<?php

namespace Tests\Feature\Finance;

use App\Models\StudentFee;
use App\Services\Finance\FeeAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\TestCase;

class FeeAssignmentTest extends TestCase
{
    use CreatesFinanceFixtures;
    use RefreshDatabase;

    public function test_charges_are_raised_from_the_matching_templates(): void
    {
        $admin = $this->admin();
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'دراسية', 3000);
        $this->feeTemplate('كتب', 'كتب', 500);
        // A different grade's template must not be picked up.
        $this->feeTemplate('رسوم صف آخر', 'دراسية', 9999, 'G12');

        $this->withUser($admin)
            ->postJson("/api/finance/students/{$student->id}/assign-fees", ['academic_year' => $this->year])
            ->assertOk()
            ->assertJsonPath('data.summary.fees_created', 2)
            ->assertJsonPath('data.summary.net_total', 3500)
            ->assertJsonPath('data.statement.totals.net', 3500);

        $this->assertSame(2, StudentFee::where('student_profile_id', $student->id)->count());
    }

    /**
     * The point of a grade list: books cost the same across many grades, so one
     * template covers them all.
     */
    public function test_one_template_can_cover_several_grades(): void
    {
        $g9 = $this->student('Nine Student', 'S-9');
        $g9->update(['grade_level' => 'G9']);
        $g10 = $this->student('Ten Student', 'S-10');
        $g11 = $this->student('Eleven Student', 'S-11');
        $g11->update(['grade_level' => 'G11']);

        $this->feeTemplate('كتب', 'كتب', 500, ['G9', 'G10']);

        $service = app(FeeAssignmentService::class);

        $this->assertSame(500.0, $service->assign($g9->fresh(), $this->year)['net_total']);
        $this->assertSame(500.0, $service->assign($g10->fresh(), $this->year)['net_total']);
        // G11 was not in the list, so nothing is raised against them.
        $this->assertSame(0.0, $service->assign($g11->fresh(), $this->year)['net_total']);
    }

    public function test_a_template_with_no_grades_covers_the_whole_school(): void
    {
        $student = $this->student('Ali Hassan', 'S-1');
        $student->update(['grade_level' => 'G3']);
        $this->feeTemplate('رسوم تسجيل', 'تسجيل', 200, null);

        $this->assertSame(
            200.0,
            app(FeeAssignmentService::class)->assign($student->fresh(), $this->year)['net_total'],
        );
    }

    public function test_the_charge_keeps_its_amount_when_the_template_changes_later(): void
    {
        $admin = $this->admin();
        $student = $this->student('Ali Hassan', 'S-1');
        $template = $this->feeTemplate('رسوم دراسية', 'دراسية', 3000);

        app(FeeAssignmentService::class)->assign($student, $this->year);

        $template->update(['amount' => 5000]);

        $this->withUser($admin)
            ->getJson("/api/finance/students/{$student->id}/account?academic_year={$this->year}")
            ->assertOk()
            // Still the amount that was raised, not the new template price.
            ->assertJsonPath('data.totals.net', 3000);
    }

    public function test_the_due_date_is_carried_onto_the_charge(): void
    {
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'دراسية', 3000, 'G10', '2027-01-15');

        app(FeeAssignmentService::class)->assign($student, $this->year);

        $fee = StudentFee::where('student_profile_id', $student->id)->firstOrFail();
        $this->assertSame('2027-01-15', $fee->due_date->toDateString());
    }

    public function test_a_charge_past_its_due_date_is_marked_overdue(): void
    {
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'دراسية', 3000, 'G10', '2020-01-01');

        app(FeeAssignmentService::class)->assign($student, $this->year);

        $this->assertSame(
            StudentFee::STATUS_OVERDUE,
            StudentFee::where('student_profile_id', $student->id)->first()->status,
        );
    }

    public function test_running_assignment_twice_does_not_duplicate_charges(): void
    {
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'دراسية', 3000);

        $service = app(FeeAssignmentService::class);
        $service->assign($student, $this->year);
        $second = $service->assign($student, $this->year);

        $this->assertSame(0, $second['fees_created']);
        $this->assertSame(1, StudentFee::where('student_profile_id', $student->id)->count());
    }

    public function test_a_manual_one_off_charge_is_added_to_the_balance(): void
    {
        $admin = $this->admin();
        $student = $this->student('Ali Hassan', 'S-1');
        $this->feeTemplate('رسوم دراسية', 'دراسية', 1000);
        app(FeeAssignmentService::class)->assign($student, $this->year);

        $this->withUser($admin)
            ->postJson("/api/finance/students/{$student->id}/fees", [
                'name' => 'رسوم نشاط',
                'category' => 'أخرى',
                'amount' => 250,
                'academic_year' => $this->year,
                'due_date' => '2099-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.totals.net', 1250);
    }

    public function test_staff_without_permission_cannot_assign_fees(): void
    {
        $staff = $this->financeUser('staff@example.com', ['finance.view']);
        $student = $this->student('Ali Hassan', 'S-1');

        $this->withUser($staff)
            ->postJson("/api/finance/students/{$student->id}/assign-fees", ['academic_year' => $this->year])
            ->assertForbidden();
    }
}
