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

class PhaseNineSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGradingFixtures;

    public function test_direct_api_access_without_permission_returns_403(): void
    {
        $staff = $this->user('staff', 'staff@example.com');

        $this->withToken($staff->createToken('test')->plainTextToken)
            ->getJson('/api/student-profiles')
            ->assertForbidden();
    }

    public function test_teacher_cannot_edit_scores_for_unowned_section(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->sectionWithStudent();
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

        $this->assertNotEquals($teacher->id, $otherTeacher->id);
    }

    public function test_student_cannot_open_another_students_report(): void
    {
        [, , $studentProfile, $course] = $this->sectionWithStudent();
        $otherStudent = $this->user('student', 'other.student@example.com');
        StudentProfile::create([
            'user_id' => $otherStudent->id,
            'student_number' => 'S-2002',
            'grade_level' => 'G10',
        ]);

        $this->withToken($otherStudent->createToken('test')->plainTextToken)
            ->getJson("/api/student/courses/{$course->id}/grade-report?term=Quarter%201")
            ->assertForbidden();

        $this->assertSame('S-1001', $studentProfile->student_number);
    }

    public function test_grade_bulk_save_writes_audit_logs(): void
    {
        [$teacher, $item, $studentProfile, $course] = $this->sectionWithStudent();
        $token = $teacher->createToken('test')->plainTextToken;

        $this->withToken($token)->putJson("/api/courses/{$course->id}/grade-entry", [
            'term' => 'Quarter 1',
            'academic_year' => '2026-2027',
            'scores' => [
                ['student_profile_id' => $studentProfile->id, 'grading_item_id' => $item->id, 'score_obtained' => 8],
            ],
        ])->assertOk();

        $this->withToken($token)->putJson("/api/courses/{$course->id}/grade-entry", [
            'term' => 'Quarter 1',
            'academic_year' => '2026-2027',
            'scores' => [
                ['student_profile_id' => $studentProfile->id, 'grading_item_id' => $item->id, 'score_obtained' => 9],
            ],
        ])->assertOk();

        $studentScore = \App\Models\StudentScore::where('grading_item_id', $item->id)
            ->where('student_profile_id', $studentProfile->id)
            ->firstOrFail();

        $this->assertDatabaseHas('grade_audit_logs', [
            'student_score_id' => $studentScore->id,
            'old_score' => null,
            'new_score' => 8,
        ]);

        $this->assertDatabaseHas('grade_audit_logs', [
            'student_score_id' => $studentScore->id,
            'old_score' => 8,
            'new_score' => 9,
        ]);
    }

    /**
     * @return array{0: User, 1: GradingItem, 2: StudentProfile, 3: Course}
     */
    private function sectionWithStudent(): array
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
