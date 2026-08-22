<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

/**
 * The school works in one year at a time. Switching the active year rewinds
 * what everyone sees — the roster, each student's grade, and their marks — and
 * switching back brings the present state back untouched.
 */
class AcademicYearSwitchingTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    private const LAST_YEAR = '2024-2025';

    private const THIS_YEAR = '2025-2026';

    public function test_only_one_year_is_active_at_a_time(): void
    {
        $admin = $this->admin();
        $last = AcademicYear::create(['name' => self::LAST_YEAR, 'is_active' => true]);
        $this->withUser($admin)
            ->postJson('/api/academic-years', ['name' => self::THIS_YEAR])
            ->assertCreated();

        $this->withUser($admin)
            ->putJson("/api/academic-years/{$last->id}/activate")
            ->assertOk();

        $this->assertSame(1, AcademicYear::query()->active()->count());
        $this->assertSame(self::LAST_YEAR, AcademicYear::currentName());
    }

    /**
     * The update route used to be able to flip is_active on its own, which is
     * how a database ended up with several active years at once.
     */
    public function test_updating_a_year_cannot_leave_two_years_active(): void
    {
        $admin = $this->admin();
        $current = AcademicYear::create(['name' => self::THIS_YEAR, 'is_active' => true]);
        $other = AcademicYear::create(['name' => self::LAST_YEAR, 'is_active' => false]);

        $this->withUser($admin)
            ->putJson("/api/academic-years/{$other->id}", ['is_active' => true])
            ->assertOk();

        $this->assertTrue($other->refresh()->is_active);
        $this->assertFalse($current->refresh()->is_active);
        $this->assertSame(1, AcademicYear::query()->active()->count());
    }

    public function test_the_active_year_cannot_be_switched_off_leaving_none(): void
    {
        $admin = $this->admin();
        $current = AcademicYear::create(['name' => self::THIS_YEAR, 'is_active' => true]);

        $this->withUser($admin)
            ->putJson("/api/academic-years/{$current->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->assertTrue($current->refresh()->is_active);
    }

    public function test_the_active_year_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $current = AcademicYear::create(['name' => self::THIS_YEAR, 'is_active' => true]);

        $this->withUser($admin)
            ->deleteJson("/api/academic-years/{$current->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('academic_years', ['id' => $current->id]);
    }

    public function test_the_first_year_is_always_activated(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)
            ->postJson('/api/academic-years', ['name' => self::THIS_YEAR, 'is_active' => false])
            ->assertCreated();

        $this->assertSame(self::THIS_YEAR, AcademicYear::currentName());
    }

    public function test_the_student_list_shows_last_years_grade_while_last_year_is_active(): void
    {
        $admin = $this->admin();
        [$student] = $this->promotedStudent($admin);

        $this->activateYear($admin, self::LAST_YEAR);

        $this->withUser($admin)
            ->getJson('/api/students')
            ->assertOk()
            ->assertJsonPath('academic_year', self::LAST_YEAR)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.grade_level', 'G1')
            ->assertJsonPath('data.0.academic_year', self::LAST_YEAR);

        // The profile itself is untouched — only the presented year changed.
        $this->assertSame('G2', $student->refresh()->grade_level);
    }

    public function test_switching_back_shows_the_current_grade_again(): void
    {
        $admin = $this->admin();
        [$student] = $this->promotedStudent($admin);

        $this->activateYear($admin, self::LAST_YEAR);
        $this->activateYear($admin, self::THIS_YEAR);

        $this->withUser($admin)
            ->getJson('/api/students')
            ->assertOk()
            ->assertJsonPath('academic_year', self::THIS_YEAR)
            ->assertJsonPath('data.0.grade_level', 'G2')
            ->assertJsonPath('data.0.academic_year', self::THIS_YEAR);
    }

    public function test_a_student_who_joined_this_year_is_absent_from_last_year(): void
    {
        $admin = $this->admin();
        $this->promotedStudent($admin);
        $g1ThisYear = $this->section('G1', self::THIS_YEAR);
        $newcomer = $this->student('Sara', $g1ThisYear);

        $this->activateYear($admin, self::LAST_YEAR);

        $response = $this->withUser($admin)->getJson('/api/students')->assertOk();
        $this->assertNotContains($newcomer->id, array_column($response->json('data'), 'id'));

        $this->activateYear($admin, self::THIS_YEAR);

        $response = $this->withUser($admin)->getJson('/api/students')->assertOk();
        $this->assertContains($newcomer->id, array_column($response->json('data'), 'id'));
    }

    public function test_grades_follow_the_year_that_is_active(): void
    {
        $admin = $this->admin();
        [$student, $g1Course, $g2Course] = $this->promotedStudent($admin);

        // Last year's mark stays readable and belongs to last year's subject.
        $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/courses/{$g1Course->id}/grade-report?term=Quarter%201&academic_year=".self::LAST_YEAR)
            ->assertOk()
            ->assertJsonPath('data.final_grade', 90);

        // This year's subject carries its own, separate mark.
        $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/courses/{$g2Course->id}/grade-report?term=Quarter%201&academic_year=".self::THIS_YEAR)
            ->assertOk()
            ->assertJsonPath('data.final_grade', 60);
    }

    public function test_a_student_with_no_class_still_appears_in_their_own_year(): void
    {
        $admin = $this->admin();
        AcademicYear::create(['name' => self::THIS_YEAR, 'is_active' => true]);

        $user = $this->user('student', 'loner@example.com');
        $student = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'S-LONER',
            'full_name' => 'Loner',
            'grade_level' => 'G5',
            'academic_year' => self::THIS_YEAR,
            'status' => 'active',
        ]);

        $this->withUser($admin)
            ->getJson('/api/students')
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.grade_level', 'G5');
    }

    /**
     * A student scored in G1 last year, moved up to G2, and scored again there.
     *
     * @return array{0: StudentProfile, 1: Course, 2: Course}
     */
    private function promotedStudent(User $admin): array
    {
        AcademicYear::create(['name' => self::LAST_YEAR, 'is_active' => false]);
        AcademicYear::create(['name' => self::THIS_YEAR, 'is_active' => true]);

        $g1 = $this->section('G1', self::LAST_YEAR);
        $g2 = $this->section('G2', self::THIS_YEAR);
        $g1Course = $this->course('G1MTH', $g1);
        $g2Course = $this->course('G2MTH', $g2);
        $student = $this->student('Ali', $g1);

        $this->score($student, $g1Course, $g1, 90);

        $this->withUser($admin)->postJson('/api/promotions/apply', [
            'course_section_id' => $g1->id,
            'target_academic_year' => self::THIS_YEAR,
        ])->assertOk();

        $this->score($student->refresh(), $g2Course, $g2, 60);

        return [$student->refresh(), $g1Course, $g2Course];
    }

    private function activateYear(User $admin, string $name): void
    {
        $year = AcademicYear::where('name', $name)->firstOrFail();

        $this->withUser($admin)
            ->putJson("/api/academic-years/{$year->id}/activate")
            ->assertOk();
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

    private function course(string $code, CourseSection $section): Course
    {
        return Course::create([
            'code' => $code,
            'name' => 'Mathematics',
            'grade_level' => $section->class_name,
            'class_section_id' => $section->id,
            'teacher_id' => $section->teacher_id,
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

    private function score(StudentProfile $student, Course $course, CourseSection $section, float $value): void
    {
        $grade = (int) filter_var($section->class_name, FILTER_SANITIZE_NUMBER_INT);
        $tier = $grade >= 7
            ? $this->gradeTier('G7-12', 7, 12)
            : $this->gradeTier('G1-6', 1, 6);
        $item = $this->gradingItem($this->gradingCategory($tier, 'Exam', 100), $course->code.' Exam', 100);

        StudentScore::create([
            'student_profile_id' => $student->id,
            'course_section_id' => $section->id,
            'course_id' => $course->id,
            'teacher_id' => $course->teacher_id,
            'grading_item_id' => $item->id,
            'term' => 'Quarter 1',
            'academic_year' => $section->academic_year,
            'score_obtained' => $value,
            'max_score' => $item->max_score,
            'created_by' => $course->teacher_id,
        ]);
    }

    private function admin(): User
    {
        return $this->user('admin', 'admin@example.com');
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
