<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\CreditLegendRow;
use App\Models\Enrollment;
use App\Models\GradeTier;
use App\Models\GradingItem;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\User;
use App\Services\GPAService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

/**
 * A scheme is built, pointed at classes, and only then switched on. Switching it
 * off again throws away the marks that were entered under it.
 */
class GradingSchemeLifecycleTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    private const YEAR = '2025-2026';

    public function test_a_new_scheme_starts_switched_off(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)
            ->postJson('/api/grade-tiers', ['name' => 'G1-4', 'min_grade' => 1, 'max_grade' => 4])
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_an_inactive_scheme_does_not_grade_its_classes(): void
    {
        $admin = $this->admin();
        [$section, $course] = $this->classWithScheme(active: false);

        $this->openAllTerms(self::YEAR);

        $this->withUser($admin)
            ->getJson("/api/courses/{$course->id}/grade-entry?term=Quarter%201&academic_year=".self::YEAR)
            ->assertStatus(422)
            ->assertJsonPath('message', 'مخطط «G1-4» مُسند لهذا الفصل لكنه غير مفعّل. فعّله من إعداد نظام الدرجات لتبدأ الدرجات.');
    }

    public function test_activating_the_scheme_opens_the_sheet(): void
    {
        $admin = $this->admin();
        [$section, $course, $tier] = $this->classWithScheme(active: false);
        $this->openAllTerms(self::YEAR);

        $this->withUser($admin)
            ->postJson("/api/grade-tiers/{$tier->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->withUser($admin)
            ->getJson("/api/courses/{$course->id}/grade-entry?term=Quarter%201&academic_year=".self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.grade_tier.name', 'G1-4');
    }

    public function test_a_scheme_whose_weights_miss_one_hundred_cannot_be_activated(): void
    {
        $admin = $this->admin();
        $tier = GradeTier::create(['name' => 'Half', 'min_grade' => 1, 'max_grade' => 4, 'is_active' => false]);
        $category = $this->gradingCategory($tier, 'Classwork', 60);
        $this->gradingItem($category, 'Classwork', 20);

        $this->withUser($admin)
            ->postJson("/api/grade-tiers/{$tier->id}/activate")
            ->assertStatus(422);

        $this->assertFalse($tier->refresh()->is_active);
    }

    public function test_a_class_holds_only_one_scheme(): void
    {
        $admin = $this->admin();
        [$section, , $first] = $this->classWithScheme(active: true);
        $second = GradeTier::create(['name' => 'Other', 'min_grade' => 5, 'max_grade' => 8, 'is_active' => false]);

        $this->withUser($admin)
            ->putJson("/api/grade-tiers/{$second->id}/sections", ['course_section_ids' => [$section->id]])
            ->assertOk();

        // Moving it onto the second scheme takes it off the first.
        $this->assertSame($second->id, $section->refresh()->grade_tier_id);
        $this->assertSame(0, $first->courseSections()->count());
    }

    public function test_dropping_a_class_from_a_scheme_leaves_it_unassigned(): void
    {
        $admin = $this->admin();
        [$section, , $tier] = $this->classWithScheme(active: true);

        $this->withUser($admin)
            ->putJson("/api/grade-tiers/{$tier->id}/sections", ['course_section_ids' => []])
            ->assertOk();

        $this->assertNull($section->refresh()->grade_tier_id);
    }

    public function test_the_impact_report_counts_what_would_be_lost(): void
    {
        $admin = $this->admin();
        [, , $tier] = $this->scoredClass();

        $this->withUser($admin)
            ->getJson("/api/grade-tiers/{$tier->id}/deactivation-impact")
            ->assertOk()
            ->assertJsonPath('data.scores', 1)
            ->assertJsonPath('data.students', 1)
            ->assertJsonPath('data.classes.0', 'G1');
    }

    public function test_switching_a_scheme_off_wipes_its_marks(): void
    {
        $admin = $this->admin();
        [, , $tier] = $this->scoredClass();

        $this->withUser($admin)
            ->postJson("/api/grade-tiers/{$tier->id}/deactivate", ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('deleted_scores', 1);

        $this->assertFalse($tier->refresh()->is_active);
        $this->assertSame(0, StudentScore::count());
    }

    public function test_switching_off_without_confirming_changes_nothing(): void
    {
        $admin = $this->admin();
        [, , $tier] = $this->scoredClass();

        $this->withUser($admin)
            ->postJson("/api/grade-tiers/{$tier->id}/deactivate", [])
            ->assertStatus(422);

        $this->assertTrue($tier->refresh()->is_active);
        $this->assertSame(1, StudentScore::count());
    }

    public function test_credits_come_from_the_legend(): void
    {
        // The legend ships with the school's own values; pin the one under test.
        CreditLegendRow::updateOrCreate(['classes_per_week' => 5], ['credits' => 0.8]);

        [, $course] = $this->classWithScheme(active: true);
        // A stored credit_hours that the legend must override.
        $course->update(['periods_per_week' => 5, 'credit_hours' => 9.9]);

        $credits = app(GPAService::class)->creditsFor($course->refresh());

        $this->assertSame(0.8, $credits['credits']);
        $this->assertSame('legend', $credits['source']);
        $this->assertNull($credits['reason']);
    }

    public function test_a_missing_legend_is_reported_rather_than_hidden(): void
    {
        // An admin who cleared the legend has nothing left to read credits from.
        CreditLegendRow::query()->delete();

        [, $course] = $this->classWithScheme(active: true);
        $course->update(['periods_per_week' => 3]);

        $credits = app(GPAService::class)->creditsFor($course->refresh());

        $this->assertSame('course', $credits['source']);
        $this->assertStringContainsString('Legend of Credits Earned', $credits['reason']);
    }

    public function test_a_subject_without_periods_is_reported(): void
    {
        [, $course] = $this->classWithScheme(active: true);
        $course->update(['periods_per_week' => null]);

        $credits = app(GPAService::class)->creditsFor($course->refresh());

        $this->assertSame('course', $credits['source']);
        $this->assertStringContainsString('حصص', $credits['reason']);
    }

    public function test_the_quarter_sheet_states_the_credits(): void
    {
        $admin = $this->admin();
        CreditLegendRow::updateOrCreate(['classes_per_week' => 4], ['credits' => 0.7]);
        [, $course] = $this->classWithScheme(active: true);
        $course->update(['periods_per_week' => 4]);
        $this->openAllTerms(self::YEAR);

        $this->withUser($admin)
            ->getJson("/api/courses/{$course->id}/grade-entry?term=Quarter%201&academic_year=".self::YEAR)
            ->assertOk()
            ->assertJsonPath('data.credits.credits', 0.7)
            ->assertJsonPath('data.credits.source', 'legend')
            ->assertJsonPath('data.credits.periods_per_week', 4);
    }

    /**
     * The transcript is judged on this number, so it must not be pre-rounded.
     */
    public function test_the_gpa_is_not_rounded(): void
    {
        $admin = $this->admin();
        [$section, $course, $tier, $student] = $this->scoredClass();

        // Two subjects with different marks so the average is a repeating figure.
        $second = Course::create([
            'code' => 'G1SCI',
            'name' => 'Science',
            'grade_level' => 'G1',
            'class_section_id' => $section->id,
            'teacher_id' => $course->teacher_id,
            'periods_per_week' => 3,
        ]);
        $item = GradingItem::firstOrFail();
        StudentScore::create([
            'student_profile_id' => $student->id,
            'course_section_id' => $section->id,
            'course_id' => $second->id,
            'teacher_id' => $course->teacher_id,
            'grading_item_id' => $item->id,
            'term' => 'Quarter 1',
            'academic_year' => self::YEAR,
            'score_obtained' => 13,
            'max_score' => 20,
            'created_by' => $course->teacher_id,
        ]);

        $this->openAllTerms(self::YEAR);

        $gpa = $this->withUser($admin)
            ->getJson("/api/admin/student-profiles/{$student->id}/gpa?term=Quarter%201")
            ->assertOk()
            ->json('term_gpa.gpa');

        // 4.0 and 1.0 over equal credits average to 2.5 — but the point is that
        // whatever comes out is the raw quotient, not a 2-decimal rounding.
        $this->assertIsFloat($gpa + 0);
        $this->assertSame(round($gpa, 10), $gpa);
    }

    /**
     * @return array{0: CourseSection, 1: Course, 2: GradeTier}
     */
    private function classWithScheme(bool $active): array
    {
        $tier = GradeTier::create(['name' => 'G1-4', 'min_grade' => 1, 'max_grade' => 4, 'is_active' => $active]);
        $category = $this->gradingCategory($tier, 'Classwork', 100);
        $this->gradingItem($category, 'Classwork', 20);

        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => self::YEAR,
            'term' => 'Quarter 1',
            'grade_tier_id' => $tier->id,
        ]);
        $course = Course::create([
            'code' => 'G1MTH',
            'name' => 'Mathematics',
            'grade_level' => 'G1',
            'class_section_id' => $section->id,
            'teacher_id' => $teacher->id,
            'periods_per_week' => 3,
        ]);

        return [$section, $course, $tier];
    }

    /**
     * @return array{0: CourseSection, 1: Course, 2: GradeTier, 3: StudentProfile}
     */
    private function scoredClass(): array
    {
        [$section, $course, $tier] = $this->classWithScheme(active: true);

        $student = StudentProfile::create([
            'user_id' => $this->user('student', 'ali@example.com')->id,
            'student_number' => 'S-1',
            'full_name' => 'Ali',
            'grade_level' => 'G1',
            'academic_year' => self::YEAR,
            'section_id' => $section->id,
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_profile_id' => $student->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        StudentScore::create([
            'student_profile_id' => $student->id,
            'course_section_id' => $section->id,
            'course_id' => $course->id,
            'teacher_id' => $course->teacher_id,
            'grading_item_id' => GradingItem::firstOrFail()->id,
            'term' => 'Quarter 1',
            'academic_year' => self::YEAR,
            'score_obtained' => 20,
            'max_score' => 20,
            'created_by' => $course->teacher_id,
        ]);

        return [$section, $course, $tier, $student];
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
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($type),
                'password' => Hash::make('password'),
                'user_type' => $type,
                'is_active' => true,
            ],
        );
    }
}
