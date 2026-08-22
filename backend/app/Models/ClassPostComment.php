<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassPostComment extends Model
{
    use SoftDeletes;

    protected $fillable = ['class_post_id', 'user_id', 'body'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(ClassPost::class, 'class_post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
