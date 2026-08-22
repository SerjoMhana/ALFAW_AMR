<?php

namespace App\Services;

use App\Models\Course;
use App\Models\GradeSubmission;
use App\Models\StudentScore;
use Illuminate\Support\Collection;

/**
 * Answers "who has handed in their grades and who still owes us work"
 * for a given academic year and set of terms.
 */
class GradeSubmissionReportService
{
    public const STATUS_SUBMITTED = 'submitted';

    /** Scores were entered but never submitted, so the sheet is still open. */
    public const STATUS_PARTIAL = 'partial';

    /** Nothing entered at all. */
    public const STATUS_NOT_STARTED = 'not_started';

    /**
     * Resolve the quarters a report covers.
     *
     * @return array<int, string>
     */
    public static function resolveTerms(string $type, ?string $term, ?int $semester): array
    {
        if ($type === 'semester') {
            return ClassReportCardService::SEMESTER_TERMS[$semester] ?? [];
        }

        return $term ? [$term] : [];
    }

    /**
     * @param  array<int, string>  $terms
     */
    public function build(string $academicYear, array $terms, ?int $classSectionId = null): array
    {
        $courses = Course::query()
            ->with(['classSection:id,class_name,section_code,academic_year', 'teacher:id,name'])
            ->whereHas('classSection', fn ($query) => $query->where('academic_year', $academicYear))
            ->when($classSectionId, fn ($query, $id) => $query->where('class_section_id', $id))
            ->get();

        $courseIds = $courses->pluck('id');
        $submissions = $this->submissionsByCourseTerm($courseIds, $terms, $academicYear);
        $started = $this->startedCourseTerms($courseIds, $terms, $academicYear);

        $rows = $courses->flatMap(fn (Course $course) => collect($terms)->map(
            fn (string $term) => $this->row($course, $term, $submissions, $started),
        ));

        return [
            'academic_year' => $academicYear,
            'terms' => array_values($terms),
            'summary' => $this->summarise($rows),
            'teachers' => $this->groupByTeacher($rows->where('teacher_id', '!=', null)),
            'unassigned' => $rows->where('teacher_id', null)->values()->all(),
        ];
    }

    private function row(Course $course, string $term, Collection $submissions, Collection $started): array
    {
        $key = $course->id.'|'.$term;
        $submission = $submissions->get($key);

        $status = match (true) {
            (bool) $submission?->isSubmitted() => self::STATUS_SUBMITTED,
            $started->has($key) => self::STATUS_PARTIAL,
            default => self::STATUS_NOT_STARTED,
        };

        return [
            'course_id' => $course->id,
            'subject_code' => $course->code,
            'subject_name' => $course->name,
            'class_name' => $course->classSection?->class_name ?: $course->classSection?->section_code,
            'teacher_id' => $course->teacher_id,
            'teacher_name' => $course->teacher?->name,
            'term' => $term,
            'status' => $status,
            'submitted_at' => $submission?->submitted_at?->toDateTimeString(),
        ];
    }

    private function summarise(Collection $rows): array
    {
        $total = $rows->count();
        $submitted = $rows->where('status', self::STATUS_SUBMITTED)->count();

        return [
            'total' => $total,
            'submitted' => $submitted,
            'partial' => $rows->where('status', self::STATUS_PARTIAL)->count(),
            'not_started' => $rows->where('status', self::STATUS_NOT_STARTED)->count(),
            'pending' => $total - $submitted,
            'unassigned' => $rows->where('teacher_id', null)->count(),
            'completion_percent' => $total > 0 ? round(($submitted / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Teachers who still owe work come first, so the list opens on what needs chasing.
     */
    private function groupByTeacher(Collection $rows): array
    {
        return $rows
            ->groupBy('teacher_id')
            ->map(function (Collection $teacherRows) {
                $pending = $teacherRows->where('status', '!=', self::STATUS_SUBMITTED)->values();
                $submitted = $teacherRows->where('status', self::STATUS_SUBMITTED)->values();

                return [
                    'teacher_id' => $teacherRows->first()['teacher_id'],
                    'teacher_name' => $teacherRows->first()['teacher_name'],
                    'total' => $teacherRows->count(),
                    'submitted_count' => $submitted->count(),
                    'pending_count' => $pending->count(),
                    'is_complete' => $pending->isEmpty(),
                    'completion_percent' => round(($submitted->count() / max($teacherRows->count(), 1)) * 100, 1),
                    'pending' => $pending->all(),
                    'submitted' => $submitted->all(),
                ];
            })
            ->sortBy([
                fn (array $teacher) => $teacher['is_complete'] ? 1 : 0,
                fn (array $teacher) => -$teacher['pending_count'],
                fn (array $teacher) => $teacher['teacher_name'],
            ])
            ->values()
            ->all();
    }

    private function submissionsByCourseTerm(Collection $courseIds, array $terms, string $academicYear): Collection
    {
        return GradeSubmission::query()
            ->whereIn('course_id', $courseIds)
            ->whereIn('term', $terms)
            ->where('academic_year', $academicYear)
            ->get()
            ->keyBy(fn (GradeSubmission $submission) => $submission->course_id.'|'.$submission->term);
    }

    /**
     * Course/term pairs that have at least one real score entered.
     */
    private function startedCourseTerms(Collection $courseIds, array $terms, string $academicYear): Collection
    {
        return StudentScore::query()
            ->whereIn('course_id', $courseIds)
            ->whereIn('term', $terms)
            ->where('academic_year', $academicYear)
            ->whereNotNull('score_obtained')
            ->distinct()
            ->get(['course_id', 'term'])
            ->mapWithKeys(fn (StudentScore $score) => [$score->course_id.'|'.$score->term => true]);
    }
}
