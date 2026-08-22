<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GradeTier;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

class PhaseEightStudentDashboardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGradingFixtures;

    public function test_student_dashboard_shows_only_own_courses_scores_breakdown_and_gpa(): void
    {
        $tier = $this->seedTier();
        [$student, $profile] = $this->student('student@example.com', 'S-1001', 'G10');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = $this->enrolledSection($profile, $teacher);

        $this->scoredEnrollment($tier, $profile, $teacher, $section, 'APBIO', 'AP Biology', true, 1.5, 'Quiz', 'Quiz 1', 18, 20);
        $this->scoredEnrollment($tier, $profile, $teacher, $section, 'ENG10', 'English 10', false, 1, 'Homework', 'Homework 1', 9, 10);

        $this->withToken($student->createToken('test')->plainTextToken)
            ->getJson('/api/student/dashboard?term=Quarter%201')
            ->assertOk()
            ->assertJsonCount(2, 'courses')
            ->assertJsonPath('courses.0.report.category_breakdown.2.items.0.name', 'APBIO Quiz 1')
            ->assertJsonPath('gpa.term.total_credit_hours', 2.5)
            ->assertJsonPath('gpa.term.gpa', 4.3);
    }

    public function test_student_dashboard_does_not_show_other_students_courses(): void
    {
        $tier = $this->seedTier();
        [, $profile] = $this->student('student@example.com', 'S-1001', 'G10');
        [$otherStudent] = $this->student('other@example.com', 'S-2002', 'G10');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = $this->enrolledSection($profile, $teacher);
        $this->scoredEnrollment($tier, $profile, $teacher, $section, 'APBIO', 'AP Biology', true, 1.5, 'Quiz', 'Quiz 1', 18, 20);

        $this->withToken($otherStudent->createToken('test')->plainTextToken)
            ->getJson('/api/student/dashboard?term=Quarter%201')
            ->assertOk()
            ->assertJsonCount(0, 'courses');
    }

    public function test_report_card_returns_a_gpa_per_term_and_no_yearly_total(): void
    {
        $tier = $this->seedTier();
        [$student, $profile] = $this->student('student@example.com', 'S-1001', 'G10');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = $this->enrolledSection($profile, $teacher);
        $this->scoredEnrollment($tier, $profile, $teacher, $section, 'ENG10', 'English 10', false, 1, 'Exam', 'Exam', 95, 100);

        $this->withToken($student->createToken('test')->plainTextToken)
            ->getJson('/api/student/report-card')
            ->assertOk()
            ->assertJsonCount(4, 'terms')
            ->assertJsonPath('terms.0.term', 'Quarter 1')
            ->assertJsonPath('terms.0.gpa.term', 'Quarter 1')
            ->assertJsonMissingPath('yearly_summary');
    }

    private function seedTier(): GradeTier
    {
        $tier = $this->gradeTier('G7-12', 7, 12);
        $this->gradingCategory($tier, 'Exam', 100, 0);
        $this->gradingCategory($tier, 'Homework', 100, 1);
        $this->gradingCategory($tier, 'Quiz', 100, 2);

        return $tier;
    }

    private function enrolledSection(StudentProfile $profile, User $teacher): CourseSection
    {
        $this->openAllTerms('2026-2027');

        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => $profile->grade_level.'-A-'.$profile->id,
            'class_name' => $profile->grade_level.'-A',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        return $section;
    }

    private function scoredEnrollment(
        GradeTier $tier,
        StudentProfile $profile,
        User $teacher,
        CourseSection $section,
        string $code,
        string $name,
        bool $isAp,
        float $credits,
        string $categoryName,
        string $itemName,
        int $score,
        int $max,
    ): void {
        $course = Course::create([
            'code' => $code,
            'name' => $name,
            'grade_level' => $profile->grade_level,
            'is_ap' => $isAp,
            'credit_hours' => $credits,
            'class_section_id' => $section->id,
            'teacher_id' => $teacher->id,
        ]);

        $category = $this->gradingCategory($tier, $categoryName, 100);
        $item = $this->gradingItem($category, "{$code} {$itemName}", $max);

        StudentScore::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $section->id,
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'grading_item_id' => $item->id,
            'term' => 'Quarter 1',
            'academic_year' => '2026-2027',
            'score_obtained' => $score,
            'max_score' => $item->max_score,
            'created_by' => $teacher->id,
        ]);
    }

    private function student(string $email, string $number, string $gradeLevel): array
    {
        $user = $this->user('student', $email);

        return [$user, StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'grade_level' => $gradeLevel,
        ])];
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
