<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One class's register for one month: the students down the side, the days of
 * the month across the top.
 *
 * This is the shape the school already works in on paper, which is the point —
 * the same grid is shown on screen, printed blank for the supervisors to fill
 * in by hand, and printed again filled once the marks have been entered.
 */
class AttendanceMonthService
{
    /** The letters printed in the grid, short enough to fit a day column. */
    public const MARKS = [
        'present' => '✓',
        'absent' => 'غ',
        'late' => 'ت',
        'excused' => 'م',
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(CourseSection $section, int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->endOfMonth();

        $students = $this->studentsOf($section);
        $records = $this->recordsFor($section, $start, $end);

        $days = collect(range(1, $start->daysInMonth))->map(function (int $day) use ($start) {
            $date = $start->addDays($day - 1);

            return [
                'day' => $day,
                'date' => $date->toDateString(),
                'weekday' => $date->dayOfWeek,
                // Friday and Saturday off, as the school week runs here.
                'is_weekend' => in_array($date->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true),
            ];
        });

        $rows = $students->map(function (StudentProfile $student, int $index) use ($days, $records) {
            $marks = [];
            $totals = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];

            foreach ($days as $day) {
                $record = $records[$student->id][$day['date']] ?? null;
                $marks[$day['date']] = $record ? [
                    'status' => $record->status,
                    'mark' => self::MARKS[$record->status] ?? '?',
                    'notes' => $record->notes,
                ] : null;

                if ($record && isset($totals[$record->status])) {
                    $totals[$record->status]++;
                }
            }

            return [
                'no' => $index + 1,
                'student_profile_id' => $student->id,
                'name' => $student->full_name ?: $student->user?->name,
                'admission_no' => $student->admission_no ?: $student->student_number,
                'marks' => $marks,
                'totals' => $totals,
                'recorded_days' => array_sum($totals),
            ];
        })->values();

        return [
            'section' => [
                'id' => $section->id,
                'name' => $section->class_name ?: $section->section_code,
                'section_code' => $section->section_code,
                'academic_year' => $section->academic_year,
            ],
            'year' => $year,
            'month' => $month,
            'month_label' => $start->translatedFormat('F Y'),
            'days' => $days->values(),
            'students' => $rows,
            'legend' => self::MARKS,
            // How many school days actually have marks, so the office can see at
            // a glance what is still outstanding.
            'recorded_dates' => collect($records)->flatMap(fn (array $byDate) => array_keys($byDate))
                ->unique()->sort()->values(),
        ];
    }

    /**
     * Records keyed by student and then by date, so building a row is lookups
     * rather than a query per cell.
     *
     * @return array<int, array<string, AttendanceRecord>>
     */
    private function recordsFor(CourseSection $section, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return AttendanceRecord::query()
            ->where('course_section_id', $section->id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('student_profile_id')
            ->map(fn (Collection $rows) => $rows
                ->keyBy(fn (AttendanceRecord $record) => $record->attendance_date->toDateString())
                ->all())
            ->all();
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    private function studentsOf(CourseSection $section): Collection
    {
        return Enrollment::query()
            ->with('studentProfile.user')
            ->where('course_section_id', $section->id)
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->get()
            ->map(fn (Enrollment $enrollment) => $enrollment->studentProfile)
            ->filter()
            ->unique('id')
            ->sortBy(fn (StudentProfile $student) => $student->full_name ?: $student->user?->name)
            ->values();
    }
}
