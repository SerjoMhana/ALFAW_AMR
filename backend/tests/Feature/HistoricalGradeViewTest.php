<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

class HistoricalGradeViewTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    private const LAST_YEAR = '2025-2026';

    private const THIS_YEAR = '2026-2027';

    public function test_last_years_class_still_lists_its_students_after_promotion(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        [$g1, $g1Course, $student] = $this->scoredLastYear();
        $this->promoteToNextYear($student, $admin);

        // The student now sits in G2, but last year's G1 sheet must still show them.
        $this->withUser($admin)
            ->getJson("/api/courses/{$g1Course->id}/grade-entry?term=Quarter%201&academic_year=".self::LAST_YEAR)
            ->assertOk()
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.students.0.student_profile.id', $student->id);

        $this->assertSame('G2', $student->refresh()->grade_level);
        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $student->id,
            'course_section_id' => $g1->id,
            'status' => 'completed',
        ]);
    }

    public function test_last_years_grades_are_unchanged_by_the_promotion(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        [, $g1Course, $student] = $this->scoredLastYear();
        $this->promoteToNextYear($student, $admin);

        $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/courses/{$g1Course->id}/grade-report?term=Quarter%201&academic_year=".self::LAST_YEAR)
            ->assertOk()
            ->assertJsonPath('data.academic_year', self::LAST_YEAR)
            ->assertJsonPath('data.final_grade', 90);
    }

    public function test_the_new_year_starts_with_no_grades_for_the_promoted_student(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        [, , $student, $g2Course] = $this->scoredLastYear();
        $this->promoteToNextYear($student, $admin);

        $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/courses/{$g2Course->id}/grade-report?term=Quarter%201&academic_year=".self::THIS_YEAR)
            ->assertOk()
            ->assertJsonPath('data.final_grade', 0);
    }

    public function test_a_graduated_students_final_year_remains_readable(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g12 = $this->section('G12', self::LAST_YEAR);
        $course = $this->course('G12MTH', $g12);
        $student = $this->student('Omar', $g12);
        $this->score($student, $course, $g12, 'Quarter 1', 90);

        $this->withUser($admin)->postJson('/api/promotions/apply', [
            'course_section_id' => $g12->id,
            'target_academic_year' => self::THIS_YEAR,
        ])->assertOk();

        $this->assertTrue($student->refresh()->is_archived);

        $this->withUser($admin)
            ->getJson("/api/courses/{$course->id}/grade-entry?term=Quarter%201&academic_year=".self::LAST_YEAR)
            ->assertOk()
            ->assertJsonCount(1, 'data.students')
            ->assertJsonPath('data.students.0.student_profile.id', $student->id);
    }

    public function test_students_who_left_the_school_are_excluded(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        [, $g1Course, $student] = $this->scoredLastYear();
        Enrollment::where('student_profile_id', $student->id)->update(['status' => 'dropped']);

        $this->withUser($admin)
            ->getJson("/api/courses/{$g1Course->id}/grade-entry?term=Quarter%201&academic_year=".self::LAST_YEAR)
            ->assertOk()
            ->assertJsonCount(0, 'data.students');
    }

    public function test_each_grade_level_keeps_its_own_gpa(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g10 = $this->section('G10', self::LAST_YEAR);
        $g11 = $this->section('G11', self::THIS_YEAR);
        $g10Course = $this->course('G10MTH', $g10);
        $g11Course = $this->course('G11MTH', $g11);
        $student = $this->student('Ali', $g10);

        // A strong year in G10, then a weak one in G11.
        $this->score($student, $g10Course, $g10, 'Quarter 1', 95);
        $this->promoteToNextYear($student, $admin);
        $this->score($student, $g11Course, $g11, 'Quarter 1', 65);

        // G11 is the grade the student is in now, judged only on G11 work.
        $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/gpa?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('term_gpa.grade_level', 'G11')
            ->assertJsonPath('term_gpa.academic_year', self::THIS_YEAR)
            ->assertJsonPath('term_gpa.gpa', 1.3)
            ->assertJsonCount(1, 'term_gpa.courses');

        // G10 keeps its own figure, untouched by the weaker G11 year.
        $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/gpa?term=Quarter%201&academic_year=".self::LAST_YEAR)
            ->assertOk()
            ->assertJsonPath('term_gpa.grade_level', 'G10')
            ->assertJsonPath('term_gpa.academic_year', self::LAST_YEAR)
            ->assertJsonPath('term_gpa.gpa', 4)
            ->assertJsonCount(1, 'term_gpa.courses');
    }

    /**
     * GPA covers every grade, so a primary class carries one too — and it stays
     * attached to that grade after the student moves up.
     */
    public function test_a_primary_grade_keeps_its_own_gpa_after_promotion(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $g1 = $this->section('G1', self::LAST_YEAR);
        $g2 = $this->section('G2', self::THIS_YEAR);
        $g1Course = $this->course('G1MTH', $g1);
        $this->course('G2MTH', $g2);
        $student = $this->student('Ali', $g1);

        $this->score($student, $g1Course, $g1, 'Quarter 1', 95);
        $this->promoteToNextYear($student, $admin);

        $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/gpa?term=Quarter%201&academic_year=".self::LAST_YEAR)
            ->assertOk()
            ->assertJsonPath('term_gpa.grade_level', 'G1')
            ->assertJsonPath('term_gpa.academic_year', self::LAST_YEAR)
            ->assertJsonPath('term_gpa.gpa', 4);

        // The new grade starts clean rather than inheriting the G1 figure.
        $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/gpa?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('term_gpa.grade_level', 'G2')
            ->assertJsonPath('term_gpa.gpa', null);
    }

    public function test_activating_a_year_deactivates_the_others(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $last = AcademicYear::create(['name' => self::LAST_YEAR, 'is_active' => false]);
        $current = AcademicYear::create(['name' => self::THIS_YEAR, 'is_active' => true]);

        $this->withUser($admin)
            ->putJson("/api/academic-years/{$last->id}/activate")
            ->assertOk();

        $this->assertTrue((bool) $last->refresh()->is_active);
        $this->assertFalse((bool) $current->refresh()->is_active);
        $this->assertSame(1, AcademicYear::where('is_active', true)->count());
    }

    public function test_creating_an_active_year_deactivates_the_previous_one(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $old = AcademicYear::create(['name' => self::LAST_YEAR, 'is_active' => true]);

        $this->withUser($admin)
            ->postJson('/api/academic-years', ['name' => self::THIS_YEAR, 'is_active' => true])
            ->assertCreated();

        $this->assertFalse((bool) $old->refresh()->is_active);
        $this->assertSame(1, AcademicYear::where('is_active', true)->count());
    }

    public function test_staff_without_settings_manage_cannot_switch_the_year(): void
    {
        $view = Permission::create(['name' => 'settings.view', 'label' => 'View settings']);
        $staff = $this->user('staff', 'staff@example.com');
        $staff->permissions()->sync([$view->id]);
        $year = AcademicYear::create(['name' => self::LAST_YEAR, 'is_active' => false]);

        $this->withUser($staff)
            ->putJson("/api/academic-years/{$year->id}/activate")
            ->assertForbidden();

        $this->assertFalse((bool) $year->refresh()->is_active);
    }

    public function test_staff_with_settings_manage_can_switch_the_year(): void
    {
        $manage = Permission::create(['name' => 'settings.manage', 'label' => 'Manage settings']);
        $staff = $this->user('staff', 'staff@example.com');
        $staff->permissions()->sync([$manage->id]);
        $year = AcademicYear::create(['name' => self::LAST_YEAR, 'is_active' => false]);

        $this->withUser($staff)
            ->putJson("/api/academic-years/{$year->id}/activate")
            ->assertOk();

        $this->assertTrue((bool) $year->refresh()->is_active);
    }

    /**
     * A G1 class last year with a scored subject, plus this year's G2 to move into.
     *
     * @return array{0: CourseSection, 1: Course, 2: StudentProfile, 3: Course}
     */
    private function scoredLastYear(): array
    {
        $g1 = $this->section('G1', self::LAST_YEAR);
        $g2 = $this->section('G2', self::THIS_YEAR);
        $g1Course = $this->course('G1MTH', $g1);
        $g2Course = $this->course('G2MTH', $g2);
        $student = $this->student('Ali', $g1);
        $this->score($student, $g1Course, $g1, 'Quarter 1', 90);

        return [$g1, $g1Course, $student, $g2Course];
    }

    private function promoteToNextYear(StudentProfile $student, User $admin): void
    {
        $this->withUser($admin)->postJson('/api/promotions/apply', [
            'course_section_id' => $student->section_id,
            'target_academic_year' => self::THIS_YEAR,
        ])->assertOk();
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

    private function score(
        StudentProfile $student,
        Course $course,
        CourseSection $section,
        string $term,
        float $value,
    ): void {
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
            'term' => $term,
            'academic_year' => $section->academic_year,
            'score_obtained' => $value,
            'max_score' => $item->max_score,
            'created_by' => $course->teacher_id,
        ]);
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
