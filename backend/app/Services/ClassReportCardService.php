<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\StudentProfile;
use Illuminate\Support\Collection;

class ClassReportCardService
{
    public function __construct(private readonly GradeCalculationService $grades) {}

    public const SEMESTER_TERMS = [
        1 => ['Quarter 1', 'Quarter 2'],
        2 => ['Quarter 3', 'Quarter 4'],
    ];

    /**
     * Every subject of the class must have all grading items entered for
     * each required quarter before any report can be generated.
     *
     * @param  Collection<int, StudentProfile>  $students
     * @param  array<int, string>  $terms
     * @return array<int, array{student: string, subject: string, term: string, missing_items: array<int, string>}>
     */
    public function findMissingScores(CourseSection $classSection, Collection $students, array $terms): array
    {
        $classSection->loadMissing('courses');
        $missing = [];

        foreach ($students as $studentProfile) {
            // An unexamined subject cannot be missing marks it never had.
            foreach ($classSection->courses->where('has_exam', true) as $course) {
                foreach ($terms as $term) {
                    $report = $this->grades->calculateStudentTermGrade(
                        $studentProfile,
                        $course,
                        $term,
                        $classSection->academic_year,
                    );

                    if (! empty($report['missing_scores'])) {
                        $missing[] = [
                            'student' => $this->studentName($studentProfile),
                            'student_number' => $studentProfile->admission_no ?: $studentProfile->student_number,
                            'subject' => $course->name,
                            'term' => $term,
                            'missing_items' => $report['missing_scores'],
                        ];
                    }
                }
            }
        }

        return $missing;
    }

    public function quarterReport(CourseSection $classSection, StudentProfile $studentProfile, string $term): array
    {
        $classSection->loadMissing('courses');

        $subjects = $classSection->courses->where('has_exam', true)->map(function (Course $course) use ($studentProfile, $classSection, $term) {
            $report = $this->grades->calculateStudentTermGrade($studentProfile, $course, $term, $classSection->academic_year);
            $grade = round($report['final_grade'], 1);

            return [
                'name' => $course->name,
                'grade' => $grade,
                'letter' => self::letterGrade($grade),
                'credit_hours' => (float) $course->credit_hours,
            ];
        })->values()->all();

        $summary = $this->summary($subjects, 'grade');

        return [
            'student_profile' => $studentProfile,
            'class_section' => $classSection,
            'term' => $term,
            'subjects' => $subjects,
            'gpa' => $this->creditWeightedGpa($subjects),
            'summary' => $summary,
            'absence_total' => $this->absenceTotal($classSection, $studentProfile),
        ];
    }

    public function semesterReport(CourseSection $classSection, StudentProfile $studentProfile, int $semester): array
    {
        $classSection->loadMissing('courses');

        $subjects = $classSection->courses->where('has_exam', true)->map(function (Course $course) use ($studentProfile, $classSection, $semester) {
            $quarterGrade = fn (string $term): float => round((float) $this->grades->calculateStudentTermGrade(
                $studentProfile,
                $course,
                $term,
                $classSection->academic_year,
            )['final_grade'], 1);

            $terms = self::SEMESTER_TERMS[$semester];
            $firstQuarter = $quarterGrade($terms[0]);
            $secondQuarter = $quarterGrade($terms[1]);
            $final = round(($firstQuarter + $secondQuarter) / 2, 1);

            return [
                'name' => $course->name,
                'credit_hours' => (float) $course->credit_hours,
                'columns' => [$firstQuarter, $secondQuarter],
                'final' => $final,
                'letter' => self::letterGrade($final),
                'credit_earned' => $final >= 60 ? (float) $course->credit_hours : 0.0,
            ];
        })->values()->all();

        return [
            'student_profile' => $studentProfile,
            'class_section' => $classSection,
            'semester' => $semester,
            'subjects' => $subjects,
            'gpa' => $this->creditWeightedGpa($subjects),
            'summary' => $this->summary($subjects, 'final'),
            'absence_total' => $this->absenceTotal($classSection, $studentProfile),
        ];
    }

    /**
     * The annual report is deliberately limited to this academic year:
     * Semester 1 = average of Q1/Q2, Semester 2 = average of Q3/Q4,
     * and the final subject grade is the average of those two semesters.
     */
    public function finalReport(CourseSection $classSection, StudentProfile $studentProfile): array
    {
        $semester1 = $this->semesterReport($classSection, $studentProfile, 1);
        $semester2 = $this->semesterReport($classSection, $studentProfile, 2);

        $subjects = collect($semester1['subjects'])->map(function (array $subject, int $index) use ($semester2) {
            $second = $semester2['subjects'][$index];
            $final = round(($subject['final'] + $second['final']) / 2, 1);

            return [
                'name' => $subject['name'],
                'credit_hours' => $subject['credit_hours'],
                'columns' => [$subject['final'], $second['final']],
                'final' => $final,
                'letter' => self::letterGrade($final),
                'credit_earned' => $final >= 60 ? $subject['credit_hours'] : 0.0,
            ];
        })->all();

        return [
            'student_profile' => $studentProfile,
            'class_section' => $classSection,
            'semester' => null,
            'is_final' => true,
            'subjects' => $subjects,
            'gpa' => $this->creditWeightedGpa($subjects),
            'summary' => $this->summary($subjects, 'final'),
            'absence_total' => $semester1['absence_total'],
        ];
    }

    /**
     * Totals used by the reference-style report-card summary. Every examined
     * subject is marked out of 100, while status follows the school's 60%
     * passing threshold.
     *
     * @param  array<int, array<string, mixed>>  $subjects
     * @return array{maximum: float, obtained: float, percentage: float, passed: bool}
     */
    private function summary(array $subjects, string $gradeKey): array
    {
        $maximum = count($subjects) * 100.0;
        $obtained = round(array_sum(array_map(
            fn (array $subject): float => (float) ($subject[$gradeKey] ?? 0),
            $subjects,
        )), 1);
        $percentage = $maximum > 0 ? round(($obtained / $maximum) * 100, 1) : 0.0;

        return [
            'maximum' => $maximum,
            'obtained' => $obtained,
            'percentage' => $percentage,
            'passed' => $percentage >= 60,
        ];
    }

    private function absenceTotal(CourseSection $classSection, StudentProfile $studentProfile): int
    {
        return AttendanceRecord::query()
            ->where('course_section_id', $classSection->id)
            ->where('student_profile_id', $studentProfile->id)
            ->whereIn('status', ['absent', 'excused'])
            ->count();
    }

    /**
     * Standard US letter scale — matches both the sample quarter PDF and
     * the GPA legend on the semester Word templates.
     */
    public static function letterGrade(float $grade): string
    {
        return match (true) {
            $grade >= 97 => 'A+',
            $grade >= 94 => 'A',
            $grade >= 90 => 'A-',
            $grade >= 87 => 'B+',
            $grade >= 84 => 'B',
            $grade >= 80 => 'B-',
            $grade >= 77 => 'C+',
            $grade >= 74 => 'C',
            $grade >= 70 => 'C-',
            $grade >= 67 => 'D+',
            $grade >= 64 => 'D',
            $grade >= 60 => 'D-',
            default => 'F',
        };
    }

    public static function gpaPoints(string $letter): float
    {
        return match ($letter) {
            'A+' => 4.0,
            'A' => 4.0,
            'A-' => 3.7,
            'B+' => 3.3,
            'B' => 3.0,
            'B-' => 2.7,
            'C+' => 2.3,
            'C' => 2.0,
            'C-' => 1.7,
            'D+' => 1.3,
            'D' => 1.0,
            'D-' => 0.7,
            default => 0.0,
        };
    }

    private function creditWeightedGpa(array $subjects): ?float
    {
        $credits = array_sum(array_column($subjects, 'credit_hours'));

        if ($credits <= 0) {
            return null;
        }

        $qualityPoints = array_sum(array_map(
            fn (array $subject) => self::gpaPoints($subject['letter']) * $subject['credit_hours'],
            $subjects,
        ));

        return round($qualityPoints / $credits, 2);
    }

    private function studentName(StudentProfile $studentProfile): string
    {
        return $studentProfile->full_name ?: ($studentProfile->user?->name ?? $studentProfile->student_number);
    }
}
