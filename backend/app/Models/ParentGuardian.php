<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ParentGuardian extends Model
{
    protected $table = 'parents';

    protected $fillable = [
        'user_id',
        'parent_admission_no',
        'first_name',
        'last_name',
        'full_name',
        'relation',
        'mobile',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(StudentProfile::class, 'parent_student', 'parent_id', 'student_id')
            ->withPivot(['relation', 'is_primary'])
            ->withTimestamps();
    }
}
