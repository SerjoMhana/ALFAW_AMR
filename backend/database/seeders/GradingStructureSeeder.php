<?php

namespace Database\Seeders;

use App\Models\GradeTier;
use App\Models\GradingCategory;
use App\Models\GradingItem;
use Illuminate\Database\Seeder;

class GradingStructureSeeder extends Seeder
{
    /**
     * Seeds the exact grading structure defined in
     * "VIS American Dept. Grading System Grades 1-12 Final.xlsx"
     * (sheets G1-4, G5-8, G9-12).
     */
    public function run(): void
    {
        GradeTier::whereIn('name', ['G1-3', 'G4-6', 'G7-12'])->delete();

        foreach ($this->tiers() as $tier) {
            $gradeTier = GradeTier::updateOrCreate(
                ['name' => $tier['name']],
                ['min_grade' => $tier['min_grade'], 'max_grade' => $tier['max_grade']],
            );

            foreach ($tier['categories'] as $categoryIndex => $category) {
                $gradingCategory = GradingCategory::updateOrCreate(
                    ['grade_tier_id' => $gradeTier->id, 'name' => $category['name']],
                    [
                        'weight_percentage' => $category['weight_percentage'],
                        'display_order' => $categoryIndex,
                        'is_active' => true,
                    ],
                );

                foreach ($category['items'] as $itemIndex => $item) {
                    GradingItem::updateOrCreate(
                        ['grading_category_id' => $gradingCategory->id, 'name' => $item['name']],
                        [
                            'max_score' => $item['max_score'],
                            'display_order' => $itemIndex,
                            'is_total_field' => $item['is_total_field'],
                            'is_active' => true,
                        ],
                    );
                }
            }
        }
    }

    private function tiers(): array
    {
        return [
            [
                'name' => 'G1-4',
                'min_grade' => 1,
                'max_grade' => 4,
                'categories' => [
                    ['name' => 'Classwork', 'weight_percentage' => 20, 'items' => [
                        ['name' => 'Classwork', 'max_score' => 20, 'is_total_field' => false],
                    ]],
                    ['name' => 'Quizzes', 'weight_percentage' => 20, 'items' => [
                        ['name' => 'Quiz1', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Quiz2', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Quiz3', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Quiz4', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Quizzes Total', 'max_score' => 20, 'is_total_field' => true],
                    ]],
                    ['name' => 'Tests', 'weight_percentage' => 20, 'items' => [
                        ['name' => 'Test1', 'max_score' => 10, 'is_total_field' => false],
                        ['name' => 'Test2', 'max_score' => 10, 'is_total_field' => false],
                        ['name' => 'Tests Total', 'max_score' => 20, 'is_total_field' => true],
                    ]],
                    ['name' => 'Assignments', 'weight_percentage' => 20, 'items' => [
                        ['name' => 'Assig.1', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Assig.2', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Assig.3', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Assig.4', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Assign.s Total', 'max_score' => 20, 'is_total_field' => true],
                    ]],
                    ['name' => 'Quarter Final', 'weight_percentage' => 20, 'items' => [
                        ['name' => 'Quarter Final', 'max_score' => 20, 'is_total_field' => false],
                    ]],
                ],
            ],
            [
                'name' => 'G5-8',
                'min_grade' => 5,
                'max_grade' => 8,
                'categories' => [
                    ['name' => 'Classwork', 'weight_percentage' => 10, 'items' => [
                        ['name' => 'Classwork', 'max_score' => 10, 'is_total_field' => false],
                    ]],
                    ['name' => 'Quizzes', 'weight_percentage' => 10, 'items' => [
                        ['name' => 'Quiz1', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Quiz2', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Quiz3', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Quiz4', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Quizzes Total', 'max_score' => 10, 'is_total_field' => true],
                    ]],
                    ['name' => 'Tests', 'weight_percentage' => 20, 'items' => [
                        ['name' => 'Test1', 'max_score' => 10, 'is_total_field' => false],
                        ['name' => 'Test2', 'max_score' => 10, 'is_total_field' => false],
                        ['name' => 'Tests Total', 'max_score' => 20, 'is_total_field' => true],
                    ]],
                    ['name' => 'Assignments', 'weight_percentage' => 20, 'items' => [
                        ['name' => 'Assig.1', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Assig.2', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Assig.3', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Assig.4', 'max_score' => 5, 'is_total_field' => false],
                        ['name' => 'Assign.s Total', 'max_score' => 20, 'is_total_field' => true],
                    ]],
                    ['name' => 'Project / Research Paper', 'weight_percentage' => 10, 'items' => [
                        ['name' => 'Project / Research Paper', 'max_score' => 10, 'is_total_field' => false],
                    ]],
                    ['name' => 'Quarter Final', 'weight_percentage' => 30, 'items' => [
                        ['name' => 'Quarter Final', 'max_score' => 30, 'is_total_field' => false],
                    ]],
                ],
            ],
            [
                'name' => 'G9-12',
                'min_grade' => 9,
                'max_grade' => 12,
                'categories' => [
                    ['name' => 'Classwork', 'weight_percentage' => 10, 'items' => [
                        ['name' => 'Classwork', 'max_score' => 10, 'is_total_field' => false],
                    ]],
                    ['name' => 'Quizzes', 'weight_percentage' => 10, 'items' => [
                        ['name' => 'Quiz1', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Quiz2', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Quiz3', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Quiz4', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Quizzes Total', 'max_score' => 10, 'is_total_field' => true],
                    ]],
                    ['name' => 'Tests', 'weight_percentage' => 10, 'items' => [
                        ['name' => 'Test1', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Test2', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Test3', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Test4', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Tests Total', 'max_score' => 10, 'is_total_field' => true],
                    ]],
                    ['name' => 'Assignments', 'weight_percentage' => 10, 'items' => [
                        ['name' => 'Assig.1', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Assig.2', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Assig.3', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Assig.4', 'max_score' => 2.5, 'is_total_field' => false],
                        ['name' => 'Assign.s Total', 'max_score' => 10, 'is_total_field' => true],
                    ]],
                    ['name' => 'Project / Research Paper', 'weight_percentage' => 20, 'items' => [
                        ['name' => 'Project / Research Paper', 'max_score' => 20, 'is_total_field' => false],
                    ]],
                    ['name' => 'Quarter Final', 'weight_percentage' => 40, 'items' => [
                        ['name' => 'Quarter Final', 'max_score' => 40, 'is_total_field' => false],
                    ]],
                ],
            ],
        ];
    }
}
