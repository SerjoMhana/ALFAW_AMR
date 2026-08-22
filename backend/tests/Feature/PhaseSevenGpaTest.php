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

class PhaseSevenGpaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGradingFixtures;

    public function test_g10_student_gpa_uses_ap_and_normal_weighted_by_credit_hours(): void
    {
        $tier = $this->gradeTier('G7-12', 7, 12);
        $category = $this->gradingCategory($tier, 'Exam', 100);

        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $student = $this->user('student', 'student@example.com');
        $profile = StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => 'S-1001',
            'grade_level' => 'G10',
        ]);

        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'G10-A',
            'class_name' => 'G10-A',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        $this->scoredCourse($category, $profile, $teacher, $section, 'APBIO', 'AP Biology', true, 1.5, 95);
        $this->scoredCourse($category, $profile, $teacher, $section, 'ENG10', 'English 10', false, 1, 85);

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson("/api/admin/student-profiles/{$profile->id}/gpa?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('term_gpa.gpa', 4.2)
            ->assertJsonPath('term_gpa.total_credit_hours', 2.5)
            ->assertJsonPath('term_gpa.courses.0.gpa_points', 5)
            ->assertJsonPath('term_gpa.courses.1.gpa_points', 3)
            ->assertJsonMissingPath('cumulative_gpa');
    }

    public function test_each_term_has_its_own_independent_gpa(): void
    {
        $tier = $this->gradeTier('G7-12', 7, 12);
        $category = $this->gradingCategory($tier, 'Exam', 100);

        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $student = $this->user('student', 'student@example.com');
        $profile = StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => 'S-1001',
            'grade_level' => 'G10',
        ]);

        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'G10-A',
            'class_name' => 'G10-A',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        // Same subject, a strong Quarter 1 and a weak Quarter 2.
        $course = Course::create([
            'code' => 'ENG10',
            'name' => 'English 10',
            'grade_level' => 'G10',
            'is_ap' => false,
            'credit_hours' => 1,
            'class_section_id' => $section->id,
            'teacher_id' => $teacher->id,
        ]);
        $item = $this->gradingItem($category, 'ENG10 Exam', 100);

        foreach ([['Quarter 1', 95], ['Quarter 2', 65]] as [$term, $score]) {
            StudentScore::create([
                'student_profile_id' => $profile->id,
                'course_section_id' => $section->id,
                'course_id' => $course->id,
                'teacher_id' => $teacher->id,
                'grading_item_id' => $item->id,
                'term' => $term,
                'academic_year' => '2026-2027',
                'score_obtained' => $score,
                'max_score' => $item->max_score,
                'created_by' => $teacher->id,
            ]);
        }

        $token = $admin->createToken('test')->plainTextToken;

        // Quarter 1 stays a 4.0 — the weak Quarter 2 must not drag it down.
        $this->withToken($token)
            ->getJson("/api/admin/student-profiles/{$profile->id}/gpa?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('term_gpa.term', 'Quarter 1')
            ->assertJsonPath('term_gpa.gpa', 4);

        // ...and Quarter 2 is judged only on its own score.
        $this->withToken($token)
            ->getJson("/api/admin/student-profiles/{$profile->id}/gpa?term=Quarter%202")
            ->assertOk()
            ->assertJsonPath('term_gpa.term', 'Quarter 2')
            ->assertJsonPath('term_gpa.gpa', 1.3);
    }

    public function test_primary_grades_also_get_a_gpa(): void
    {
        $tier = $this->gradeTier('G1-6', 1, 6);
        $category = $this->gradingCategory($tier, 'Exam', 100);

        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $student = $this->user('student', 'g6@example.com');
        $profile = StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => 'S-6001',
            'grade_level' => 'G6',
        ]);

        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'G6-A',
            'class_name' => 'G6',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        $this->scoredCourse($category, $profile, $teacher, $section, 'G6MTH', 'Mathematics 6', false, 1, 95);

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson("/api/admin/student-profiles/{$profile->id}/gpa?term=Quarter%201")
            ->assertOk()
            ->assertJsonPath('term_gpa.grade_level', 'G6')
            ->assertJsonPath('term_gpa.gpa', 4)
            ->assertJsonCount(1, 'term_gpa.courses');
    }

    private function scoredCourse(
        \App\Models\GradingCategory $category,
        StudentProfile $profile,
        User $teacher,
        CourseSection $section,
        string $code,
        string $name,
        bool $isAp,
        float $credits,
        int $grade,
    ): void {
        $course = Course::create([
            'code' => $code,
            'name' => $name,
            'grade_level' => 'G10',
            'is_ap' => $isAp,
            'credit_hours' => $credits,
            'class_section_id' => $section->id,
            'teacher_id' => $teacher->id,
        ]);

        $item = $this->gradingItem($category, "{$code} Exam", 100);

        StudentScore::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $section->id,
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'grading_item_id' => $item->id,
            'term' => 'Quarter 1',
            'academic_year' => '2026-2027',
            'score_obtained' => $grade,
            'max_score' => $item->max_score,
            'created_by' => $teacher->id,
        ]);
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
