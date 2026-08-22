<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountRule extends Model
{
    public const TYPE_SIBLING = 'sibling';

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    public const TYPES = [self::TYPE_SIBLING, self::TYPE_PERCENTAGE, self::TYPE_FIXED];

    protected $fillable = [
        'name',
        'type',
        'applies_to_categories',
        'value',
        'sibling_tiers',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'applies_to_categories' => 'array',
            'sibling_tiers' => 'array',
            'value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A rule with no category list touches every charge.
     */
    public function coversCategory(string $category): bool
    {
        $categories = $this->applies_to_categories;

        return empty($categories) || in_array($category, $categories, true);
    }

    /**
     * Percentage owed for a given sibling ordinal, or null when that ordinal
     * earns nothing (typically the first child).
     */
    public function siblingPercentageFor(int $ordinal): ?float
    {
        $tiers = $this->sibling_tiers ?? [];

        return isset($tiers[(string) $ordinal]) ? (float) $tiers[(string) $ordinal] : null;
    }
}
