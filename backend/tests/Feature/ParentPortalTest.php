<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\ParentGuardian;
use App\Models\ReportCardPublication;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\TermWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

class ParentPortalTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    private const YEAR = '2026-2027';

    public function test_parent_lists_their_own_children(): void
    {
        [$guardian] = $this->family(['Ali', 'Sara']);
        $this->openTerm('Quarter 1');

        $this->withUser($guardian->user)
            ->getJson('/api/parent/children')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.open_terms.0', 'Quarter 1');
    }

    public function test_parent_sees_their_childs_grades(): void
    {
        [$guardian, $children] = $this->family(['Ali']);
        $this->openTerm('Quarter 1');
        $this->score($children[0], 8);

        $this->withUser($guardian->user)
            ->getJson("/api/parent/children/{$children[0]->id}/grades?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('data.student.id', $children[0]->id)
            ->assertJsonCount(1, 'data.courses')
            ->assertJsonPath('data.courses.0.report.final_grade', 80);
    }

    public function test_parent_cannot_open_a_child_that_is_not_theirs(): void
    {
        [$guardian] = $this->family(['Ali']);
        [, $otherChildren] = $this->family(['Stranger'], 'other');
        $this->openTerm('Quarter 1');

        $this->withUser($guardian->user)
            ->getJson("/api/parent/children/{$otherChildren[0]->id}/grades?term=Quarter%201")
            ->assertForbidden();

        $this->withUser($guardian->user)
            ->getJson("/api/parent/children/{$otherChildren[0]->id}/report-cards")
            ->assertForbidden();
    }

    public function test_parent_cannot_see_a_closed_term(): void
    {
        [$guardian, $children] = $this->family(['Ali']);

        $this->withUser($guardian->user)
            ->getJson("/api/parent/children/{$children[0]->id}/grades?term=Quarter%201")
            ->assertForbidden();
    }

    public function test_parent_downloads_a_published_report_card(): void
    {
        [$guardian, $children, $section] = $this->family(['Ali']);
        $this->openTerm('Quarter 1');
        $publication = $this->publish($section, 'Quarter 1');

        $response = $this->withUser($guardian->user)
            ->get("/api/parent/children/{$children[0]->id}/report-cards/{$publication->id}/pdf")
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /**
     * Regression: the report card template dereferenced the teacher without a
     * null check, so any class holding an unassigned subject 500'd on download.
     */
    public function test_report_card_downloads_when_a_subject_has_no_teacher(): void
    {
        [$guardian, $children, $section] = $this->family(['Ali']);
        Course::create([
            'code' => 'ORPHAN10',
            'name' => 'Unassigned Subject',
            'grade_level' => 'G10',
            'class_section_id' => $section->id,
            'teacher_id' => null,
        ]);
        $this->openTerm('Quarter 1');
        $publication = $this->publish($section, 'Quarter 1');

        $response = $this->withUser($guardian->user)
            ->get("/api/parent/children/{$children[0]->id}/report-cards/{$publication->id}/pdf")
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_parent_cannot_download_a_report_card_for_another_class(): void
    {
        [$guardian, $children] = $this->family(['Ali']);
        [, , $otherSection] = $this->family(['Stranger'], 'other');
        $this->openTerm('Quarter 1');
        $publication = $this->publish($otherSection, 'Quarter 1');

        $this->withUser($guardian->user)
            ->get("/api/parent/children/{$children[0]->id}/report-cards/{$publication->id}/pdf")
            ->assertNotFound();
    }

    public function test_report_cards_list_only_shows_published_periods(): void
    {
        [$guardian, $children, $section] = $this->family(['Ali']);

        $this->withUser($guardian->user)
            ->getJson("/api/parent/children/{$children[0]->id}/report-cards")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->publish($section, 'Quarter 1');

        $this->withUser($guardian->user)
            ->getJson("/api/parent/children/{$children[0]->id}/report-cards")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.period', 'Quarter 1');
    }

    public function test_student_downloads_their_own_published_report_card(): void
    {
        [, $children, $section] = $this->family(['Ali']);
        $this->openTerm('Quarter 1');
        $publication = $this->publish($section, 'Quarter 1');

        $this->withUser($children[0]->user)
            ->get("/api/student/report-cards/{$publication->id}/pdf")
            ->assertOk();
    }

    public function test_non_parent_cannot_reach_the_parent_routes(): void
    {
        [, $children] = $this->family(['Ali']);
        $teacher = $this->user('teacher', 'teacher@example.com');

        $this->withUser($teacher)
            ->getJson('/api/parent/children')
            ->assertForbidden();

        $this->withUser($teacher)
            ->getJson("/api/parent/children/{$children[0]->id}/grades")
            ->assertForbidden();
    }

    public function test_parent_account_without_a_guardian_record_gets_404(): void
    {
        $orphan = $this->user('parent', 'orphan@example.com');

        $this->withUser($orphan)
            ->getJson('/api/parent/children')
            ->assertNotFound();
    }

    /**
     * A guardian, their children, and the class they all sit in.
     *
     * @return array{0: ParentGuardian, 1: array<int, StudentProfile>, 2: CourseSection}
     */
    private function family(array $names, string $prefix = 'fam'): array
    {
        $teacher = User::where('user_type', 'teacher')->first()
            ?? $this->user('teacher', $prefix.'.teacher@example.com');

        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => strtoupper($prefix).'-G10-A',
            'class_name' => 'G10',
            'academic_year' => self::YEAR,
            'term' => 'Quarter 1',
        ]);

        Course::create([
            'code' => strtoupper($prefix).'MATH10',
            'name' => 'Mathematics 10',
            'grade_level' => 'G10',
            'class_section_id' => $section->id,
            'teacher_id' => $teacher->id,
        ]);

        $guardianUser = $this->user('parent', $prefix.'.parent@example.com');
        $guardian = ParentGuardian::create([
            'user_id' => $guardianUser->id,
            'parent_admission_no' => 'P-'.strtoupper($prefix),
            'first_name' => 'Guardian',
            'full_name' => 'Guardian '.$prefix,
            'relation' => 'father',
            'mobile' => '0910000000',
        ]);

        $children = [];
        foreach ($names as $index => $name) {
            $childUser = $this->user('student', $prefix.'.'.strtolower($name).'@example.com');
            $child = StudentProfile::create([
                'user_id' => $childUser->id,
                'student_number' => 'S-'.strtoupper($prefix).'-'.$index,
                'full_name' => $name,
                'grade_level' => 'G10',
                'section_id' => $section->id,
                'status' => 'active',
            ]);

            Enrollment::create([
                'student_profile_id' => $child->id,
                'course_section_id' => $section->id,
                'status' => 'active',
            ]);

            $guardian->students()->attach($child->id, ['relation' => 'father', 'is_primary' => true]);
            $children[] = $child->fresh();
        }

        return [$guardian->fresh(), $children, $section];
    }

    private function score(StudentProfile $child, float $value): void
    {
        $course = Course::where('class_section_id', $child->section_id)->firstOrFail();
        $tier = $this->gradeTier('G7-12', 7, 12);
        $item = $this->gradingItem($this->gradingCategory($tier, 'Quiz', 100), 'Quiz 1', 10);

        StudentScore::create([
            'student_profile_id' => $child->id,
            'course_section_id' => $child->section_id,
            'course_id' => $course->id,
            'teacher_id' => $course->teacher_id,
            'grading_item_id' => $item->id,
            'term' => 'Quarter 1',
            'academic_year' => self::YEAR,
            'score_obtained' => $value,
            'max_score' => $item->max_score,
            'created_by' => $course->teacher_id,
        ]);
    }

    private function publish(CourseSection $section, string $period): ReportCardPublication
    {
        return ReportCardPublication::create([
            'course_section_id' => $section->id,
            'academic_year' => self::YEAR,
            'type' => ReportCardPublication::TYPE_QUARTER,
            'period' => $period,
            'published_at' => now(),
        ]);
    }

    private function openTerm(string $term): void
    {
        TermWindow::firstOrCreate(
            ['academic_year' => self::YEAR, 'term' => $term],
            ['is_open' => true, 'opened_at' => now()],
        );
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
