<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A receipt. Once written it is never edited or deleted — a mistake is
 * corrected by voiding, which reverses the allocations and leaves this row and
 * its serial number in place.
 */
class Payment extends Model
{
    protected $fillable = [
        'student_profile_id',
        'academic_year',
        'receipt_number',
        'amount',
        'method',
        'reference',
        'paid_on',
        'notes',
        'received_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'receipt_number' => 'integer',
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
