<?php

namespace App\Services;

use App\Models\GradeTier;
use App\Models\GradingCategory;
use App\Models\GradingItem;
use Illuminate\Support\Facades\DB;

/**
 * The school's own grading sheet, transcribed from
 * "VIS American Dept. Grading System Grades 1-12".
 *
 * Each item's max score is the mark the teacher enters out of; the category
 * weight is that block's share of the 100-point quarter total. Both come
 * straight from the workbook's "% of the Final Grade" and "The Exam is out of"
 * rows, so a scheme built from a preset reproduces the paper sheet exactly.
 */
class GradingSchemePresets
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'vis-g1-4',
                'name' => 'G1-4',
                'label' => 'القسم الأمريكي — الصفوف 1 إلى 4',
                'min_grade' => 1,
                'max_grade' => 4,
                'categories' => [
                    ['name' => 'Classwork', 'weight_percentage' => 20, 'items' => [['Classwork', 20]]],
                    ['name' => 'Quizzes', 'weight_percentage' => 20, 'items' => [['Quiz 1', 5], ['Quiz 2', 5], ['Quiz 3', 5], ['Quiz 4', 5]]],
                    ['name' => 'Tests', 'weight_percentage' => 20, 'items' => [['Test 1', 10], ['Test 2', 10]]],
                    ['name' => 'Assignments', 'weight_percentage' => 20, 'items' => [['Assignment 1', 5], ['Assignment 2', 5], ['Assignment 3', 5], ['Assignment 4', 5]]],
                    ['name' => 'Quarter Final', 'weight_percentage' => 20, 'items' => [['Quarter Final', 20]]],
                ],
            ],
            [
                'key' => 'vis-g5-8',
                'name' => 'G5-8',
                'label' => 'القسم الأمريكي — الصفوف 5 إلى 8',
                'min_grade' => 5,
                'max_grade' => 8,
                'categories' => [
                    ['name' => 'Classwork', 'weight_percentage' => 10, 'items' => [['Classwork', 10]]],
                    ['name' => 'Quizzes', 'weight_percentage' => 10, 'items' => [['Quiz 1', 2.5], ['Quiz 2', 2.5], ['Quiz 3', 2.5], ['Quiz 4', 2.5]]],
                    ['name' => 'Tests', 'weight_percentage' => 20, 'items' => [['Test 1', 10], ['Test 2', 10]]],
                    ['name' => 'Assignments', 'weight_percentage' => 20, 'items' => [['Assignment 1', 5], ['Assignment 2', 5], ['Assignment 3', 5], ['Assignment 4', 5]]],
                    ['name' => 'Project / Research Paper', 'weight_percentage' => 10, 'items' => [['Project / Research Paper', 10]]],
                    ['name' => 'Quarter Final', 'weight_percentage' => 30, 'items' => [['Quarter Final', 30]]],
                ],
            ],
            [
                'key' => 'vis-g9-12',
                'name' => 'G9-12',
                'label' => 'القسم الأمريكي — الصفوف 9 إلى 12',
                'min_grade' => 9,
                'max_grade' => 12,
                'categories' => [
                    ['name' => 'Classwork', 'weight_percentage' => 10, 'items' => [['Classwork', 10]]],
                    ['name' => 'Quizzes', 'weight_percentage' => 10, 'items' => [['Quiz 1', 2.5], ['Quiz 2', 2.5], ['Quiz 3', 2.5], ['Quiz 4', 2.5]]],
                    ['name' => 'Tests', 'weight_percentage' => 10, 'items' => [['Test 1', 2.5], ['Test 2', 2.5], ['Test 3', 2.5], ['Test 4', 2.5]]],
                    ['name' => 'Assignments', 'weight_percentage' => 10, 'items' => [['Assignment 1', 2.5], ['Assignment 2', 2.5], ['Assignment 3', 2.5], ['Assignment 4', 2.5]]],
                    ['name' => 'Project / Research Paper', 'weight_percentage' => 20, 'items' => [['Project / Research Paper', 20]]],
                    ['name' => 'Quarter Final', 'weight_percentage' => 40, 'items' => [['Quarter Final', 40]]],
                ],
            ],
        ];
    }

    public function find(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }

    /**
     * Builds a preset into a real scheme. The caller has already checked that
     * nothing overlaps, so this only has to write.
     */
    public function apply(array $preset): GradeTier
    {
        return DB::transaction(function () use ($preset) {
            $tier = GradeTier::create([
                'name' => $preset['name'],
                'min_grade' => $preset['min_grade'],
                'max_grade' => $preset['max_grade'],
            ]);

            foreach ($preset['categories'] as $order => $category) {
                $row = GradingCategory::create([
                    'grade_tier_id' => $tier->id,
                    'name' => $category['name'],
                    'weight_percentage' => $category['weight_percentage'],
                    'display_order' => $order + 1,
                    'is_active' => true,
                ]);

                foreach ($category['items'] as $index => [$name, $maxScore]) {
                    GradingItem::create([
                        'grading_category_id' => $row->id,
                        'name' => $name,
                        'max_score' => $maxScore,
                        'display_order' => $index + 1,
                        'is_total_field' => false,
                        'is_active' => true,
                    ]);
                }
            }

            return $tier->load('categories.items');
        });
    }
}
