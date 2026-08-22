<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GradeSubmission;
use App\Models\GradingItem;
use App\Models\StudentProfile;
use App\Models\StudentScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

class GradeSubmissionReportTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    private const YEAR = '2026-2027';

    public function test_report_separates_submitted_partial_and_not_started(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = $this->section();
        $item = $this->item();
        $student = $this->enrolledStudent($section);

        $submitted = $this->course($section, $teacher, 'MATH10', 'Mathematics');
        $partial = $this->course($section, $teacher, 'ENG10', 'English');
        $this->course($section, $teacher, 'SCI10', 'Science');

        $this->submit($submitted, 'Quarter 1');
        $this->score($partial, $student, $item, $teacher, 'Quarter 1', 7);

        $response = $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson($this->url('quarter', ['term' => 'Quarter 1']))
            ->assertOk();

        $response->assertJsonPath('data.summary.total', 3)
            ->assertJsonPath('data.summary.submitted', 1)
            ->assertJsonPath('data.summary.partial', 1)
            ->assertJsonPath('data.summary.not_started', 1)
            ->assertJsonPath('data.summary.pending', 2);

        $statuses = collect($response->json('data.teachers.0.pending'))
            ->pluck('status', 'subject_code');

        $this->assertSame('partial', $statuses['ENG10']);
        $this->assertSame('not_started', $statuses['SCI10']);
    }

    public function test_report_lists_pending_subject_with_class_and_term(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = $this->section();
        $this->course($section, $teacher, 'MATH10', 'Mathematics');

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson($this->url('quarter', ['term' => 'Quarter 2']))
            ->assertOk()
            ->assertJsonPath('data.teachers.0.teacher_name', $teacher->name)
            ->assertJsonPath('data.teachers.0.is_complete', false)
            ->assertJsonPath('data.teachers.0.pending.0.subject_name', 'Mathematics')
            ->assertJsonPath('data.teachers.0.pending.0.class_name', 'G10')
            ->assertJsonPath('data.teachers.0.pending.0.term', 'Quarter 2');
    }

    public function test_semester_report_covers_both_of_its_quarters(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = $this->section();
        $course = $this->course($section, $teacher, 'MATH10', 'Mathematics');

        $this->submit($course, 'Quarter 1');

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson($this->url('semester', ['semester' => 1]))
            ->assertOk()
            ->assertJsonPath('data.terms', ['Quarter 1', 'Quarter 2'])
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.submitted', 1)
            ->assertJsonPath('data.summary.pending', 1)
            ->assertJsonPath('data.teachers.0.pending.0.term', 'Quarter 2');
    }

    public function test_teacher_who_submitted_everything_is_marked_complete(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = $this->section();
        $course = $this->course($section, $teacher, 'MATH10', 'Mathematics');

        $this->submit($course, 'Quarter 1');

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson($this->url('quarter', ['term' => 'Quarter 1']))
            ->assertOk()
            ->assertJsonPath('data.teachers.0.is_complete', true)
            ->assertJsonPath('data.teachers.0.pending_count', 0)
            ->assertJsonPath('data.summary.completion_percent', 100);
    }

    public function test_subjects_without_a_teacher_are_reported_separately(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $section = $this->section();
        $this->course($section, null, 'ART10', 'Art');

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson($this->url('quarter', ['term' => 'Quarter 1']))
            ->assertOk()
            ->assertJsonCount(0, 'data.teachers')
            ->assertJsonCount(1, 'data.unassigned')
            ->assertJsonPath('data.unassigned.0.subject_code', 'ART10')
            ->assertJsonPath('data.summary.unassigned', 1);
    }

    public function test_report_can_be_filtered_to_one_class(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $first = $this->section();
        $second = $this->section('G11', 'G11-A');
        $this->course($first, $teacher, 'MATH10', 'Mathematics');
        $this->course($second, $teacher, 'MATH11', 'Mathematics 11');

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson($this->url('quarter', ['term' => 'Quarter 1', 'class_section_id' => $second->id]))
            ->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.teachers.0.pending.0.subject_code', 'MATH11');
    }

    public function test_report_ignores_other_academic_years(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $old = $this->section('G10', 'G10-OLD', '2025-2026');
        $this->course($old, $teacher, 'MATH10', 'Mathematics');

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson($this->url('quarter', ['term' => 'Quarter 1']))
            ->assertOk()
            ->assertJsonPath('data.summary.total', 0);
    }

    public function test_pdf_downloads(): void
    {
        $admin = $this->user('admin', 'admin@example.com');
        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = $this->section();
        $this->course($section, $teacher, 'MATH10', 'Mathematics');

        $response = $this->withToken($admin->createToken('t')->plainTextToken)
            ->get('/api/reports/grade-submissions/pdf?academic_year='.self::YEAR.'&type=quarter&term=Quarter%201')
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_staff_without_permission_cannot_open_the_report(): void
    {
        $staff = $this->user('staff', 'staff@example.com');

        $this->withToken($staff->createToken('t')->plainTextToken)
            ->getJson($this->url('quarter', ['term' => 'Quarter 1']))
            ->assertForbidden();
    }

    public function test_report_rejects_an_unknown_term(): void
    {
        $admin = $this->user('admin', 'admin@example.com');

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson($this->url('quarter', ['term' => 'Quarter 9']))
            ->assertStatus(422);
    }

    private function url(string $type, array $params): string
    {
        return '/api/reports/grade-submissions?'.http_build_query([
            'academic_year' => self::YEAR,
            'type' => $type,
            ...$params,
        ]);
    }

    private function section(string $className = 'G10', string $code = 'G10-A', string $year = self::YEAR): CourseSection
    {
        return CourseSection::create([
            'teacher_id' => User::where('user_type', 'teacher')->value('id')
                ?? $this->user('teacher', 'homeroom@example.com')->id,
            'section_code' => $code,
            'class_name' => $className,
            'academic_year' => $year,
            'term' => 'Quarter 1',
        ]);
    }

    private function course(CourseSection $section, ?User $teacher, string $code, string $name): Course
    {
        return Course::create([
            'code' => $code,
            'name' => $name,
            'grade_level' => 'G10',
            'class_section_id' => $section->id,
            'teacher_id' => $teacher?->id,
        ]);
    }

    private function item(): GradingItem
    {
        $tier = $this->gradeTier('G7-12', 7, 12);

        return $this->gradingItem($this->gradingCategory($tier, 'Quiz', 100), 'Quiz 1', 10);
    }

    private function enrolledStudent(CourseSection $section): StudentProfile
    {
        $profile = StudentProfile::create([
            'user_id' => $this->user('student', 'student@example.com')->id,
            'student_number' => 'S-1001',
            'grade_level' => 'G10',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        return $profile;
    }

    private function submit(Course $course, string $term): void
    {
        GradeSubmission::create([
            'course_id' => $course->id,
            'term' => $term,
            'academic_year' => self::YEAR,
            'status' => GradeSubmission::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by' => $course->teacher_id,
        ]);
    }

    private function score(
        Course $course,
        StudentProfile $profile,
        GradingItem $item,
        User $teacher,
        string $term,
        float $score,
    ): void {
        StudentScore::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $course->class_section_id,
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'grading_item_id' => $item->id,
            'term' => $term,
            'academic_year' => self::YEAR,
            'score_obtained' => $score,
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
