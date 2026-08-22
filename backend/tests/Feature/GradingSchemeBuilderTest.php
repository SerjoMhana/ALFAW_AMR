<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\GradeTier;
use App\Models\GradingCategory;
use App\Models\GradingItem;
use App\Models\Permission;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

/**
 * The admin builds the school's own grade split: schemes covering a range of
 * grades, categories carrying the weights, and items the teacher enters out of.
 */
class GradingSchemeBuilderTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    public function test_admin_creates_a_scheme_and_fills_it_in(): void
    {
        $admin = $this->admin();

        $tier = $this->withUser($admin)
            ->postJson('/api/grade-tiers', ['name' => 'G1-4', 'min_grade' => 1, 'max_grade' => 4])
            ->assertCreated()
            ->json('data');

        $this->withUser($admin)
            ->postJson('/api/grading-categories', [
                'grade_tier_id' => $tier['id'],
                'name' => 'Quizzes',
                'weight_percentage' => 20,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Quizzes');

        $category = GradingCategory::where('grade_tier_id', $tier['id'])->firstOrFail();

        $this->withUser($admin)
            ->postJson('/api/grading-items', [
                'grading_category_id' => $category->id,
                'name' => 'Quiz 1',
                'max_score' => 5,
            ])
            ->assertCreated();

        $this->assertSame(1, GradingItem::where('grading_category_id', $category->id)->count());
    }

    /**
     * Two schemes covering the same grade would make the lookup ambiguous, so a
     * grade can only ever belong to one.
     */
    public function test_a_scheme_cannot_overlap_another(): void
    {
        $admin = $this->admin();
        $this->gradeTier('G1-4', 1, 4);

        $this->withUser($admin)
            ->postJson('/api/grade-tiers', ['name' => 'Primary', 'min_grade' => 4, 'max_grade' => 6])
            ->assertStatus(422);

        $this->assertSame(1, GradeTier::count());
    }

    public function test_a_scheme_can_be_re_ranged_when_nothing_clashes(): void
    {
        $admin = $this->admin();
        $tier = $this->gradeTier('G1-4', 1, 4);
        $this->gradeTier('G9-12', 9, 12);

        $this->withUser($admin)
            ->putJson("/api/grade-tiers/{$tier->id}", ['min_grade' => 1, 'max_grade' => 6])
            ->assertOk();

        $this->assertSame(6, $tier->refresh()->max_grade);

        // ...but not onto a grade another scheme already owns.
        $this->withUser($admin)
            ->putJson("/api/grade-tiers/{$tier->id}", ['min_grade' => 1, 'max_grade' => 10])
            ->assertStatus(422);

        $this->assertSame(6, $tier->refresh()->max_grade);
    }

    public function test_category_weights_cannot_exceed_one_hundred(): void
    {
        $admin = $this->admin();
        $tier = $this->gradeTier('G1-4', 1, 4);
        $this->gradingCategory($tier, 'Classwork', 80);

        $this->withUser($admin)
            ->postJson('/api/grading-categories', [
                'grade_tier_id' => $tier->id,
                'name' => 'Final',
                'weight_percentage' => 30,
            ])
            ->assertStatus(422);

        $this->assertSame(1, GradingCategory::where('grade_tier_id', $tier->id)->count());
    }

    public function test_a_scheme_holding_recorded_marks_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        [$tier] = $this->scoredScheme();

        $this->withUser($admin)
            ->deleteJson("/api/grade-tiers/{$tier->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('grade_tiers', ['id' => $tier->id]);
    }

    public function test_an_untouched_scheme_is_deleted_outright(): void
    {
        $admin = $this->admin();
        $tier = $this->gradeTier('G1-4', 1, 4);
        $category = $this->gradingCategory($tier, 'Classwork', 100);
        $this->gradingItem($category, 'Classwork', 20);

        $this->withUser($admin)
            ->deleteJson("/api/grade-tiers/{$tier->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('grade_tiers', ['id' => $tier->id]);
    }

    /**
     * Deleting a scored category would take the marks with it, so it is retired
     * instead — it stops counting towards the 100 but the history survives.
     */
    public function test_a_scored_category_is_retired_rather_than_deleted(): void
    {
        $admin = $this->admin();
        [, $category] = $this->scoredScheme();

        $this->withUser($admin)
            ->deleteJson("/api/grading-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('grading_categories', ['id' => $category->id]);
        $this->assertSame(1, StudentScore::count());
    }

    public function test_an_unscored_category_is_deleted_with_its_items(): void
    {
        $admin = $this->admin();
        $tier = $this->gradeTier('G1-4', 1, 4);
        $category = $this->gradingCategory($tier, 'Classwork', 100);
        $this->gradingItem($category, 'Classwork', 20);

        $this->withUser($admin)
            ->deleteJson("/api/grading-categories/{$category->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('grading_categories', ['id' => $category->id]);
        $this->assertSame(0, GradingItem::count());
    }

    public function test_the_school_preset_builds_the_whole_scheme(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)
            ->postJson('/api/grading-structure/presets', ['key' => 'vis-g9-12'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'G9-12');

        $tier = GradeTier::where('name', 'G9-12')->firstOrFail();
        $categories = GradingCategory::where('grade_tier_id', $tier->id)->get();

        $this->assertSame(6, $categories->count());
        $this->assertEqualsWithDelta(100.0, (float) $categories->sum('weight_percentage'), 0.001);

        // The quarter final carries 40% and is marked out of 40, per the sheet.
        $final = $categories->firstWhere('name', 'Quarter Final');
        $this->assertEqualsWithDelta(40.0, (float) $final->weight_percentage, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $final->items()->first()->max_score, 0.001);
    }

    public function test_a_preset_that_would_overlap_is_refused(): void
    {
        $admin = $this->admin();
        $this->gradeTier('Existing', 10, 11);

        $this->withUser($admin)
            ->postJson('/api/grading-structure/presets', ['key' => 'vis-g9-12'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('grade_tiers', ['name' => 'G9-12']);
    }

    public function test_the_preset_list_flags_what_already_clashes(): void
    {
        $admin = $this->admin();
        $this->gradeTier('Existing', 1, 2);

        $response = $this->withUser($admin)->getJson('/api/grading-structure/presets')->assertOk();

        $byKey = collect($response->json('data'))->keyBy('key');
        $this->assertSame('Existing', $byKey['vis-g1-4']['conflict']);
        $this->assertNull($byKey['vis-g9-12']['conflict']);
    }

    public function test_the_scheme_is_matched_by_range_not_by_name(): void
    {
        // A name that looks like another range must not win over the real range.
        $this->gradeTier('G1-4', 9, 12);

        $this->assertSame('G1-4', GradeTier::forNumericGrade(11)?->name);
        $this->assertNull(GradeTier::forNumericGrade(3));
    }

    public function test_staff_without_permission_cannot_touch_schemes(): void
    {
        $staff = $this->user('staff', 'staff@example.com');

        $this->withUser($staff)
            ->postJson('/api/grade-tiers', ['name' => 'G1-4', 'min_grade' => 1, 'max_grade' => 4])
            ->assertForbidden();

        $this->assertSame(0, GradeTier::count());
    }

    /**
     * A scheme with one mark already entered against it.
     *
     * @return array{0: GradeTier, 1: GradingCategory}
     */
    private function scoredScheme(): array
    {
        $tier = $this->gradeTier('G1-4', 1, 4);
        $category = $this->gradingCategory($tier, 'Classwork', 100);
        $item = $this->gradingItem($category, 'Classwork', 20);

        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => '2025-2026',
            'term' => 'Quarter 1',
        ]);
        $course = Course::create([
            'code' => 'G1MTH',
            'name' => 'Mathematics',
            'grade_level' => 'G1',
            'class_section_id' => $section->id,
            'teacher_id' => $teacher->id,
        ]);
        $student = StudentProfile::create([
            'user_id' => $this->user('student', 'ali@example.com')->id,
            'student_number' => 'S-1',
            'full_name' => 'Ali',
            'grade_level' => 'G1',
            'academic_year' => '2025-2026',
            'section_id' => $section->id,
            'status' => 'active',
        ]);

        StudentScore::create([
            'student_profile_id' => $student->id,
            'course_section_id' => $section->id,
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'grading_item_id' => $item->id,
            'term' => 'Quarter 1',
            'academic_year' => '2025-2026',
            'score_obtained' => 18,
            'max_score' => 20,
            'created_by' => $teacher->id,
        ]);

        return [$tier, $category];
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
