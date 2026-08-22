<?php

namespace Tests\Feature;

use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A class and a teacher are independent: a class is usually created before the
 * school decides who teaches it, and a teacher is hired before being given one.
 */
class ClassTeacherOptionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_class_is_created_without_a_teacher(): void
    {
        $admin = $this->admin();

        $id = $this->withUser($admin)->postJson('/api/course-sections', [
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => '2025-2026',
        ])->assertCreated()->json('data.id');

        $this->assertNull(CourseSection::findOrFail($id)->teacher_id);
    }

    /**
     * The old behaviour invented a "Default Teacher" login — a real account with
     * a weak password that nobody asked for.
     */
    public function test_creating_a_class_invents_no_account(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)->postJson('/api/course-sections', [
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => '2025-2026',
        ])->assertCreated();

        $this->assertSame(0, User::where('user_type', 'teacher')->count());
        $this->assertDatabaseMissing('users', ['email' => 'default.teacher@school.test']);
    }

    public function test_a_teacher_can_still_be_named_when_creating_the_class(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $id = $this->withUser($admin)->postJson('/api/course-sections', [
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => '2025-2026',
            'teacher_id' => $teacher->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame($teacher->id, CourseSection::findOrFail($id)->teacher_id);
    }

    public function test_a_teacher_can_be_assigned_after_the_fact(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $section = CourseSection::create([
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => '2025-2026',
            'term' => 'Quarter 1',
        ]);

        $this->withUser($admin)
            ->putJson("/api/course-sections/{$section->id}", ['teacher_id' => $teacher->id])
            ->assertOk();

        $this->assertSame($teacher->id, $section->refresh()->teacher_id);
    }

    public function test_a_teacher_exists_without_any_class(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)->postJson('/api/teachers', [
            'name' => 'Sara Ahmed',
            'email' => 'sara@example.com',
            'password' => 'Str0ng!Passw0rd',
        ])->assertCreated();

        $teacher = User::where('email', 'sara@example.com')->firstOrFail();

        $this->assertSame('teacher', $teacher->user_type);
        $this->assertSame(0, CourseSection::where('teacher_id', $teacher->id)->count());
    }

    /**
     * Removing a teacher leaves their classes standing but unassigned; the class
     * and everything hanging off it survive.
     */
    public function test_deleting_a_teacher_unassigns_their_classes(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $section = CourseSection::create([
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => '2025-2026',
            'term' => 'Quarter 1',
            'teacher_id' => $teacher->id,
        ]);

        $this->withUser($admin)
            ->deleteJson("/api/teachers/{$teacher->id}")
            ->assertOk()
            ->assertJsonPath('unassigned_classes', 1);

        $this->assertDatabaseHas('course_sections', ['id' => $section->id]);
        $this->assertNull($section->refresh()->teacher_id);
    }

    public function test_the_class_list_reports_a_missing_teacher_as_null(): void
    {
        $admin = $this->admin();
        CourseSection::create([
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => '2025-2026',
            'term' => 'Quarter 1',
        ]);

        $this->withUser($admin)
            ->getJson('/api/course-sections')
            ->assertOk()
            ->assertJsonPath('data.0.teacher', null);
    }

    private function teacher(): User
    {
        return User::create([
            'name' => 'Teacher',
            'email' => 'teacher@example.com',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'teacher',
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
