<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'phone_2',
        'address',
        'academic_qualification',
        'password',
        'user_type',
        'is_active',
        'google_email',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // Suspending an account has to take its keys away at that moment, not
        // whenever the holder next happens to make a request.
        static::updated(function (self $user): void {
            if ($user->wasChanged('is_active') && ! $user->is_active) {
                $user->tokens()->delete();
            }
        });
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /**
     * The guardian record behind a `parent` login, used to resolve their children.
     */
    public function parentGuardian(): HasOne
    {
        return $this->hasOne(ParentGuardian::class);
    }

    public function teachingSections(): HasMany
    {
        return $this->hasMany(CourseSection::class, 'teacher_id');
    }

    /**
     * Subjects this teacher is assigned to teach (and may enter grades for).
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'teacher_id');
    }

    public function isAdmin(): bool
    {
        return $this->user_type === 'admin';
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isAdmin() || $this->permissions()->where('name', $permission)->exists();
    }
}
