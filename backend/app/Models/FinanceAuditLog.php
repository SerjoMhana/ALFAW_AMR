<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Request;

/**
 * Append-only trail of every financial change: what was touched, by whom, from
 * where, and what the values were before and after.
 */
class FinanceAuditLog extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'before',
        'after',
        'user_id',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public static function record(
        Model $entity,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $userId = null,
    ): self {
        return static::create([
            'entity_type' => class_basename($entity),
            'entity_id' => $entity->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'user_id' => $userId ?? auth()->id(),
            'ip_address' => Request::ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
