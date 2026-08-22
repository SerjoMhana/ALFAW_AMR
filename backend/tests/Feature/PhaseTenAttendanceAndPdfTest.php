<?php

namespace Tests\Feature;

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

class PhaseTenAttendanceAndPdfTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGradingFixtures;

    /**
     * The register is the office's book. Teaching the class does not open it —
     * only the admin, or a member of staff handed the permission, may write.
     */
    public function test_a_teacher_cannot_write_the_register_for_their_own_class(): void
    {
        [$teacher, $studentProfile, $section] = $this->sectionWithStudent();

        $this->withToken($teacher->createToken('test')->plainTextToken)
            ->putJson("/api/course-sections/{$section->id}/attendance", [
                'attendance_date' => '2026-09-01',
                'records' => [
                    ['student_profile_id' => $studentProfile->id, 'status' => 'present'],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_the_admin_writes_the_register(): void
    {
        [, $studentProfile, $section] = $this->sectionWithStudent();
        $admin = $this->user('admin', 'register.admin@example.com');

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->putJson("/api/course-sections/{$section->id}/attendance", [
                'attendance_date' => '2026-09-01',
                'records' => [
                    ['student_profile_id' => $studentProfile->id, 'status' => 'present'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'present');

        $this->assertDatabaseHas('attendance_records', [
            'course_section_id' => $section->id,
            'student_profile_id' => $studentProfile->id,
            'attendance_date' => '2026-09-01 00:00:00',
            'status' => 'present',
        ]);
    }

    public function test_a_staff_member_handed_the_permission_writes_the_register(): void
    {
        [, $studentProfile, $section] = $this->sectionWithStudent();
        $staff = $this->user('staff', 'registrar@example.com');
        $staff->permissions()->attach(
            Permission::firstOrCreate(['name' => 'attendance.manage'], ['label' => 'Manage attendance'])->id,
        );

        $this->withToken($staff->createToken('test')->plainTextToken)
            ->putJson("/api/course-sections/{$section->id}/attendance", [
                'attendance_date' => '2026-09-01',
                'records' => [
                    ['student_profile_id' => $studentProfile->id, 'status' => 'absent'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('attendance_records', [
            'student_profile_id' => $studentProfile->id,
            'status' => 'absent',
        ]);
    }

    public function test_report_card_pdf_downloads_for_quarter_semester_and_final(): void
    {
        [, $studentProfile] = $this->sectionWithStudent(scored: true);
        $token = $studentProfile->user->createToken('test')->plainTextToken;

        foreach (['quarter', 'semester', 'final'] as $type) {
            $response = $this->withToken($token)
                ->get("/api/student/report-card/pdf?type={$type}&term=Quarter%201");

            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    private function sectionWithStudent(bool $scored = false): array
    {
        $teacher = $this->user('teacher', 'teacher@example.com');
        $student = $this->user('student', 'student@example.com');
        $studentProfile = StudentProfile::create([
            'user_id' => $student->id,
            'student_number' => 'S-1001',
            'grade_level' => 'G10',
        ]);
        $course = Course::create([
            'code' => 'MATH10',
            'name' => 'Mathematics 10',
            'grade_level' => 'G10',
            'credit_hours' => 1,
        ]);
        $section = CourseSection::create([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'section_code' => 'MATH10-A',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);
        Enrollment::create([
            'student_profile_id' => $studentProfile->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        if ($scored) {
            $tier = $this->gradeTier('G7-12', 7, 12);
            $category = $this->gradingCategory($tier, 'Exam', 100);
            $item = $this->gradingItem($category, 'Exam', 100);

            StudentScore::create([
                'student_profile_id' => $studentProfile->id,
                'course_section_id' => $section->id,
                'course_id' => $course->id,
                'teacher_id' => $teacher->id,
                'grading_item_id' => $item->id,
                'term' => 'Quarter 1',
                'academic_year' => '2026-2027',
                'score_obtained' => 95,
                'max_score' => $item->max_score,
                'created_by' => $teacher->id,
            ]);
        }

        return [$teacher, $studentProfile, $section];
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
