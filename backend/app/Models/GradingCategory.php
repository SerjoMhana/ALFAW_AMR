<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingCategory extends Model
{
    protected $fillable = [
        'grade_tier_id',
        'name',
        'weight_percentage',
        'display_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weight_percentage' => 'decimal:2',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function gradeTier(): BelongsTo
    {
        return $this->belongsTo(GradeTier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GradingItem::class);
    }
}
