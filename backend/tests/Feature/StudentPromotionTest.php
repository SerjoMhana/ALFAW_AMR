<?php

namespace Tests\Feature;

use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentPromotionTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = '2026-2027';

    private const NEXT_YEAR = '2027-2028';

    public function test_preview_shows_each_student_moving_to_the_next_grade(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::YEAR);
        $this->section('G11', self::NEXT_YEAR);
        $this->student('Ali', $g10);
        $this->student('Sara', $g10);

        $this->withUser($admin)
            ->postJson('/api/promotions/preview', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'promote')
            ->assertJsonPath('data.target_section.class_name', 'G11')
            ->assertJsonPath('data.blocked_reason', null)
            ->assertJsonCount(2, 'data.students')
            ->assertJsonPath('data.students.0.target_class', 'G11');
    }

    public function test_promoting_moves_students_into_the_next_class(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::YEAR);
        $g11 = $this->section('G11', self::NEXT_YEAR);
        $student = $this->student('Ali', $g10);

        $this->withUser($admin)
            ->postJson('/api/promotions/apply', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'promote')
            ->assertJsonPath('data.moved', 1);

        $student->refresh();
        $this->assertSame('G11', $student->grade_level);
        $this->assertSame(11, (int) $student->current_grade_level);
        $this->assertSame(self::NEXT_YEAR, $student->academic_year);
        $this->assertSame($g11->id, $student->section_id);

        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $student->id,
            'course_section_id' => $g11->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $student->id,
            'course_section_id' => $g10->id,
            'status' => 'completed',
        ]);
    }

    public function test_only_selected_students_are_promoted(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::YEAR);
        $this->section('G11', self::NEXT_YEAR);
        $moving = $this->student('Ali', $g10);
        $staying = $this->student('Sara', $g10);

        $this->withUser($admin)
            ->postJson('/api/promotions/apply', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
                'student_profile_ids' => [$moving->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.moved', 1);

        $this->assertSame('G11', $moving->refresh()->grade_level);
        $this->assertSame('G10', $staying->refresh()->grade_level);
    }

    public function test_final_grade_students_graduate_into_the_archive(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g12 = $this->section('G12', self::YEAR);
        $student = $this->student('Omar', $g12);

        $this->withUser($admin)
            ->postJson('/api/promotions/preview', [
                'course_section_id' => $g12->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'graduate')
            ->assertJsonPath('data.target_section', null);

        $this->withUser($admin)
            ->postJson('/api/promotions/apply', [
                'course_section_id' => $g12->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'graduate');

        $student->refresh();
        $this->assertTrue($student->is_archived);
        $this->assertSame('تخرج - دفعة '.self::YEAR, $student->archive_reason);
        $this->assertSame($g12->id, $student->previous_section_id);
        $this->assertFalse((bool) $student->user->fresh()->is_active);
    }

    public function test_a_graduate_appears_in_the_archive_and_can_be_restored(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g12 = $this->section('G12', self::YEAR);
        $student = $this->student('Omar', $g12);

        $this->withUser($admin)->postJson('/api/promotions/apply', [
            'course_section_id' => $g12->id,
            'target_academic_year' => self::NEXT_YEAR,
        ])->assertOk();

        $this->withUser($admin)
            ->getJson('/api/students/archived')
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id);

        $this->withUser($admin)
            ->postJson("/api/students/{$student->id}/restore")
            ->assertOk();

        $student->refresh();
        $this->assertFalse($student->is_archived);
        $this->assertSame($g12->id, $student->section_id);
        $this->assertTrue((bool) $student->user->fresh()->is_active);
    }

    public function test_promotion_is_blocked_when_the_next_class_does_not_exist(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::YEAR);
        $this->student('Ali', $g10);

        $this->withUser($admin)
            ->postJson('/api/promotions/preview', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertOk()
            ->assertJsonPath('data.target_section', null)
            ->assertJsonPath('data.blocked_reason', 'لا يوجد فصل للصف التالي في السنة الدراسية المختارة. أنشئ الفصل أولاً من صفحة الفصول.');

        $this->withUser($admin)
            ->postJson('/api/promotions/apply', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertStatus(422);
    }

    public function test_an_explicit_target_class_overrides_the_suggestion(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::YEAR);
        $this->section('G11', self::NEXT_YEAR);
        $chosen = $this->section('G11-B', self::NEXT_YEAR);
        $student = $this->student('Ali', $g10);

        $this->withUser($admin)
            ->postJson('/api/promotions/apply', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
                'target_section_id' => $chosen->id,
            ])
            ->assertOk();

        $this->assertSame($chosen->id, $student->refresh()->section_id);
    }

    public function test_promoting_an_empty_class_is_rejected(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::YEAR);
        $this->section('G11', self::NEXT_YEAR);

        $this->withUser($admin)
            ->postJson('/api/promotions/apply', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertStatus(422);
    }

    public function test_archived_students_are_not_promoted(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::YEAR);
        $this->section('G11', self::NEXT_YEAR);
        $archived = $this->student('Ali', $g10);
        $archived->update(['status' => 'archived', 'archived_at' => now()]);

        $this->withUser($admin)
            ->postJson('/api/promotions/apply', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertStatus(422);

        $this->assertSame('G10', $archived->refresh()->grade_level);
    }

    public function test_context_lists_classes_with_student_counts(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::YEAR);
        $this->student('Ali', $g10);
        $this->student('Sara', $g10);

        $this->withUser($admin)
            ->getJson('/api/promotions/context?academic_year='.self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.0.class_name', 'G10')
            ->assertJsonPath('data.0.grade_number', 10)
            ->assertJsonPath('data.0.students_count', 2);
    }

    public function test_staff_without_permission_cannot_promote(): void
    {
        $staff = $this->user('staff', 'staff@example.com');
        $g10 = $this->section('G10', self::YEAR);

        $this->withUser($staff)
            ->postJson('/api/promotions/apply', [
                'course_section_id' => $g10->id,
                'target_academic_year' => self::NEXT_YEAR,
            ])
            ->assertForbidden();
    }

    private function section(string $className, string $year): CourseSection
    {
        return CourseSection::create([
            'teacher_id' => User::where('user_type', 'teacher')->first()?->id
                ?? $this->user('teacher', 'teacher@example.com')->id,
            'section_code' => $className.'-'.$year,
            'class_name' => $className,
            'academic_year' => $year,
            'term' => 'Quarter 1',
        ]);
    }

    private function student(string $name, CourseSection $section): StudentProfile
    {
        $user = $this->user('student', strtolower($name).'@example.com');
        $student = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'S-'.strtoupper($name),
            'full_name' => $name,
            'grade_level' => $section->class_name,
            'current_grade_level' => (int) filter_var($section->class_name, FILTER_SANITIZE_NUMBER_INT),
            'course' => $section->class_name,
            'academic_year' => $section->academic_year,
            'section_id' => $section->id,
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_profile_id' => $student->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        return $student->fresh();
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
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
