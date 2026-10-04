<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TeacherCourseAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_subjects_to_a_teacher(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        [$math, $english] = $this->subjects();

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->putJson("/api/teachers/{$teacher->id}/courses", [
                'course_ids' => [$math->id, $english->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame($teacher->id, $math->fresh()->teacher_id);
        $this->assertSame($teacher->id, $english->fresh()->teacher_id);
    }

    public function test_syncing_removes_subjects_left_out_of_the_list(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        [$math, $english] = $this->subjects();
        Course::whereIn('id', [$math->id, $english->id])->update(['teacher_id' => $teacher->id]);

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->putJson("/api/teachers/{$teacher->id}/courses", ['course_ids' => [$math->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($teacher->id, $math->fresh()->teacher_id);
        $this->assertNull($english->fresh()->teacher_id);
    }

    public function test_assigning_a_subject_moves_it_from_the_previous_teacher(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $first = $this->user('teacher', 'first@example.com');
        $second = $this->user('teacher', 'second@example.com');
        [$math] = $this->subjects();
        $math->update(['teacher_id' => $first->id]);

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->putJson("/api/teachers/{$second->id}/courses", ['course_ids' => [$math->id]])
            ->assertOk();

        $this->assertSame($second->id, $math->fresh()->teacher_id);
        $this->assertSame(0, $first->courses()->count());
    }

    public function test_subjects_can_be_assigned_while_creating_the_teacher(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        [$math] = $this->subjects();

        $response = $this->withToken($admin->createToken('test')->plainTextToken)
            ->postJson('/api/teachers', [
                'name' => 'New Teacher',
                'email' => 'new.teacher@example.com',
                'username' => 'new.teacher',
                'password' => 'Str0ng!Passw0rd',
                'course_ids' => [$math->id],
            ])
            ->assertCreated();

        $this->assertSame($response->json('data.id'), $math->fresh()->teacher_id);
    }

    public function test_teacher_only_sees_assigned_subjects_in_grade_entry_context(): void
    {
        $teacher = $this->user('teacher', 'teacher@example.com');
        [$math, $english] = $this->subjects();
        $math->update(['teacher_id' => $teacher->id]);

        $this->withToken($teacher->createToken('test')->plainTextToken)
            ->getJson('/api/grade-entry/context')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $math->id);

        $this->assertNotNull($english->fresh());
    }

    public function test_grade_entry_context_uses_the_admins_active_year_automatically(): void
    {
        AcademicYear::create(['name' => '2025-2026', 'is_active' => false]);
        AcademicYear::create(['name' => '2026-2027', 'is_active' => true]);
        $teacher = $this->user('teacher', 'teacher@example.com');
        [$current] = $this->subjects();
        $current->update(['teacher_id' => $teacher->id]);

        $pastSection = CourseSection::create([
            'section_code' => 'G9-OLD',
            'class_name' => 'G9',
            'academic_year' => '2025-2026',
            'term' => 'Quarter 1',
        ]);
        Course::create([
            'code' => 'OLD-MATH',
            'name' => 'Old Mathematics',
            'grade_level' => 'G9',
            'class_section_id' => $pastSection->id,
            'teacher_id' => $teacher->id,
        ]);

        $this->withToken($teacher->createToken('test')->plainTextToken)
            ->getJson('/api/grade-entry/context')
            ->assertOk()
            ->assertJsonPath('academic_year', '2026-2027')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $current->id)
            ->assertJsonPath('data.0.class_section.academic_year', '2026-2027');
    }

    public function test_assignment_endpoint_rejects_non_teacher_users(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $staff = $this->user('staff', 'staff@example.com');
        [$math] = $this->subjects();

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->putJson("/api/teachers/{$staff->id}/courses", ['course_ids' => [$math->id]])
            ->assertStatus(422);
    }

    public function test_staff_without_permission_cannot_assign_subjects(): void
    {
        $staff = $this->user('staff', 'staff@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        [$math] = $this->subjects();

        $this->withToken($staff->createToken('test')->plainTextToken)
            ->putJson("/api/teachers/{$teacher->id}/courses", ['course_ids' => [$math->id]])
            ->assertForbidden();
    }

    /**
     * @return array{0: Course, 1: Course}
     */
    private function subjects(): array
    {
        $section = CourseSection::create([
            'teacher_id' => $this->user('teacher', 'homeroom@example.com')->id,
            'section_code' => 'G10-A',
            'class_name' => 'G10',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        return [
            Course::create([
                'code' => 'MATH10',
                'name' => 'Mathematics 10',
                'grade_level' => 'G10',
                'class_section_id' => $section->id,
            ]),
            Course::create([
                'code' => 'ENG10',
                'name' => 'English 10',
                'grade_level' => 'G10',
                'class_section_id' => $section->id,
            ]),
        ];
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
