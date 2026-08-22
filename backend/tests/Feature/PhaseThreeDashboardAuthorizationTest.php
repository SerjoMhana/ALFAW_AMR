<?php

namespace Tests\Feature;

use App\Models\CourseSection;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseThreeDashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_without_permission_cannot_view_or_manage_page_api(): void
    {
        $staff = $this->user('staff', 'staff@example.com');
        $token = $staff->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/courses')
            ->assertForbidden();

        $this->withToken($token)->postJson('/api/courses', [
            'code' => 'ENG10',
            'name' => 'English 10',
            'grade_level' => 'G10',
        ])->assertForbidden();
    }

    public function test_staff_with_permission_can_perform_allowed_operation(): void
    {
        $view = Permission::create(['name' => 'courses.view', 'label' => 'View courses']);
        $manage = Permission::create(['name' => 'courses.manage', 'label' => 'Manage courses']);
        $staff = $this->user('staff', 'staff@example.com');
        $staff->permissions()->sync([$view->id, $manage->id]);

        $token = $staff->createToken('test')->plainTextToken;
        $teacher = $this->user('teacher', 'teacher@example.com');

        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'G10-A',
            'class_name' => 'G10-A',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        $this->withToken($token)->postJson('/api/courses', [
            'code' => 'ENG10',
            'name' => 'English 10',
            'grade_level' => 'G10',
            'class_section_id' => $section->id,
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/courses')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'ENG10');
    }

    public function test_admin_can_assign_staff_permissions(): void
    {
        Permission::create(['name' => 'students.view', 'label' => 'View students']);
        Permission::create(['name' => 'students.manage', 'label' => 'Manage students']);

        $admin = $this->user('admin', 'admin@example.com');
        $staff = $this->user('staff', 'staff@example.com');
        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson("/api/staff-permissions/{$staff->id}", [
            'permissions' => ['students.view', 'students.manage'],
        ])->assertOk()
            ->assertJsonCount(2, 'data.permissions');

        $this->assertTrue($staff->fresh()->hasPermission('students.manage'));
    }

    private function user(string $type, string $email): User
    {
        return User::create([
            'name' => ucfirst($type),
            'email' => $email,
            'password' => Hash::make('password'),
            'user_type' => $type,
            'is_active' => true,
        ]);
    }
}
