<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeTier extends Model
{
    protected $fillable = [
        'name',
        'min_grade',
        'max_grade',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_grade' => 'integer',
            'max_grade' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(GradingCategory::class);
    }

    /**
     * The classes told to work with this scheme. A class holds one scheme, so
     * this is the full list of what the scheme governs.
     */
    public function courseSections(): HasMany
    {
        return $this->hasMany(CourseSection::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Every mark recorded under this scheme — what deactivating it would wipe.
     */
    public function scoreQuery()
    {
        return StudentScore::query()->whereIn(
            'grading_item_id',
            GradingItem::query()
                ->select('id')
                ->whereIn('grading_category_id', $this->categories()->select('id')),
        );
    }

    /**
     * The active scheme covering a grade, used only as a fallback for a class
     * that has not been pointed at one explicitly. An inactive scheme never
     * matches: a scheme does nothing until the admin switches it on.
     */
    public static function forNumericGrade(int $grade): ?self
    {
        return static::query()
            ->active()
            ->where('min_grade', '<=', $grade)
            ->where('max_grade', '>=', $grade)
            ->orderBy('min_grade')
            ->first();
    }

    /**
     * Another scheme already claiming any grade in this range, which would make
     * the lookup above ambiguous.
     */
    public static function overlapping(int $minGrade, int $maxGrade, ?int $ignoreId = null): ?self
    {
        return static::query()
            ->where('min_grade', '<=', $maxGrade)
            ->where('max_grade', '>=', $minGrade)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    /**
     * Whether any mark has been entered against this scheme, which is what makes
     * it unsafe to delete or re-range.
     */
    public function hasRecordedScores(): bool
    {
        return StudentScore::query()
            ->whereIn(
                'grading_item_id',
                GradingItem::query()
                    ->select('id')
                    ->whereIn('grading_category_id', $this->categories()->select('id')),
            )
            ->exists();
    }
}
