<?php

namespace Tests\Feature;

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

class PhaseSixGradeCalculationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGradingFixtures;

    public function test_weighted_grade_report_calculates_category_breakdown(): void
    {
        [$admin, $studentProfile, $course] = $this->scoredSection();
        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/admin/student-profiles/{$studentProfile->id}/courses/{$course->id}/grade-report?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('data.final_grade', 27)
            ->assertJsonPath('data.total_applied_weight', 30)
            ->assertJsonFragment([
                'category_name' => 'Homework',
                'average_percent' => 85,
                'weighted_points' => 8.5,
            ])
            ->assertJsonFragment([
                'category_name' => 'Quiz',
                'average_percent' => 92.5,
                'weighted_points' => 18.5,
            ]);

        $response = $this->withToken($token)
            ->getJson("/api/admin/student-profiles/{$studentProfile->id}/courses/{$course->id}/grade-report?term=Quarter%201");

        $missing = collect($response->json('data.missing_scores'));
        $this->assertTrue($missing->contains(fn (string $entry) => str_contains($entry, 'Exam')));
    }

    public function test_teacher_can_view_own_section_summary(): void
    {
        [, $studentProfile, $course, $teacher] = $this->scoredSection();
        $token = $teacher->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/teacher/courses/{$course->id}/grade-summary?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('data.0.student_profile.id', $studentProfile->id)
            ->assertJsonPath('data.0.report.final_grade', 27);
    }

    public function test_student_can_view_own_report(): void
    {
        [, $studentProfile, $course] = $this->scoredSection();

        $this->withToken($studentProfile->user->createToken('own')->plainTextToken)
            ->getJson("/api/student/courses/{$course->id}/grade-report?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('data.final_grade', 27);
    }

    public function test_student_cannot_view_section_report_when_not_enrolled(): void
    {
        [, , $course] = $this->scoredSection();
        $otherStudent = $this->user('student', 'other.student@example.com');
        $otherProfile = StudentProfile::create([
            'user_id' => $otherStudent->id,
            'student_number' => 'S-2002',
            'grade_level' => 'G10',
        ]);
        $otherSection = CourseSection::create([
            'teacher_id' => $course->teacher_id,
            'section_code' => 'MATH10-B',
            'class_name' => 'G10-B',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);
        Enrollment::create([
            'student_profile_id' => $otherProfile->id,
            'course_section_id' => $otherSection->id,
            'status' => 'active',
        ]);
        $token = $otherStudent->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'other.student@example.com');

        $this->withToken($token)
            ->getJson("/api/student/courses/{$course->id}/grade-report?term=Quarter%201")
            ->assertForbidden();

        $this->assertFalse($otherProfile->enrollments()->where('course_section_id', $course->class_section_id)->exists());
    }

    private function scoredSection(): array
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $student = $this->user('student', 'student@example.com');

        $studentProfile = StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => 'S-1001',
            'grade_level' => 'G10',
        ]);

        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'MATH10-A',
            'class_name' => 'G10-A',
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
        $exam = $this->gradingCategory($tier, 'Exam', 50, 0);
        $homework = $this->gradingCategory($tier, 'Homework', 10, 1);
        $project = $this->gradingCategory($tier, 'Project', 20, 2);
        $quiz = $this->gradingCategory($tier, 'Quiz', 20, 3);

        $this->gradingItem($exam, 'Final Exam', 100);
        $this->gradingItem($project, 'Research Project', 100);
        $homeworkOne = $this->gradingItem($homework, 'Homework 1', 10, 0);
        $homeworkTwo = $this->gradingItem($homework, 'Homework 2', 10, 1);
        $quizOne = $this->gradingItem($quiz, 'Quiz 1', 20, 0);
        $quizTwo = $this->gradingItem($quiz, 'Quiz 2', 20, 1);

        foreach ([[$homeworkOne, 8], [$homeworkTwo, 9], [$quizOne, 18], [$quizTwo, 19]] as [$item, $score]) {
            StudentScore::create([
                'student_profile_id' => $studentProfile->id,
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

        $this->openAllTerms('2026-2027');

        return [$admin, $studentProfile, $course, $teacher];
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
