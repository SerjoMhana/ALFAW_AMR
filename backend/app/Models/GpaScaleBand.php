<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GpaScaleBand extends Model
{
    protected $fillable = [
        'letter',
        'min_score',
        'max_score',
        'points',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'min_score' => 'float',
            'max_score' => 'float',
            'points' => 'float',
        ];
    }
}
