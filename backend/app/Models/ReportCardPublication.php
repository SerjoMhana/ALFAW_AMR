<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report card the admin has released to a class. Students and parents can
 * only download a report card that has a matching row here.
 */
class ReportCardPublication extends Model
{
    public const TYPE_QUARTER = 'quarter';

    public const TYPE_SEMESTER = 'semester';

    public const TYPE_FINAL = 'final';

    protected $fillable = [
        'course_section_id',
        'academic_year',
        'type',
        'period',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime:Y-m-d H:i',
        ];
    }

    /**
     * The period label doubles as the term passed to ReportCardService.
     */
    public static function periodFor(string $type, ?string $term, ?int $semester): string
    {
        return match ($type) {
            self::TYPE_SEMESTER => 'Semester '.$semester,
            self::TYPE_FINAL => 'Final Report',
            default => (string) $term,
        };
    }

    public function courseSection(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class);
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
