<?php

namespace App\Services;

use App\Models\GradeTier;
use App\Models\GradingCategory;
use App\Models\GradingItem;
use Illuminate\Support\Facades\DB;

class GradingStructureImportConfirmService
{
    /**
     * @param  array<int, array{tier_name: string, min_grade: int, max_grade: int, categories: array<int, array{name: string, weight_percentage: float, items: array<int, array{name: string, max_score: float, is_total_field: bool}>}>}>  $structure
     */
    public function confirm(array $structure): array
    {
        return DB::transaction(function () use ($structure) {
            return collect($structure)->map(fn (array $tier) => $this->applyTier($tier))->values()->all();
        });
    }

    private function applyTier(array $tier): GradeTier
    {
        $gradeTier = GradeTier::updateOrCreate(
            ['name' => $tier['tier_name']],
            ['min_grade' => $tier['min_grade'], 'max_grade' => $tier['max_grade']],
        );

        $seenCategoryIds = [];

        foreach ($tier['categories'] as $categoryIndex => $category) {
            $gradingCategory = GradingCategory::updateOrCreate(
                ['grade_tier_id' => $gradeTier->id, 'name' => $category['name']],
                [
                    'weight_percentage' => $category['weight_percentage'],
                    'display_order' => $categoryIndex,
                    'is_active' => true,
                ],
            );
            $seenCategoryIds[] = $gradingCategory->id;

            $seenItemIds = [];

            foreach ($category['items'] as $itemIndex => $item) {
                $gradingItem = GradingItem::updateOrCreate(
                    ['grading_category_id' => $gradingCategory->id, 'name' => $item['name']],
                    [
                        'max_score' => $item['max_score'],
                        'display_order' => $itemIndex,
                        'is_total_field' => $item['is_total_field'],
                        'is_active' => true,
                    ],
                );
                $seenItemIds[] = $gradingItem->id;
            }

            GradingItem::where('grading_category_id', $gradingCategory->id)
                ->whereNotIn('id', $seenItemIds)
                ->update(['is_active' => false]);
        }

        GradingCategory::where('grade_tier_id', $gradeTier->id)
            ->whereNotIn('id', $seenCategoryIds)
            ->update(['is_active' => false]);

        return $gradeTier->load('categories.items');
    }
}
