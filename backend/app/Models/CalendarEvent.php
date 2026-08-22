<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEvent extends Model
{
    use \App\Models\Concerns\BelongsToAcademicYear;

    public const KINDS = ['activity', 'holiday', 'exam', 'meeting', 'other'];

    /** Sensible defaults so a new event is already legible on the month. */
    public const KIND_COLORS = [
        'activity' => '#465fff',
        'holiday' => '#12b76a',
        'exam' => '#f04438',
        'meeting' => '#f79009',
        'other' => '#667085',
    ];

    protected $fillable = [
        'title', 'description', 'kind', 'color',
        'starts_on', 'ends_on', 'all_day', 'starts_at', 'ends_at',
        'location', 'academic_year', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'all_day' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Everything that touches the window, including an event that started
     * before it and runs into it — a week-long trip belongs on both months.
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('starts_on', '<=', $to)->where('ends_on', '>=', $from);
    }
}
