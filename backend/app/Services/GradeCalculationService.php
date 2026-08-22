<?php

namespace App\Services;

use App\Models\Course;
use App\Models\GradeTier;
use App\Models\GradingCategory;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use Illuminate\Support\Collection;

class GradeCalculationService
{
    public function getGradeTierByStudentGrade(StudentProfile $studentProfile): ?GradeTier
    {
        return $studentProfile->gradeTier();
    }

    public function getGradingStructureByTier(GradeTier $gradeTier): Collection
    {
        return GradingCategory::query()
            ->where('grade_tier_id', $gradeTier->id)
            ->where('is_active', true)
            ->with(['items' => function ($query) {
                $query->where('is_active', true)->orderBy('display_order');
            }])
            ->orderBy('display_order')
            ->get();
    }

    /**
     * @param  Collection<int, \App\Models\GradingItem>  $items  entry-able items (is_total_field = false)
     * @param  Collection<int, StudentScore>  $scoresByItemId  keyed by grading_item_id
     */
    public function calculateCategoryAverage(Collection $items, Collection $scoresByItemId): ?float
    {
        $scoredItems = $items->filter(function ($item) use ($scoresByItemId) {
            $score = $scoresByItemId->get($item->id);

            return $score !== null && $score->score_obtained !== null;
        });

        if ($scoredItems->isEmpty()) {
            return null;
        }

        $totalObtained = $scoredItems->sum(fn ($item) => (float) $scoresByItemId->get($item->id)->score_obtained);
        $totalMax = $scoredItems->sum(fn ($item) => (float) $item->max_score);

        if ($totalMax <= 0) {
            return null;
        }

        return ($totalObtained / $totalMax) * 100;
    }

    public function calculateWeightedScore(float $categoryAveragePercent, float $weightPercentage): float
    {
        return ($categoryAveragePercent * $weightPercentage) / 100;
    }

    /**
     * @param  array<int, float>  $weightedScores
     */
    public function calculateFinalGrade(array $weightedScores): float
    {
        return array_sum($weightedScores);
    }

    public function calculateStudentTermGrade(
        StudentProfile $studentProfile,
        Course $course,
        string $term,
        string $academicYear,
    ): array {
        // No archived check here: this is a pure calculation, and a graduated
        // student's past grades must stay readable. Whether a caller may look at
        // a given student is decided at the entry points that expose this.
        $gradeTier = $this->getGradeTierByStudentGrade($studentProfile) ?? $course->gradeTier();

        if (! $gradeTier) {
            return [
                'student_profile_id' => $studentProfile->id,
                'course_id' => $course->id,
                'term' => $term,
                'academic_year' => $academicYear,
                'grade_tier' => null,
                'category_breakdown' => [],
                'final_grade' => 0.0,
                'missing_scores' => [],
                'total_applied_weight' => 0.0,
            ];
        }

        $structure = $this->getGradingStructureByTier($gradeTier);

        $scoresByItemId = StudentScore::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('course_id', $course->id)
            ->where('term', $term)
            ->where('academic_year', $academicYear)
            ->get()
            ->keyBy('grading_item_id');

        $breakdown = [];
        $missingScores = [];
        $weightedScores = [];
        $totalAppliedWeight = 0.0;

        foreach ($structure as $category) {
            $entryItems = $category->items->where('is_total_field', false)->values();
            $averagePercent = $this->calculateCategoryAverage($entryItems, $scoresByItemId);

            foreach ($entryItems as $item) {
                $score = $scoresByItemId->get($item->id);
                if ($score === null || $score->score_obtained === null) {
                    $missingScores[] = "{$category->name}: {$item->name}";
                }
            }

            $items = $entryItems->map(function ($item) use ($scoresByItemId) {
                $score = $scoresByItemId->get($item->id);

                return [
                    'grading_item_id' => $item->id,
                    'name' => $item->name,
                    'max_score' => (float) $item->max_score,
                    'score_obtained' => $score?->score_obtained !== null ? (float) $score->score_obtained : null,
                ];
            })->values()->all();

            if ($averagePercent === null) {
                $breakdown[] = [
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                    'weight' => (float) $category->weight_percentage,
                    'average_percent' => null,
                    'weighted_points' => 0.0,
                    'items' => $items,
                ];

                continue;
            }

            $weightedPoints = $this->calculateWeightedScore($averagePercent, (float) $category->weight_percentage);
            $weightedScores[] = $weightedPoints;
            $totalAppliedWeight += (float) $category->weight_percentage;

            $breakdown[] = [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'weight' => (float) $category->weight_percentage,
                'average_percent' => round($averagePercent, 2),
                'weighted_points' => round($weightedPoints, 2),
                'items' => $items,
            ];
        }

        return [
            'student_profile_id' => $studentProfile->id,
            'course_id' => $course->id,
            'term' => $term,
            'academic_year' => $academicYear,
            'grade_tier' => $gradeTier->name,
            'category_breakdown' => $breakdown,
            'final_grade' => round($this->calculateFinalGrade($weightedScores), 2),
            'missing_scores' => $missingScores,
            'total_applied_weight' => round($totalAppliedWeight, 2),
        ];
    }

    public function calculateCourseSummary(Course $course, string $term, string $academicYear): array
    {
        $course->loadMissing('classSection.enrollments.studentProfile.user');
        $classSection = $course->classSection;

        if (! $classSection) {
            return [];
        }

        return $classSection->enrollments
            ->whereIn('status', ['active', 'completed'])
            ->filter(fn ($enrollment) => $enrollment->studentProfile !== null)
            ->map(function ($enrollment) use ($course, $term, $academicYear) {
                return [
                    'student_profile' => $enrollment->studentProfile,
                    'report' => $this->calculateStudentTermGrade($enrollment->studentProfile, $course, $term, $academicYear),
                ];
            })
            ->values()
            ->all();
    }
}
