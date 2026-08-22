<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseTwoAcademicStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_class_with_the_fields_from_the_add_class_form(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $sectionId = $this->withToken($token)->postJson('/api/course-sections', [
            'section_code' => 'G10-A',
            'class_name' => 'Grade 10 A',
            'academic_year' => '2026-2027',
            'capacity' => 25,
        ])->assertCreated()
            ->assertJsonPath('data.section_code', 'G10-A')
            ->assertJsonPath('data.class_name', 'Grade 10 A')
            ->assertJsonPath('data.academic_year', '2026-2027')
            ->assertJsonPath('data.capacity', 25)
            ->json('data.id');

        $this->assertDatabaseHas('course_sections', [
            'section_code' => 'G10-A',
            'class_name' => 'Grade 10 A',
            'academic_year' => '2026-2027',
            'capacity' => 25,
        ]);

        foreach ([['MATH10', 'Mathematics'], ['SCI10', 'Science']] as [$code, $name]) {
            $this->withToken($token)->postJson('/api/courses', [
                'code' => $code,
                'name' => $name,
                'grade_level' => 'G10',
                'class_section_id' => $sectionId,
            ])->assertCreated()
                ->assertJsonPath('data.class_section_id', $sectionId);
        }

        $this->assertDatabaseCount('courses', 2);

        $this->withToken($token)->postJson('/api/courses/bulk', [
            'class_section_id' => $sectionId,
            'courses' => [
                ['code' => 'ENG10', 'name' => 'English'],
                ['code' => 'HIS10', 'name' => 'History'],
            ],
        ])->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.class_section_id', $sectionId)
            ->assertJsonPath('data.1.class_section_id', $sectionId);

        $this->assertDatabaseCount('courses', 4);
    }

    public function test_admin_can_create_student_course_section_and_enrollment(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);

        $teacher = User::create([
            'name' => 'Teacher',
            'email' => 'teacher@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'teacher',
            'is_active' => true,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $studentProfileId = $this->withToken($token)->postJson('/api/student-profiles', [
            'user_name' => 'Student One',
            'user_email' => 'student.one@example.com',
            'user_password' => 'Str0ng!Passw0rd',
            'student_number' => 'S-1001',
            'grade_level' => 'G10',
            'guardian_name' => 'Guardian One',
        ])->assertCreated()
            ->assertJsonPath('data.user.user_type', 'student')
            ->json('data.id');

        $sectionId = $this->withToken($token)->postJson('/api/course-sections', [
            'teacher_id' => $teacher->id,
            'section_code' => 'MATH10-A',
            'class_name' => 'Grade 10 A',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
            'capacity' => 25,
        ])->assertCreated()
            ->assertJsonPath('data.teacher.id', $teacher->id)
            ->json('data.id');

        $this->withToken($token)->postJson('/api/courses', [
            'code' => 'MATH10',
            'name' => 'Mathematics 10',
            'grade_level' => 'G10',
            'class_section_id' => $sectionId,
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/enrollments', [
            'student_profile_id' => $studentProfileId,
            'course_section_id' => $sectionId,
            'status' => 'active',
            'enrolled_at' => '2026-08-20',
        ])->assertCreated()
            ->assertJsonPath('data.student_profile.id', $studentProfileId)
            ->assertJsonPath('data.course_section.id', $sectionId);
    }

    public function test_section_teacher_must_be_teacher_user(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);

        $student = User::create([
            'name' => 'Student',
            'email' => 'student@example.com',
            'password' => Hash::make('password'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/course-sections', [
            'teacher_id' => $student->id,
            'section_code' => 'SCI10-A',
            'class_name' => 'Grade 10 A',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ])->assertUnprocessable();
    }
}
