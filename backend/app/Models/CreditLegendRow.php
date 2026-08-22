<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditLegendRow extends Model
{
    protected $fillable = [
        'classes_per_week',
        'credits',
    ];

    protected function casts(): array
    {
        return [
            'classes_per_week' => 'integer',
            'credits' => 'float',
        ];
    }
}
