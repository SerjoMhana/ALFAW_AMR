<?php

namespace Tests\Unit;

use App\Services\GradeCalculationService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * The weighting arithmetic on its own, with no database and no HTTP: given
 * category averages and weights, what final grade comes out.
 */
class GradeCalculationTest extends TestCase
{
    private GradeCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GradeCalculationService;
    }

    public function test_a_category_average_is_the_share_of_its_total_max(): void
    {
        $items = $this->items([['id' => 1, 'max' => 20], ['id' => 2, 'max' => 30]]);
        $scores = $this->scores([1 => 15, 2 => 20]);

        // 35 out of 50.
        $this->assertSame(70.0, $this->service->calculateCategoryAverage($items, $scores));
    }

    public function test_an_unscored_item_is_left_out_rather_than_counted_as_zero(): void
    {
        $items = $this->items([['id' => 1, 'max' => 20], ['id' => 2, 'max' => 30]]);
        $scores = $this->scores([1 => 15]);

        // Only the scored item counts: 15 of 20, not 15 of 50.
        $this->assertSame(75.0, $this->service->calculateCategoryAverage($items, $scores));
    }

    public function test_a_category_with_nothing_scored_has_no_average(): void
    {
        $items = $this->items([['id' => 1, 'max' => 20]]);

        $this->assertNull($this->service->calculateCategoryAverage($items, $this->scores([])));
    }

    public function test_a_zero_is_a_score_and_not_a_blank(): void
    {
        $items = $this->items([['id' => 1, 'max' => 20]]);

        $this->assertSame(0.0, $this->service->calculateCategoryAverage($items, $this->scores([1 => 0])));
    }

    public function test_a_category_whose_items_are_worth_nothing_has_no_average(): void
    {
        $items = $this->items([['id' => 1, 'max' => 0]]);

        $this->assertNull($this->service->calculateCategoryAverage($items, $this->scores([1 => 0])));
    }

    public function test_the_weighted_score_is_the_average_times_the_weight(): void
    {
        $this->assertSame(28.0, $this->service->calculateWeightedScore(70.0, 40.0));
        $this->assertSame(0.0, $this->service->calculateWeightedScore(0.0, 40.0));
        $this->assertSame(40.0, $this->service->calculateWeightedScore(100.0, 40.0));
    }

    public function test_the_final_grade_is_the_sum_of_the_weighted_parts(): void
    {
        // The school's G9-12 split: 10/10/10/10/20/40, every part at 89.4%.
        $parts = array_map(
            fn (float $weight) => $this->service->calculateWeightedScore(89.4, $weight),
            [10.0, 10.0, 10.0, 10.0, 20.0, 40.0],
        );

        $this->assertEqualsWithDelta(89.4, $this->service->calculateFinalGrade($parts), 0.0001);
    }

    public function test_full_marks_everywhere_give_one_hundred(): void
    {
        $parts = array_map(
            fn (float $weight) => $this->service->calculateWeightedScore(100.0, $weight),
            [20.0, 20.0, 20.0, 20.0, 20.0],
        );

        $this->assertSame(100.0, $this->service->calculateFinalGrade($parts));
    }

    /**
     * A part-filled sheet must not read as a low grade; the weight that was
     * actually applied is what the caller reports alongside it.
     */
    public function test_only_the_scored_categories_contribute(): void
    {
        $parts = [$this->service->calculateWeightedScore(80.0, 40.0)];

        $this->assertSame(32.0, $this->service->calculateFinalGrade($parts));
    }

    /**
     * @param  array<int, array{id: int, max: float|int}>  $rows
     */
    private function items(array $rows): Collection
    {
        return collect($rows)->map(fn (array $row) => (object) [
            'id' => $row['id'],
            'max_score' => $row['max'],
        ]);
    }

    /**
     * @param  array<int, float|int|null>  $byItemId
     */
    private function scores(array $byItemId): Collection
    {
        return collect($byItemId)->map(fn ($value) => (object) ['score_obtained' => $value]);
    }
}
