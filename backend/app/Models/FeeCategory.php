<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Admin-defined kind of charge — books, uniform, transport and so on. The
 * school adds whatever names it uses rather than picking from a fixed list.
 */
class FeeCategory extends Model
{
    protected $fillable = ['name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(FeeTemplate::class);
    }
}
