<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseOneAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_bypass_permissions(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);

        $token = $this->login('admin@example.com');

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.permissions.0', '*');

        $this->withToken($token)->getJson('/api/phase-one/staff-users-check')
            ->assertOk();
    }

    public function test_staff_access_is_limited_by_permissions(): void
    {
        $permission = Permission::create([
            'name' => 'users.view',
            'label' => 'View users',
        ]);

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'staff',
            'is_active' => true,
        ]);

        $token = $this->login('staff@example.com');

        $this->withToken($token)->getJson('/api/phase-one/staff-users-check')
            ->assertForbidden();

        $staff->permissions()->attach($permission);

        $this->withToken($token)->getJson('/api/phase-one/staff-users-check')
            ->assertOk();
    }

    public function test_teacher_and_student_have_no_admin_permissions(): void
    {
        $teacher = User::create([
            'name' => 'Teacher',
            'email' => 'teacher@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'teacher',
            'is_active' => true,
        ]);

        $student = User::create([
            'name' => 'Student',
            'email' => 'student@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        foreach ([$teacher, $student] as $user) {
            $token = $this->login($user->email);

            $this->withToken($token)->getJson('/api/phase-one/teacher-student-check')
                ->assertOk();

            $this->withToken($token)->getJson('/api/phase-one/staff-users-check')
                ->assertForbidden();
        }
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::create([
            'name' => 'Inactive',
            'email' => 'inactive@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'staff',
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@example.com',
            'password' => 'password',
        ])->assertUnprocessable();
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk()->json('token');
    }
}
