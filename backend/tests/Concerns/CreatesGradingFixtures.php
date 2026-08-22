<?php

namespace Tests\Concerns;

use App\Models\GradeTier;
use App\Models\GradingCategory;
use App\Models\GradingItem;
use App\Models\TermWindow;

trait CreatesGradingFixtures
{
    /**
     * Grades can only be entered or viewed while the admin has the quarter open,
     * so fixtures that exercise grading must open the window first.
     */
    protected function openTerm(string $academicYear, string $term = 'Quarter 1'): TermWindow
    {
        return TermWindow::firstOrCreate(
            ['academic_year' => $academicYear, 'term' => $term],
            ['is_open' => true, 'opened_at' => now()],
        );
    }

    protected function openAllTerms(string $academicYear): void
    {
        foreach (TermWindow::TERMS as $term) {
            $this->openTerm($academicYear, $term);
        }
    }

    /**
     * A scheme that is already switched on: a scheme does nothing until the
     * admin activates it, and fixtures are describing schools already running.
     */
    protected function gradeTier(string $name, int $minGrade, int $maxGrade): GradeTier
    {
        return GradeTier::firstOrCreate(
            ['name' => $name],
            ['min_grade' => $minGrade, 'max_grade' => $maxGrade, 'is_active' => true],
        );
    }

    protected function gradingCategory(GradeTier $tier, string $name, float $weight, int $order = 0): GradingCategory
    {
        return GradingCategory::firstOrCreate(
            ['grade_tier_id' => $tier->id, 'name' => $name],
            ['weight_percentage' => $weight, 'display_order' => $order],
        );
    }

    protected function gradingItem(GradingCategory $category, string $name, float $maxScore, int $order = 0): GradingItem
    {
        return GradingItem::create([
            'grading_category_id' => $category->id,
            'name' => $name,
            'max_score' => $maxScore,
            'display_order' => $order,
            'is_total_field' => false,
        ]);
    }
}
