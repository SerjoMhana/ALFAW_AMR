<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GradingItem;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

class PhaseFiveStudentScoresTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGradingFixtures;

    public function test_teacher_can_bulk_save_scores_for_own_section(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->sectionWithStudent();
        $token = $teacher->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/courses/{$course->id}/grade-entry?term=Quarter%201&academic_year=2026-2027")
            ->assertOk()
            ->assertJsonPath('data.students.0.student_profile.id', $studentProfile->id);

        $this->withToken($token)->putJson("/api/courses/{$course->id}/grade-entry", [
            'term' => 'Quarter 1',
            'academic_year' => '2026-2027',
            'scores' => [
                ['student_profile_id' => $studentProfile->id, 'grading_item_id' => $item->id, 'score_obtained' => 18],
            ],
        ])->assertOk()
            ->assertJsonPath('data.0.score_obtained', '18.00');

        $this->assertDatabaseHas('student_scores', [
            'grading_item_id' => $item->id,
            'student_profile_id' => $studentProfile->id,
            'course_id' => $course->id,
            'score_obtained' => 18,
        ]);
    }

    public function test_score_cannot_exceed_assessment_max_score(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->sectionWithStudent(maxScore: 20);
        $token = $teacher->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson("/api/courses/{$course->id}/grade-entry", [
            'term' => 'Quarter 1',
            'academic_year' => '2026-2027',
            'scores' => [
                ['student_profile_id' => $studentProfile->id, 'grading_item_id' => $item->id, 'score_obtained' => 21],
            ],
        ])->assertUnprocessable();
    }

    public function test_teacher_cannot_modify_scores_for_other_teacher_section(): void
    {
        [, $item, $studentProfile, $course] = $this->sectionWithStudent();
        $otherTeacher = $this->user('teacher', 'other.teacher@example.com');

        $this->withToken($otherTeacher->createToken('test')->plainTextToken)
            ->putJson("/api/courses/{$course->id}/grade-entry", [
                'term' => 'Quarter 1',
                'academic_year' => '2026-2027',
                'scores' => [
                    ['student_profile_id' => $studentProfile->id, 'grading_item_id' => $item->id, 'score_obtained' => 10],
                ],
            ])
            ->assertForbidden();
    }

    public function test_student_cannot_modify_scores(): void
    {
        [, $item, $studentProfile, $course] = $this->sectionWithStudent();

        $this->withToken($studentProfile->user->createToken('test')->plainTextToken)
            ->putJson("/api/courses/{$course->id}/grade-entry", [
                'term' => 'Quarter 1',
                'academic_year' => '2026-2027',
                'scores' => [
                    ['student_profile_id' => $studentProfile->id, 'grading_item_id' => $item->id, 'score_obtained' => 10],
                ],
            ])
            ->assertForbidden();

        $this->withToken($studentProfile->user->createToken('test2')->plainTextToken)
            ->getJson("/api/courses/{$course->id}/grade-entry?term=Quarter%201&academic_year=2026-2027")
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: GradingItem, 2: StudentProfile, 3: Course}
     */
    private function sectionWithStudent(int $maxScore = 20): array
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
        $category = $this->gradingCategory($tier, 'Quiz', 100);
        $item = $this->gradingItem($category, 'Quiz 1', $maxScore);

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
