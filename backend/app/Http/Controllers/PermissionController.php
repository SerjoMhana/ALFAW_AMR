<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncStaffPermissionsRequest;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Permission::orderBy('name')->get(),
        ]);
    }

    public function staff(): JsonResponse
    {
        return response()->json([
            'data' => User::query()
                ->where('user_type', 'staff')
                ->with('permissions')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function syncStaff(SyncStaffPermissionsRequest $request, User $user): JsonResponse
    {
        abort_unless($user->user_type === 'staff', 422, 'Permissions can only be assigned to staff users.');

        $permissionIds = Permission::query()
            ->whereIn('name', $request->input('permissions', []))
            ->pluck('id');

        $user->permissions()->sync($permissionIds);

        return response()->json([
            'data' => $user->load('permissions'),
        ]);
    }
}
