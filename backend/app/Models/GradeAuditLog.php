<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeAuditLog extends Model
{
    protected $fillable = [
        'student_score_id',
        'old_score',
        'new_score',
        'changed_by',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'old_score' => 'decimal:2',
            'new_score' => 'decimal:2',
        ];
    }

    public function studentScore(): BelongsTo
    {
        return $this->belongsTo(StudentScore::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
