<?php

namespace App\Policies;

use App\Models\StudentProfile;
use App\Models\User;

class StudentProfilePolicy
{
    public function view(User $user, StudentProfile $studentProfile): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('students.view')
            || ($user->user_type === 'student' && $studentProfile->user_id === $user->id);
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('students.manage');
    }
}
