<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money handed to someone to spend on the school's behalf.
 */
class CashAdvance extends Model
{
    use \App\Models\Concerns\BelongsToAcademicYear;

    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'academic_year', 'advance_number', 'holder_id', 'holder_name',
        'purpose', 'amount', 'method', 'reference', 'issued_on', 'notes',
        'status', 'returned_amount', 'reimbursed_amount',
        'issued_by', 'settled_at', 'settled_by',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'advance_number' => 'integer',
            'amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'reimbursed_amount' => 'decimal:2',
            'issued_on' => 'date',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(CashAdvanceExpense::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * What has been spent out of the advance so far.
     */
    public function spent(): float
    {
        return round((float) $this->expenses()->sum('amount'), 2);
    }

    /**
     * What is still unaccounted for.
     *
     * Positive means the holder is still carrying the school's money; negative
     * means they spent their own and the school owes them.
     */
    public function outstanding(): float
    {
        return round(
            (float) $this->amount
            - $this->spent()
            - (float) $this->returned_amount
            + (float) $this->reimbursed_amount,
            2,
        );
    }
}
