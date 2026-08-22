<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge the school defines once and raises against matching students. One
 * template can cover several grades that are priced the same.
 */
class FeeTemplate extends Model
{
    protected $fillable = [
        'name',
        'fee_category_id',
        'amount',
        'academic_year',
        'grade_levels',
        'due_date',
        'is_mandatory',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'grade_levels' => 'array',
            'due_date' => 'date',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }

    /**
     * An empty grade list means the template applies to the whole school.
     */
    public function coversGrade(?string ...$labels): bool
    {
        $grades = $this->grade_levels;

        if (empty($grades)) {
            return true;
        }

        foreach ($labels as $label) {
            if ($label !== null && in_array($label, $grades, true)) {
                return true;
            }
        }

        return false;
    }
}
