<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far one person has read one subject's stream.
 */
class ClassPostView extends Model
{
    protected $fillable = ['user_id', 'course_id', 'last_seen_post_id', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime', 'last_seen_post_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
