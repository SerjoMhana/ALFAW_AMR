<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GradingItem;
use App\Models\Permission;
use App\Models\StudentProfile;
use App\Models\TermWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

class TermWindowTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    private const YEAR = '2026-2027';

    public function test_teacher_cannot_save_grades_while_the_term_is_closed(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->fixture();

        $this->withUser($teacher)
            ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, 8))
            ->assertForbidden();

        $this->assertDatabaseCount('student_scores', 0);
    }

    public function test_teacher_can_save_once_the_admin_opens_the_term(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->fixture();
        $this->openTerm('Quarter 1');

        $this->withUser($teacher)
            ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, 8))
            ->assertOk();

        $this->assertDatabaseHas('student_scores', ['course_id' => $course->id, 'score_obtained' => 8]);
    }

    public function test_teacher_cannot_submit_while_the_term_is_closed(): void
    {
        [$teacher, , , $course] = $this->fixture();

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertForbidden();
    }

    public function test_sheet_reports_the_term_as_closed_and_not_editable(): void
    {
        [$teacher, , , $course] = $this->fixture();

        $this->withUser($teacher)
            ->getJson("/api/courses/{$course->id}/grade-entry?term=Quarter%201&academic_year=".self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.submission.term_open', false)
            ->assertJsonPath('data.submission.can_edit', false)
            ->assertJsonPath('data.submission.can_submit', false);
    }

    public function test_admin_can_still_edit_a_closed_term(): void
    {
        [, $item, $studentProfile, $course] = $this->fixture();
        $admin = $this->user('admin', 'admin@example.com');

        $this->withUser($admin)
            ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, 9))
            ->assertOk();
    }

    public function test_student_cannot_see_a_closed_term(): void
    {
        [, , $studentProfile] = $this->fixture();

        $this->withUser($studentProfile->user)
            ->getJson('/api/student/terms')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withUser($studentProfile->user)
            ->getJson('/api/student/dashboard?term=Quarter%201')
            ->assertForbidden();
    }

    public function test_student_sees_the_term_once_it_is_opened(): void
    {
        [, , $studentProfile] = $this->fixture();
        $this->openTerm('Quarter 1');

        $this->withUser($studentProfile->user)
            ->getJson('/api/student/terms')
            ->assertOk()
            ->assertJsonPath('data.0', 'Quarter 1');

        $this->withUser($studentProfile->user)
            ->getJson('/api/student/dashboard?term=Quarter%201')
            ->assertOk()
            ->assertJsonPath('term', 'Quarter 1')
            ->assertJsonCount(1, 'courses');
    }

    public function test_student_report_card_only_covers_open_terms(): void
    {
        [, , $studentProfile] = $this->fixture();
        $this->openTerm('Quarter 1');
        $this->openTerm('Quarter 2');

        $this->withUser($studentProfile->user)
            ->getJson('/api/student/report-card')
            ->assertOk()
            ->assertJsonCount(2, 'terms')
            ->assertJsonPath('terms.0.term', 'Quarter 1')
            ->assertJsonPath('terms.1.term', 'Quarter 2');
    }

    public function test_admin_opens_and_closes_a_term(): void
    {
        $admin = $this->user('admin', 'admin@example.com');

        $this->withUser($admin)
            ->putJson('/api/term-windows', [
                'academic_year' => self::YEAR,
                'term' => 'Quarter 1',
                'is_open' => true,
            ])
            ->assertOk();

        $this->assertTrue(TermWindow::isOpen(self::YEAR, 'Quarter 1'));

        $this->withUser($admin)
            ->putJson('/api/term-windows', [
                'academic_year' => self::YEAR,
                'term' => 'Quarter 1',
                'is_open' => false,
            ])
            ->assertOk();

        $this->assertFalse(TermWindow::isOpen(self::YEAR, 'Quarter 1'));
    }

    public function test_term_window_list_returns_all_four_quarters(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $this->openTerm('Quarter 3');

        $this->withUser($admin)
            ->getJson('/api/term-windows?academic_year='.self::YEAR)
            ->assertOk()
            ->assertJsonCount(4, 'data.terms')
            ->assertJsonPath('data.terms.0.term', 'Quarter 1')
            ->assertJsonPath('data.terms.0.is_open', false)
            ->assertJsonPath('data.terms.2.term', 'Quarter 3')
            ->assertJsonPath('data.terms.2.is_open', true);
    }

    public function test_staff_without_permission_cannot_open_a_term(): void
    {
        $staff = $this->user('staff', 'staff@example.com');

        $this->withUser($staff)
            ->putJson('/api/term-windows', [
                'academic_year' => self::YEAR,
                'term' => 'Quarter 1',
                'is_open' => true,
            ])
            ->assertForbidden();
    }

    public function test_staff_with_admin_manage_grades_can_open_a_term(): void
    {
        $permission = Permission::create(['name' => 'admin_manage_grades', 'label' => 'Manage all grades']);
        $staff = $this->user('staff', 'staff@example.com');
        $staff->permissions()->sync([$permission->id]);

        $this->withUser($staff)
            ->putJson('/api/term-windows', [
                'academic_year' => self::YEAR,
                'term' => 'Quarter 1',
                'is_open' => true,
            ])
            ->assertOk();
    }

    private function openTerm(string $term): void
    {
        TermWindow::create([
            'academic_year' => self::YEAR,
            'term' => $term,
            'is_open' => true,
            'opened_at' => now(),
        ]);
    }

    private function term(): array
    {
        return ['term' => 'Quarter 1', 'academic_year' => self::YEAR];
    }

    private function payload(StudentProfile $studentProfile, GradingItem $item, float $score): array
    {
        return [
            ...$this->term(),
            'scores' => [[
                'student_profile_id' => $studentProfile->id,
                'grading_item_id' => $item->id,
                'score_obtained' => $score,
            ]],
        ];
    }

    /**
     * @return array{0: User, 1: GradingItem, 2: StudentProfile, 3: Course}
     */
    private function fixture(): array
    {
        $teacher = $this->user('teacher', 'teacher@example.com');
        $student = $this->user('student', 'student@example.com');

        $studentProfile = StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => 'S-1001',
            'grade_level' => 'G10',
        ]);

        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'G10-A',
            'class_name' => 'G10',
            'academic_year' => self::YEAR,
            'term' => 'Quarter 1',
        ]);

        $course = Course::create([
            'code' => 'MATH10',
            'name' => 'Mathematics 10',
            'grade_level' => 'G10',
            'class_section_id' => $section->id,
            'teacher_id' => $teacher->id,
        ]);

        Enrollment::create([
            'student_profile_id' => $studentProfile->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        $tier = $this->gradeTier('G7-12', 7, 12);
        $item = $this->gradingItem($this->gradingCategory($tier, 'Quiz', 100), 'Quiz 1', 10);

        return [$teacher, $item, $studentProfile->fresh(), $course];
    }

    /**
     * The guard caches its user for the life of the test app, so it must be
     * reset whenever a test switches between users.
     */
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
