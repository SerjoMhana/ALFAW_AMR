<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CashAdvance;
use App\Models\ClassPost;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\TermWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Deleting an academic year created by mistake.
 *
 * The year carries classes, marks, registers and receipts, so the tests are
 * about two things: the warning counts the truth, and the deletion leaves
 * nothing orphaned behind it.
 */
class AcademicYearDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicYear $current;

    private AcademicYear $mistake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin@school.test');
        $this->current = AcademicYear::create(['name' => '2026-2027', 'is_active' => true]);
        $this->mistake = AcademicYear::create(['name' => '2030-2031', 'is_active' => false]);
    }

    public function test_the_warning_says_nothing_is_attached_to_an_empty_year(): void
    {
        $body = $this->withUser($this->admin)
            ->getJson("/api/academic-years/{$this->mistake->id}/impact")
            ->assertOk()
            ->json('data');

        $this->assertTrue($body['is_empty']);
        $this->assertSame(0, $body['total']);
    }

    public function test_the_warning_counts_what_would_go(): void
    {
        $this->fillYear('2030-2031');

        $body = $this->withUser($this->admin)
            ->getJson("/api/academic-years/{$this->mistake->id}/impact")
            ->assertOk()
            ->json('data');

        $this->assertFalse($body['is_empty']);
        $this->assertSame(1, $body['counts']['classes']);
        $this->assertSame(1, $body['counts']['courses']);
        $this->assertSame(1, $body['counts']['enrollments']);
        $this->assertSame(1, $body['counts']['students']);
        $this->assertSame(1, $body['counts']['term_windows']);
        $this->assertSame(1, $body['counts']['posts']);
        $this->assertSame(1, $body['counts']['advances']);
        $this->assertSame(1, $body['counts']['calendar_events']);
    }

    public function test_an_empty_year_is_deleted_on_its_own(): void
    {
        $this->withUser($this->admin)
            ->deleteJson("/api/academic-years/{$this->mistake->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->assertDatabaseMissing('academic_years', ['name' => '2030-2031']);
        $this->assertDatabaseHas('academic_years', ['name' => '2026-2027']);
    }

    /**
     * The point of the whole exercise: nothing is left pointing at a year that
     * no longer exists.
     */
    public function test_deleting_a_year_takes_its_data_with_it(): void
    {
        $this->fillYear('2030-2031');

        $this->withUser($this->admin)
            ->deleteJson("/api/academic-years/{$this->mistake->id}")
            ->assertOk();

        $this->assertDatabaseMissing('academic_years', ['name' => '2030-2031']);
        $this->assertSame(0, CourseSection::where('academic_year', '2030-2031')->count());
        $this->assertSame(0, TermWindow::where('academic_year', '2030-2031')->count());
        $this->assertSame(0, CashAdvance::where('academic_year', '2030-2031')->count());
        $this->assertSame(0, CalendarEvent::where('academic_year', '2030-2031')->count());
        $this->assertSame(0, ClassPost::withTrashed()->count());
        // Subjects hang off the class by a nullable key, so they would have been
        // orphaned rather than removed had they not been deleted first.
        $this->assertSame(0, Course::count());
        $this->assertSame(0, Enrollment::count());
    }

    public function test_a_student_who_sits_in_another_year_survives(): void
    {
        $student = $this->student('Ali', 'S-1', '2026-2027');
        $this->enrol($student, $this->section('2030-2031'));
        $this->enrol($student, $this->section('2026-2027'));

        $impact = $this->withUser($this->admin)
            ->getJson("/api/academic-years/{$this->mistake->id}/impact")
            ->json('data');

        $this->assertSame(0, $impact['counts']['students']);

        $this->withUser($this->admin)->deleteJson("/api/academic-years/{$this->mistake->id}")->assertOk();

        $this->assertDatabaseHas('student_profiles', ['id' => $student->id]);
        // Only the enrolment in the deleted year went.
        $this->assertSame(1, Enrollment::where('student_profile_id', $student->id)->count());
    }

    public function test_a_student_who_exists_only_in_that_year_goes_with_it(): void
    {
        $student = $this->student('Omar', 'S-2', '2030-2031');
        $this->enrol($student, $this->section('2030-2031'));
        $userId = $student->user_id;

        $this->withUser($this->admin)->deleteJson("/api/academic-years/{$this->mistake->id}")->assertOk();

        $this->assertDatabaseMissing('student_profiles', ['id' => $student->id]);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_the_active_year_is_protected(): void
    {
        $this->withUser($this->admin)
            ->deleteJson("/api/academic-years/{$this->current->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('academic_years', ['name' => '2026-2027']);
    }

    public function test_the_last_remaining_year_is_protected(): void
    {
        $this->mistake->delete();
        $this->current->forceFill(['is_active' => false])->save();

        $this->withUser($this->admin)
            ->deleteJson("/api/academic-years/{$this->current->id}")
            ->assertStatus(422);

        $this->assertSame(1, AcademicYear::count());
    }

    public function test_staff_without_settings_manage_cannot_delete_a_year(): void
    {
        $staff = $this->user('staff', 'staff@school.test');

        $this->withUser($staff)->getJson("/api/academic-years/{$this->mistake->id}/impact")->assertForbidden();
        $this->withUser($staff)->deleteJson("/api/academic-years/{$this->mistake->id}")->assertForbidden();

        $this->assertDatabaseHas('academic_years', ['name' => '2030-2031']);
    }

    // ---- fixtures -------------------------------------------------------------

    private function fillYear(string $name): void
    {
        $section = $this->section($name);
        $course = Course::create([
            'code' => 'MTH-X',
            'name' => 'Mathematics',
            'grade_level' => 'G12',
            'class_section_id' => $section->id,
        ]);

        $student = $this->student('Omar', 'S-9', $name);
        $this->enrol($student, $section);

        TermWindow::create(['academic_year' => $name, 'term' => 'Quarter 1', 'is_open' => true]);

        ClassPost::create([
            'course_id' => $course->id,
            'author_id' => $this->admin->id,
            'type' => 'announcement',
            'body' => 'إعلان',
            'published_at' => now(),
            'academic_year' => $name,
        ]);

        CashAdvance::create([
            'academic_year' => $name,
            'advance_number' => 1,
            'holder_name' => 'محمد',
            'purpose' => 'مستلزمات',
            'amount' => 100,
            'method' => 'نقداً',
            'issued_on' => now()->toDateString(),
        ]);

        CalendarEvent::create([
            'title' => 'يوم رياضي',
            'kind' => 'activity',
            'color' => '#465fff',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
            'academic_year' => $name,
        ]);
    }

    private function section(string $year): CourseSection
    {
        return CourseSection::create([
            'section_code' => 'S-'.$year,
            'class_name' => 'G12',
            'academic_year' => $year,
            'term' => 'Quarter 1',
        ]);
    }

    private function enrol(StudentProfile $student, CourseSection $section): Enrollment
    {
        return Enrollment::create([
            'student_profile_id' => $student->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);
    }

    private function student(string $name, string $number, string $year): StudentProfile
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($number).'@school.test',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'admission_no' => $number,
            'full_name' => $name,
            'grade_level' => 'G12',
            'academic_year' => $year,
            'status' => 'active',
        ]);
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
