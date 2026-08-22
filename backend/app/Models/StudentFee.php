<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One charge raised against one student, and the unit a receipt is allocated
 * onto. `amount`, `name` and `category` are snapshots taken when the charge was
 * raised, so later edits to the template never rewrite history.
 */
class StudentFee extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    protected $fillable = [
        'student_profile_id',
        'fee_template_id',
        'name',
        'category',
        'academic_year',
        'amount',
        'due_date',
        'paid_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(FeeTemplate::class, 'fee_template_id');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(StudentDiscount::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Only approved discounts reduce what is owed; pending ones do not.
     */
    public function approvedDiscountTotal(): float
    {
        return round((float) $this->discounts
            ->where('status', StudentDiscount::STATUS_APPROVED)
            ->sum(fn (StudentDiscount $discount) => (float) $discount->amount), 2);
    }

    /**
     * What is actually owed after approved discounts.
     */
    public function netAmount(): float
    {
        return round(max((float) $this->amount - $this->approvedDiscountTotal(), 0), 2);
    }

    public function outstanding(): float
    {
        return round(max($this->netAmount() - (float) $this->paid_amount, 0), 2);
    }

    /**
     * Recomputes status from what has been paid. Overdue only applies while
     * money is still owed.
     */
    public function refreshStatus(): void
    {
        $this->loadMissing('discounts');

        $outstanding = $this->outstanding();
        $paid = (float) $this->paid_amount;

        $this->status = match (true) {
            $outstanding <= 0 => self::STATUS_PAID,
            $this->due_date !== null && $this->due_date->isPast() => self::STATUS_OVERDUE,
            $paid > 0 => self::STATUS_PARTIALLY_PAID,
            default => self::STATUS_UNPAID,
        };

        $this->save();
    }
}
