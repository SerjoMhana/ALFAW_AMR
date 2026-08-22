<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function view(User $user, Course $course): bool
    {
        return $user->isAdmin() || $user->hasPermission('courses.view');
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('courses.manage');
    }

    public function viewGradeSummary(User $user, Course $course): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('admin_manage_grades')
            || ($user->user_type === 'teacher' && $course->teacher_id === $user->id);
    }

    public function enterGrades(User $user, Course $course): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('admin_manage_grades')
            || ($user->user_type === 'teacher' && $course->teacher_id === $user->id);
    }
}
