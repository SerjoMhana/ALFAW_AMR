<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CourseSection;
use App\Models\Payment;
use App\Models\StudentFee;
use App\Models\StudentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceReportController extends Controller
{
    /**
     * Who still owes money, optionally narrowed to one class.
     */
    public function outstanding(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'course_section_id' => ['nullable', 'integer', 'exists:course_sections,id'],
        ]);

        $rows = StudentFee::query()
            ->with(['studentProfile.user:id,name', 'discounts'])
            ->where('academic_year', $validated['academic_year'])
            ->when(
                $validated['course_section_id'] ?? null,
                fn ($query, $sectionId) => $query->whereHas(
                    'studentProfile',
                    fn ($inner) => $inner->where('section_id', $sectionId),
                ),
            )
            ->get()
            ->groupBy('student_profile_id')
            ->map(function ($group) {
                $student = $group->first()->studentProfile;

                return [
                    'student_profile_id' => $student?->id,
                    'name' => $student?->full_name ?: $student?->user?->name,
                    'admission_no' => $student?->admission_no ?: $student?->student_number,
                    'grade_level' => $student?->grade_level,
                    'total' => round((float) $group->sum(fn (StudentFee $fee) => $fee->netAmount()), 2),
                    'paid' => round((float) $group->sum(fn (StudentFee $fee) => (float) $fee->paid_amount), 2),
                    'outstanding' => round((float) $group->sum(fn (StudentFee $fee) => $fee->outstanding()), 2),
                ];
            })
            ->filter(fn (array $row) => $row['outstanding'] > 0)
            ->sortByDesc('outstanding')
            ->values();

        return response()->json([
            'data' => [
                'academic_year' => $validated['academic_year'],
                'totals' => [
                    'students' => $rows->count(),
                    'outstanding' => round((float) $rows->sum('outstanding'), 2),
                ],
                'rows' => $rows,
            ],
        ]);
    }

    /**
     * Overdue debt bucketed by how long it has been late.
     */
    public function aged(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
        ]);

        $today = now()->startOfDay();
        $buckets = ['current' => 0.0, 'week' => 0.0, 'month' => 0.0, 'quarter' => 0.0, 'older' => 0.0];
        $students = [];

        StudentFee::query()
            ->with(['studentProfile.user:id,name', 'discounts'])
            ->where('academic_year', $validated['academic_year'])
            ->get()
            ->each(function (StudentFee $fee) use (&$buckets, &$students, $today): void {
                $outstanding = $fee->outstanding();

                if ($outstanding <= 0) {
                    return;
                }

                $daysLate = $fee->due_date && $fee->due_date->lt($today)
                    ? $fee->due_date->diffInDays($today)
                    : 0;

                $bucket = match (true) {
                    $daysLate <= 0 => 'current',
                    $daysLate <= 7 => 'week',
                    $daysLate <= 30 => 'month',
                    $daysLate <= 90 => 'quarter',
                    default => 'older',
                };

                $buckets[$bucket] = round($buckets[$bucket] + $outstanding, 2);

                if ($daysLate > 0) {
                    $student = $fee->studentProfile;
                    $key = $student?->id;
                    $students[$key] ??= [
                        'student_profile_id' => $key,
                        'name' => $student?->full_name ?: $student?->user?->name,
                        'admission_no' => $student?->admission_no ?: $student?->student_number,
                        'overdue' => 0.0,
                        'days_late' => 0,
                    ];
                    $students[$key]['overdue'] = round($students[$key]['overdue'] + $outstanding, 2);
                    $students[$key]['days_late'] = max($students[$key]['days_late'], $daysLate);
                }
            });

        return response()->json([
            'data' => [
                'academic_year' => $validated['academic_year'],
                'buckets' => $buckets,
                'students' => collect($students)->sortByDesc('overdue')->values(),
            ],
        ]);
    }

    /**
     * What came in over a period, broken down by day, week, month or year.
     */
    public function collections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'period' => ['nullable', Rule::in(['day', 'week', 'month', 'year'])],
        ]);

        $period = $validated['period'] ?? 'day';

        $payments = Payment::query()
            ->with(['studentProfile.user:id,name', 'receivedBy:id,name'])
            ->whereBetween('paid_on', [$validated['from'], $validated['to']])
            ->orderBy('paid_on')
            ->orderBy('receipt_number')
            ->get();

        // Voided receipts stay visible in the list but never count toward money in.
        $valid = $payments->reject(fn (Payment $payment) => $payment->isVoided());

        $series = $valid
            ->groupBy(fn (Payment $payment) => $this->bucketFor($payment, $period))
            // Cast the key: PHP turns a numeric group key such as a year into an
            // int, which would make the label's type vary by period.
            ->map(fn ($group, $label) => [
                'label' => (string) $label,
                'collected' => round((float) $group->sum(fn (Payment $row) => (float) $row->amount), 2),
                'receipts' => $group->count(),
            ])
            ->sortKeys()
            ->values();

        return response()->json([
            'data' => [
                'period' => $period,
                'from' => $validated['from'],
                'to' => $validated['to'],
                'totals' => [
                    'collected' => round((float) $valid->sum(fn (Payment $row) => (float) $row->amount), 2),
                    'receipts' => $valid->count(),
                    'voided' => $payments->count() - $valid->count(),
                ],
                'series' => $series,
                'by_method' => $valid
                    ->groupBy('method')
                    ->map(fn ($group) => round((float) $group->sum(fn ($row) => (float) $row->amount), 2)),
                'by_cashier' => $valid
                    ->groupBy(fn (Payment $payment) => $payment->receivedBy?->name ?? '—')
                    ->map(fn ($group) => round((float) $group->sum(fn ($row) => (float) $row->amount), 2)),
                'rows' => $payments->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'receipt_number' => $payment->receipt_number,
                    'paid_on' => $payment->paid_on?->toDateString(),
                    'student' => $payment->studentProfile?->full_name
                        ?: $payment->studentProfile?->user?->name,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    'received_by' => $payment->receivedBy?->name,
                    'is_voided' => $payment->isVoided(),
                ])->values(),
            ],
        ]);
    }

    /**
     * A sortable label for the chosen granularity — ISO week for weeks so the
     * ordering stays correct across a year boundary.
     */
    private function bucketFor(Payment $payment, string $period): string
    {
        $date = $payment->paid_on;

        return match ($period) {
            'week' => $date->isoFormat('GGGG-[W]WW'),
            'month' => $date->format('Y-m'),
            'year' => $date->format('Y'),
            default => $date->toDateString(),
        };
    }

    /**
     * Students to pick from in the finance screens.
     */
    public function students(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));

        return response()->json([
            'data' => StudentProfile::query()
                ->with(['user:id,name', 'section:id,class_name'])
                ->active()
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($inner) use ($search): void {
                        $inner->where('full_name', 'like', "%{$search}%")
                            ->orWhere('admission_no', 'like', "%{$search}%")
                            ->orWhere('student_number', 'like', "%{$search}%")
                            ->orWhere('national_id', 'like', "%{$search}%");
                    });
                })
                ->orderBy('full_name')
                ->limit(25)
                ->get()
                ->map(fn (StudentProfile $student) => [
                    'id' => $student->id,
                    'name' => $student->full_name ?: $student->user?->name,
                    'admission_no' => $student->admission_no ?: $student->student_number,
                    'grade_level' => $student->grade_level,
                    'class_name' => $student->section?->class_name,
                    'academic_year' => $student->academic_year,
                ])
                ->values(),
        ]);
    }

    /**
     * Classes, used both for filtering reports and for picking grade levels
     * when defining a fee template.
     */
    public function classes(): JsonResponse
    {
        return response()->json([
            'data' => CourseSection::inActiveYear()
                ->orderByDesc('academic_year')
                ->orderBy('class_name')
                ->get(['id', 'class_name', 'section_code', 'academic_year']),
        ]);
    }
}
