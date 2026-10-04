<?php

namespace Tests\Feature;

use App\Models\CourseSection;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentManagementActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_edits_student_profile_and_moves_their_enrollment(): void
    {
        [$admin, $student, $profile] = $this->fixtures();
        $target = CourseSection::create([
            'section_code' => 'G8-A',
            'class_name' => 'Grade 8 A',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        $this->actingAs($admin)->putJson("/api/students/{$profile->id}", [
            'user_name' => 'Student Updated',
            'user_email' => 'student.updated@example.com',
            'student_number' => 'S-EDIT-1',
            'admission_no' => 'S-EDIT-1',
            'full_name' => 'Student Updated',
            'grade_level' => 'G8',
            'current_grade_level' => 8,
            'course' => 'Grade 8 A',
            'section_id' => $target->id,
            'academic_year' => '2026-2027',
        ])->assertOk()
            ->assertJsonPath('data.full_name', 'Student Updated')
            ->assertJsonPath('data.user.name', 'Student Updated');

        $this->assertDatabaseHas('student_profiles', [
            'id' => $profile->id,
            'admission_no' => 'S-EDIT-1',
            'section_id' => $target->id,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $profile->id,
            'course_section_id' => $target->id,
            'status' => 'active',
        ]);
        $this->assertSame('student.updated@example.com', $student->refresh()->email);
    }

    public function test_admin_changes_student_password(): void
    {
        [$admin, $student] = $this->fixtures();

        $this->actingAs($admin)->putJson("/api/users/{$student->id}", [
            'username' => 'student.changed',
            'password' => 'NewPass@12345',
        ])->assertOk()
            ->assertJsonPath('data.username', 'student.changed');

        $student->refresh();
        $this->assertTrue(Hash::check('NewPass@12345', $student->password));
        $this->assertFalse(Hash::check('OldPass@12345', $student->password));
    }

    public function test_deleting_from_active_students_archives_and_can_be_restored(): void
    {
        [$admin, $student, $profile] = $this->fixtures();

        $this->actingAs($admin)->postJson("/api/students/{$profile->id}/archive", [
            'archive_reason' => 'حذف من قائمة الطلبة ونقل إلى الأرشيف',
        ])->assertOk()
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.user.is_active', false);

        $this->assertDatabaseHas('student_profiles', [
            'id' => $profile->id,
            'status' => 'archived',
            'archive_reason' => 'حذف من قائمة الطلبة ونقل إلى الأرشيف',
        ]);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'is_active' => false]);

        $this->actingAs($admin)->postJson("/api/students/{$profile->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.user.is_active', true);

        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id, 'status' => 'active']);
    }

    /**
     * @return array{User, User, StudentProfile}
     */
    private function fixtures(): array
    {
        $admin = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('AdminPass@12345'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
        $student = User::create([
            'name' => 'Student',
            'username' => 'student',
            'email' => 'student@example.com',
            'password' => Hash::make('OldPass@12345'),
            'user_type' => 'student',
            'is_active' => true,
        ]);
        $profile = StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => 'S-OLD-1',
            'admission_no' => 'S-OLD-1',
            'full_name' => 'Student',
            'grade_level' => 'G7',
            'academic_year' => '2026-2027',
            'status' => 'active',
        ]);

        return [$admin, $student, $profile];
    }
}
