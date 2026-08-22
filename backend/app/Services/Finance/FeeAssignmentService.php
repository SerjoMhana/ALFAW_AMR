<?php

namespace App\Services\Finance;

use App\Models\FeeTemplate;
use App\Models\FinanceAuditLog;
use App\Models\StudentFee;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FeeAssignmentService
{
    public function __construct(private readonly DiscountEngine $discounts) {}

    /**
     * Mandatory templates that cover a student's grade for a year, plus any
     * that apply school-wide.
     *
     * @return Collection<int, FeeTemplate>
     */
    public function templatesFor(StudentProfile $student, string $academicYear): Collection
    {
        return FeeTemplate::query()
            ->with('category')
            ->where('is_active', true)
            ->where('is_mandatory', true)
            ->where('academic_year', $academicYear)
            ->get()
            ->filter(fn (FeeTemplate $template) => $template->coversGrade(
                $student->grade_level,
                $student->course,
            ))
            ->values();
    }

    /**
     * Raises the year's charges against a student and applies the automatic
     * discounts. Charges already raised are left alone so re-running is safe.
     *
     * @param  array<int, int>  $extraTemplateIds  optional services the student subscribed to
     */
    public function assign(StudentProfile $student, string $academicYear, array $extraTemplateIds = []): array
    {
        $templates = $this->templatesFor($student, $academicYear);

        if ($extraTemplateIds !== []) {
            $templates = $templates->merge(
                FeeTemplate::with('category')
                    ->whereIn('id', $extraTemplateIds)
                    ->where('is_active', true)
                    ->get()
            )->unique('id');
        }

        $alreadyRaised = StudentFee::query()
            ->where('student_profile_id', $student->id)
            ->where('academic_year', $academicYear)
            ->pluck('fee_template_id')
            ->filter()
            ->all();

        return DB::transaction(function () use ($student, $academicYear, $templates, $alreadyRaised) {
            $created = collect();

            foreach ($templates as $template) {
                if (in_array($template->id, $alreadyRaised, true)) {
                    continue;
                }

                $fee = StudentFee::create([
                    'student_profile_id' => $student->id,
                    'fee_template_id' => $template->id,
                    'name' => $template->name,
                    // Snapshots: editing the template later must not rewrite this.
                    'category' => $template->category?->name ?? 'أخرى',
                    'academic_year' => $academicYear,
                    'amount' => $template->amount,
                    'due_date' => $template->due_date,
                ]);

                FinanceAuditLog::record($fee, 'fee.raised', null, [
                    'name' => $fee->name,
                    'amount' => (float) $fee->amount,
                    'due_date' => $fee->due_date?->toDateString(),
                ]);

                $this->discounts->applyAutomaticDiscounts($fee);
                $fee->load('discounts')->refreshStatus();
                $created->push($fee);
            }

            return [
                'fees_created' => $created->count(),
                'net_total' => $this->netTotal($student, $academicYear),
            ];
        });
    }

    /**
     * Net of every charge for the year after approved discounts.
     */
    public function netTotal(StudentProfile $student, string $academicYear): float
    {
        return round(
            $this->feesFor($student, $academicYear)->sum(fn (StudentFee $fee) => $fee->netAmount()),
            2,
        );
    }

    /**
     * @return Collection<int, StudentFee>
     */
    public function feesFor(StudentProfile $student, string $academicYear): Collection
    {
        return StudentFee::query()
            ->with('discounts')
            ->where('student_profile_id', $student->id)
            ->where('academic_year', $academicYear)
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Re-evaluates every charge's status, which is what turns an unpaid charge
     * overdue once its due date passes or a discount changes the balance.
     */
    public function refreshStatuses(StudentProfile $student, string $academicYear): void
    {
        $this->feesFor($student, $academicYear)->each(fn (StudentFee $fee) => $fee->refreshStatus());
    }
}
