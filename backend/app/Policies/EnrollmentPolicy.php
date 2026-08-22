<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;

class EnrollmentPolicy
{
    public function view(User $user, Enrollment $enrollment): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('enrollments.manage')
            || ($user->user_type === 'student' && $enrollment->studentProfile->user_id === $user->id);
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('enrollments.manage');
    }
}
