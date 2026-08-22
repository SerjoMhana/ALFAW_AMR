<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GradingItem;
use App\Models\Permission;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

class GradeSubmissionLockTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    public function test_sheet_starts_unlocked_and_editable(): void
    {
        [$teacher, , , $course] = $this->courseWithStudent();

        $this->withUser($teacher)
            ->getJson($this->sheetUrl($course))
            ->assertOk()
            ->assertJsonPath('data.submission.status', 'draft')
            ->assertJsonPath('data.submission.is_locked', false)
            ->assertJsonPath('data.submission.can_edit', true)
            ->assertJsonPath('data.submission.can_submit', true);
    }

    public function test_teacher_can_save_repeatedly_before_submitting(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->courseWithStudent();

        foreach ([5, 7] as $score) {
            $this->withUser($teacher)
                ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, $score))
                ->assertOk()
                ->assertJsonPath('submission.is_locked', false);
        }

        $this->assertDatabaseHas('student_scores', [
            'course_id' => $course->id,
            'student_profile_id' => $studentProfile->id,
            'score_obtained' => 7,
        ]);
    }

    public function test_teacher_cannot_edit_after_submitting(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->courseWithStudent();

        $this->withUser($teacher)
            ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, 8))
            ->assertOk();

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.is_locked', true)
            ->assertJsonPath('data.can_submit', false)
            ->assertJsonPath('data.can_unlock', false);

        $this->withUser($teacher)
            ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, 10))
            ->assertForbidden();

        $this->assertDatabaseHas('student_scores', [
            'course_id' => $course->id,
            'score_obtained' => 8,
        ]);
    }

    public function test_submitted_sheet_reports_locked_state_to_the_teacher(): void
    {
        [$teacher, , , $course] = $this->courseWithStudent();

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertOk();

        $this->withUser($teacher)
            ->getJson($this->sheetUrl($course))
            ->assertOk()
            ->assertJsonPath('data.submission.is_locked', true)
            ->assertJsonPath('data.submission.can_edit', false)
            ->assertJsonPath('data.submission.submitted_by', $teacher->name);
    }

    public function test_admin_can_still_edit_and_unlock_a_submitted_sheet(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->courseWithStudent();
        $admin = $this->user('admin', 'admin@example.com');

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertOk();

        $this->withUser($admin)
            ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, 9))
            ->assertOk();

        $this->withUser($admin)
            ->postJson("/api/courses/{$course->id}/grade-entry/unlock", $this->term())
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_locked', false);

        $this->assertDatabaseHas('grade_submissions', [
            'course_id' => $course->id,
            'status' => 'draft',
            'unlocked_by' => $admin->id,
        ]);
    }

    public function test_teacher_can_edit_again_after_admin_unlocks(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->courseWithStudent();
        $admin = $this->user('admin', 'admin@example.com');

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertOk();

        $this->withUser($teacher)
            ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, 6))
            ->assertForbidden();

        $this->withUser($admin)
            ->postJson("/api/courses/{$course->id}/grade-entry/unlock", $this->term())
            ->assertOk();

        $this->withUser($teacher)
            ->putJson("/api/courses/{$course->id}/grade-entry", $this->payload($studentProfile, $item, 6))
            ->assertOk();
    }

    public function test_teacher_cannot_unlock_their_own_sheet(): void
    {
        [$teacher, , , $course] = $this->courseWithStudent();

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertOk();

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/unlock", $this->term())
            ->assertForbidden();
    }

    public function test_staff_with_admin_manage_grades_can_unlock(): void
    {
        [$teacher, , , $course] = $this->courseWithStudent();
        $permission = Permission::create(['name' => 'admin_manage_grades', 'label' => 'Manage all grades']);
        $staff = $this->user('staff', 'staff@example.com');
        $staff->permissions()->sync([$permission->id]);

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertOk();

        $this->withUser($staff)
            ->postJson("/api/courses/{$course->id}/grade-entry/unlock", $this->term())
            ->assertOk()
            ->assertJsonPath('data.is_locked', false);
    }

    public function test_locking_is_scoped_to_the_submitted_term(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->courseWithStudent();

        $this->withUser($teacher)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertOk();

        $this->withUser($teacher)
            ->putJson("/api/courses/{$course->id}/grade-entry", [
                ...$this->payload($studentProfile, $item, 7),
                'term' => 'Quarter 2',
            ])
            ->assertOk();
    }

    public function test_unassigned_teacher_cannot_submit(): void
    {
        [, , , $course] = $this->courseWithStudent();
        $other = $this->user('teacher', 'other@example.com');

        $this->withUser($other)
            ->postJson("/api/courses/{$course->id}/grade-entry/submit", $this->term())
            ->assertForbidden();
    }

    /**
     * Authenticate the next request as $user.
     *
     * The guard caches its resolved user for the lifetime of the test application,
     * so it must be reset whenever a test switches between users.
     */
    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function sheetUrl(Course $course): string
    {
        return "/api/courses/{$course->id}/grade-entry?term=Quarter%201&academic_year=2026-2027";
    }

    private function term(): array
    {
        return ['term' => 'Quarter 1', 'academic_year' => '2026-2027'];
    }

    private function payload(StudentProfile $studentProfile, GradingItem $item, float $score): array
    {
        return [
            ...$this->term(),
            'scores' => [
                [
                    'student_profile_id' => $studentProfile->id,
                    'grading_item_id' => $item->id,
                    'score_obtained' => $score,
                ],
            ],
        ];
    }

    /**
     * @return array{0: User, 1: GradingItem, 2: StudentProfile, 3: Course}
     */
    private function courseWithStudent(): array
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
            'academic_year' => '2026-2027',
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
        $category = $this->gradingCategory($tier, 'Quiz', 100);
        $item = $this->gradingItem($category, 'Quiz 1', 10);

        $this->openAllTerms('2026-2027');

        return [$teacher, $item, $studentProfile, $course];
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
