<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradingItem extends Model
{
    protected $fillable = [
        'grading_category_id',
        'name',
        'max_score',
        'display_order',
        'is_total_field',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'max_score' => 'decimal:2',
            'display_order' => 'integer',
            'is_total_field' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GradingCategory::class, 'grading_category_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(StudentScore::class);
    }
}
