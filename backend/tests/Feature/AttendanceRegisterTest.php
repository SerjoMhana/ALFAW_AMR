<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\AttendanceMonthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The monthly register: the grid the office keeps and the supervisors carry.
 */
class AttendanceRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CourseSection $section;

    private StudentProfile $ali;

    private StudentProfile $sara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin@school.test');
        $this->section = CourseSection::create([
            'section_code' => 'G12-A',
            'class_name' => 'G12',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);

        // Named out of alphabetical order to prove the sheet sorts them.
        $this->sara = $this->student('Sara', 'S-2');
        $this->ali = $this->student('Ali', 'S-1');
    }

    public function test_the_month_lays_out_every_student_against_every_day(): void
    {
        $sheet = app(AttendanceMonthService::class)->build($this->section, 2026, 9);

        $this->assertCount(30, $sheet['days']);
        $this->assertCount(2, $sheet['students']);
        // Sorted by name, so the paper reads the way a register does.
        $this->assertSame('Ali', $sheet['students'][0]['name']);
        $this->assertSame(1, $sheet['students'][0]['no']);
        $this->assertCount(30, $sheet['students'][0]['marks']);
    }

    public function test_recorded_days_show_their_mark_and_count_towards_the_totals(): void
    {
        $this->record($this->ali, '2026-09-01', 'present');
        $this->record($this->ali, '2026-09-02', 'absent');
        $this->record($this->ali, '2026-09-03', 'absent');
        $this->record($this->sara, '2026-09-01', 'late');

        $sheet = app(AttendanceMonthService::class)->build($this->section, 2026, 9);
        $ali = collect($sheet['students'])->firstWhere('name', 'Ali');
        $sara = collect($sheet['students'])->firstWhere('name', 'Sara');

        $this->assertSame('غ', $ali['marks']['2026-09-02']['mark']);
        $this->assertSame(2, $ali['totals']['absent']);
        $this->assertSame(1, $ali['totals']['present']);
        $this->assertSame(1, $sara['totals']['late']);
        // A day nobody marked stays empty rather than counting as attendance.
        $this->assertNull($ali['marks']['2026-09-10']);
        $this->assertCount(3, $sheet['recorded_dates']);
    }

    public function test_fridays_and_saturdays_are_flagged_as_the_weekend(): void
    {
        $sheet = app(AttendanceMonthService::class)->build($this->section, 2026, 9);
        $weekend = collect($sheet['days'])->where('is_weekend', true);

        // September 2026 has four of each.
        $this->assertCount(8, $weekend);
        $this->assertTrue(collect($sheet['days'])->firstWhere('date', '2026-09-04')['is_weekend']);
    }

    public function test_the_month_endpoint_answers_the_office(): void
    {
        $this->withUser($this->admin)
            ->getJson("/api/course-sections/{$this->section->id}/attendance/month?year=2026&month=9")
            ->assertOk()
            ->assertJsonPath('data.section.name', 'G12')
            ->assertJsonCount(30, 'data.days')
            ->assertJsonCount(2, 'data.students');
    }

    public function test_a_student_cannot_read_the_class_register(): void
    {
        $student = $this->ali->user;

        $this->withUser($student)
            ->getJson("/api/course-sections/{$this->section->id}/attendance/month")
            ->assertForbidden();

        $this->withUser($student)
            ->getJson("/api/course-sections/{$this->section->id}/attendance")
            ->assertForbidden();
    }

    public function test_someone_with_view_only_can_read_but_not_write(): void
    {
        $viewer = $this->user('staff', 'viewer@school.test');
        $viewer->permissions()->attach(
            Permission::firstOrCreate(['name' => 'attendance.view'], ['label' => 'View attendance'])->id,
        );

        $this->withUser($viewer)
            ->getJson("/api/course-sections/{$this->section->id}/attendance/month")
            ->assertOk();

        $this->withUser($viewer)
            ->putJson("/api/course-sections/{$this->section->id}/attendance", [
                'attendance_date' => '2026-09-01',
                'records' => [['student_profile_id' => $this->ali->id, 'status' => 'present']],
            ])
            ->assertForbidden();
    }

    // ---- the printed sheet ---------------------------------------------------

    public function test_the_blank_sheet_downloads_as_a_pdf(): void
    {
        $response = $this->withUser($this->admin)
            ->get("/api/course-sections/{$this->section->id}/attendance/month.pdf?year=2026&month=9");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('-blank.pdf', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_filled_sheet_is_a_separate_download(): void
    {
        $this->record($this->ali, '2026-09-01', 'absent');

        $response = $this->withUser($this->admin)
            ->get("/api/course-sections/{$this->section->id}/attendance/month.pdf?year=2026&month=9&filled=1");

        $response->assertOk();
        $this->assertStringNotContainsString('-blank', $response->headers->get('content-disposition'));
    }

    public function test_the_sheet_cannot_be_printed_by_an_outsider(): void
    {
        $this->withUser($this->ali->user)
            ->get("/api/course-sections/{$this->section->id}/attendance/month.pdf")
            ->assertForbidden();
    }

    public function test_a_month_outside_the_calendar_is_refused(): void
    {
        $this->withUser($this->admin)
            ->getJson("/api/course-sections/{$this->section->id}/attendance/month?month=13")
            ->assertStatus(422);
    }

    // ---- fixtures -------------------------------------------------------------

    private function record(StudentProfile $student, string $date, string $status): AttendanceRecord
    {
        return AttendanceRecord::create([
            'course_section_id' => $this->section->id,
            'student_profile_id' => $student->id,
            'attendance_date' => $date,
            'status' => $status,
            'recorded_by' => $this->admin->id,
        ]);
    }

    private function student(string $name, string $number): StudentProfile
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($number).'@school.test',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'admission_no' => $number,
            'full_name' => $name,
            'grade_level' => 'G12',
            'academic_year' => '2026-2027',
            'section_id' => $this->section->id,
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $this->section->id,
            'status' => 'active',
        ]);

        return $profile;
    }

    private function user(string $type, string $email): User
    {
        return User::create([
            'name' => ucfirst($type),
            'email' => $email,
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => $type,
            'is_active' => true,
        ]);
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
