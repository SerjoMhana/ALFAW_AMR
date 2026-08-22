<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CreditLegendRow;
use App\Models\Enrollment;
use App\Models\GpaScaleBand;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

/**
 * A GPA belongs to one grade level in one term, for every grade G1 through G12.
 *
 * It is built only from the subjects of the class the student sat in, so each
 * grade carries its own figure and nothing is averaged or carried between them.
 * There is likewise no cumulative figure across terms.
 */
class GPAService
{
    private ?Collection $scaleBands = null;

    private ?Collection $legendRows = null;

    public function __construct(private readonly GradeCalculationService $grades) {}

    /**
     * @param  string|null  $academicYear  a past year to read, or null for the grade the student is in now
     */
    public function termGpa(StudentProfile $studentProfile, string $term, ?string $academicYear = null): ?array
    {
        // Asking for the student's standing right now: an archived student has none.
        if ($academicYear === null && $studentProfile->is_archived) {
            return null;
        }

        $enrollments = $this->enrollmentsFor($studentProfile, $academicYear);
        $section = $enrollments->first()?->courseSection;

        return [
            'grade_level' => $section?->class_name ?: $studentProfile->grade_level,
            'academic_year' => $section?->academic_year ?? $academicYear,
            ...$this->calculateFromEnrollments($studentProfile, $enrollments, $term),
        ];
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function enrollmentsFor(StudentProfile $studentProfile, ?string $academicYear): Collection
    {
        $query = Enrollment::query()
            ->with('courseSection.courses')
            ->where('student_profile_id', $studentProfile->id);

        if ($academicYear === null) {
            return $query
                ->where('status', 'active')
                ->whereHas('studentProfile', fn ($inner) => $inner->active())
                ->get();
        }

        // A past year: the class the student belonged to then, whether or not
        // they have since been promoted out of it.
        return $query
            ->members()
            ->whereHas('courseSection', fn ($inner) => $inner->where('academic_year', $academicYear))
            ->get();
    }

    private function calculateFromEnrollments(StudentProfile $studentProfile, $enrollments, string $term): array
    {
        $courses = [];
        $qualityPoints = 0.0;
        $credits = 0.0;

        foreach ($enrollments as $enrollment) {
            $section = $enrollment->courseSection;

            // Off the sheet, out of the average: an unexamined subject has
            // no grade to weigh and no credits to earn.
            foreach ($section->courses->where('has_exam', true) as $course) {
                $report = $this->grades->calculateStudentTermGrade($studentProfile, $course, $term, $section->academic_year);

                if ($report['total_applied_weight'] <= 0) {
                    continue;
                }

                $band = $this->bandForGrade((float) $report['final_grade']);
                $points = ($band?->points ?? 0.0) + ($course->is_ap ? 1.0 : 0.0);
                $creditHours = $this->creditsForCourse($course);
                $quality = $points * $creditHours;

                $qualityPoints += $quality;
                $credits += $creditHours;
                $courses[] = [
                    'course_id' => $course->id,
                    'course_code' => $course->code,
                    'course_name' => $course->name,
                    'is_ap' => (bool) $course->is_ap,
                    'periods_per_week' => $course->periods_per_week,
                    'credit_hours' => $creditHours,
                    'final_grade' => $report['final_grade'],
                    'letter_grade' => $band?->letter,
                    'gpa_points' => $points,
                    'quality_points' => $quality,
                ];
            }
        }

        // Reported at full precision: rounding a GPA changes the number a
        // transcript is judged on, so that decision belongs to whoever reads it.
        return [
            'term' => $term,
            'gpa' => $credits > 0 ? $qualityPoints / $credits : null,
            'total_credit_hours' => $credits,
            'total_credits_earned' => $credits,
            'courses' => $courses,
        ];
    }


    public function bandForGrade(float $grade): ?GpaScaleBand
    {
        $this->scaleBands ??= GpaScaleBand::query()
            ->orderByDesc('min_score')
            ->get();

        return $this->scaleBands->first(fn (GpaScaleBand $band) => $grade >= $band->min_score);
    }

    public function creditsForCourse(Course $course): float
    {
        return $this->creditsFor($course)['credits'];
    }

    /**
     * The credits a subject earns, and why. The legend is the school's rule, so
     * a subject that cannot be read from it is reported as such rather than
     * quietly falling back to a stored number nobody maintains.
     *
     * @return array{credits: float, source: string, reason: string|null}
     */
    public function creditsFor(Course $course): array
    {
        if (! $course->periods_per_week) {
            return [
                'credits' => (float) $course->credit_hours,
                'source' => 'course',
                'reason' => 'لم تُحدَّد حصص هذه المادة في الأسبوع، فلا يمكن قراءة نقاطها من جدول Legend of Credits Earned. عدّل المادة وأدخل عدد الحصص.',
            ];
        }

        $credits = $this->creditsForPeriods((int) $course->periods_per_week);

        if ($credits === null) {
            return [
                'credits' => (float) $course->credit_hours,
                'source' => 'course',
                'reason' => 'جدول Legend of Credits Earned فارغ. أدخله من إعداد نظام الدرجات ← إعداد نظام النقاط ليُحتسب رصيد كل مادة.',
            ];
        }

        return ['credits' => $credits, 'source' => 'legend', 'reason' => null];
    }

    public function creditsForPeriods(int $periodsPerWeek): ?float
    {
        $this->legendRows ??= CreditLegendRow::query()
            ->orderBy('classes_per_week')
            ->get();

        if ($this->legendRows->isEmpty()) {
            return null;
        }

        $exact = $this->legendRows->firstWhere('classes_per_week', $periodsPerWeek);
        if ($exact) {
            return (float) $exact->credits;
        }

        $below = $this->legendRows->filter(fn (CreditLegendRow $row) => $row->classes_per_week <= $periodsPerWeek)->last();

        return $below ? (float) $below->credits : (float) $this->legendRows->first()->credits;
    }
}
