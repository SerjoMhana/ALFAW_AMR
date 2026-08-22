<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AcademicYear extends Model
{
    protected $fillable = [
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The year the school is currently working in. Exactly one row is ever
     * active, so the newest is only a tie-breaker for a database that predates
     * that rule.
     */
    public static function current(): ?self
    {
        return static::query()->active()->orderByDesc('name')->first();
    }

    public static function currentName(): ?string
    {
        return static::current()?->name;
    }

    /**
     * Switches the school over to this year. Every other year is stood down in
     * the same transaction, so a reader never sees two active years — or none.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            static::query()->whereKeyNot($this->getKey())->update(['is_active' => false]);
            $this->forceFill(['is_active' => true])->save();
        });
    }
}
