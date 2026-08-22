<?php

namespace App\Policies;

use App\Models\User;

class GradingStructurePolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('manage_grading_structure');
    }

    public function import(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('import_grading_structure');
    }
}
