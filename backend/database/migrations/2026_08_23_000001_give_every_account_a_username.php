<?php

use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Everyone signs in with a username now, so everyone needs one.
 *
 * Students were already given their admission number as a username when added
 * by hand, but accounts created by other paths — the transcript import, early
 * seeding — were left without. This fills those in so nobody is left holding a
 * sign-in they cannot use.
 */
return new class extends Migration
{
    public function up(): void
    {
        StudentProfile::with('user')->whereHas('user', fn ($query) => $query->whereNull('username')->orWhere('username', ''))
            ->each(function (StudentProfile $profile): void {
                $candidate = $profile->admission_no ?: $profile->student_number;

                if (blank($candidate) || $this->taken($candidate, $profile->user_id)) {
                    return;
                }

                $profile->user?->forceFill(['username' => $candidate])->save();
            });

        // Anyone else without one falls back to the part of their email before
        // the @, which is what a person would have picked anyway.
        User::whereNull('username')->orWhere('username', '')->each(function (User $user): void {
            $candidate = str($user->email)->before('@')->toString();

            if (blank($candidate) || $this->taken($candidate, $user->id)) {
                return;
            }

            $user->forceFill(['username' => $candidate])->save();
        });
    }

    public function down(): void
    {
        // The usernames stay: taking them away would lock people out.
    }

    private function taken(string $username, ?int $exceptUserId): bool
    {
        return User::where('username', $username)
            ->when($exceptUserId, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->exists();
    }
};
