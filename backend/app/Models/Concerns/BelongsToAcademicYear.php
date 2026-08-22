<?php

namespace App\Models\Concerns;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Builder;

/**
 * The school works in one year at a time.
 *
 * Every list the app shows is a list of that year: switching the active year is
 * how the office looks at a past one, and nothing outside it should appear
 * uninvited beside this year's work.
 */
trait BelongsToAcademicYear
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInActiveYear(Builder $query, string $column = 'academic_year'): Builder
    {
        $year = AcademicYear::currentName();

        // No year set up yet: showing everything beats showing nothing, and the
        // school has not started keeping years apart anyway.
        return $year === null ? $query : $query->where($column, $year);
    }
}
