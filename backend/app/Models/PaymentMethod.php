<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = ['name', 'is_active', 'display_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function activeNames(): array
    {
        return static::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
