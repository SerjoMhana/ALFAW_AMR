<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Admin-controlled gate for a quarter. While a term is open teachers may enter
 * grades for it and students and parents may see them; while it is closed the
 * term does not exist as far as either side is concerned.
 *
 * A missing row means closed.
 */
class TermWindow extends Model
{
    public const TERMS = ['Quarter 1', 'Quarter 2', 'Quarter 3', 'Quarter 4'];

    protected $fillable = [
        'academic_year',
        'term',
        'is_open',
        'opened_at',
        'opened_by',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public static function isOpen(string $academicYear, string $term): bool
    {
        return static::query()
            ->where('academic_year', $academicYear)
            ->where('term', $term)
            ->where('is_open', true)
            ->exists();
    }

    /**
     * Open terms for a year, in the canonical Quarter 1..4 order.
     *
     * @return Collection<int, string>
     */
    public static function openTerms(string $academicYear): Collection
    {
        $open = static::query()
            ->where('academic_year', $academicYear)
            ->where('is_open', true)
            ->pluck('term')
            ->all();

        return collect(self::TERMS)->filter(fn (string $term) => in_array($term, $open, true))->values();
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
