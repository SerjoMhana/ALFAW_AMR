<?php

namespace App\Policies;

use App\Models\CourseSection;
use App\Models\User;

class CourseSectionPolicy
{
    public function view(User $user, CourseSection $courseSection): bool
    {
        return $user->isAdmin()
            || $user->hasPermission('sections.view')
            || ($user->user_type === 'teacher' && $courseSection->teacher_id === $user->id)
            || ($user->user_type === 'student' && $user->studentProfile?->enrollments()
                ->where('course_section_id', $courseSection->id)
                ->where('status', 'active')
                ->exists());
    }

    public function manage(User $user, CourseSection $courseSection): bool
    {
        return $user->isAdmin() || $user->hasPermission('sections.manage');
    }
}
