<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\CashAdvance;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The school works in one year at a time.
 *
 * Whichever year is active is the whole of what the app shows; last year's
 * classes, subjects, streams, events and advances are out of sight until that
 * year is activated again. Nothing is deleted — only put away.
 */
class ActiveYearScopeTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-2027';

    private const PAST = '2025-2026';

    private User $admin;

    private AcademicYear $current;

    private AcademicYear $previous;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin@school.test');
        $this->previous = AcademicYear::create(['name' => self::PAST, 'is_active' => false]);
        $this->current = AcademicYear::create(['name' => self::NOW, 'is_active' => true]);
    }

    // ---- classes and subjects ---------------------------------------------------

    public function test_only_this_years_classes_are_listed(): void
    {
        $this->section(self::NOW, 'G12');
        $this->section(self::PAST, 'G11');

        $body = $this->withUser($this->admin)->getJson('/api/course-sections')->assertOk()->json('data');

        $this->assertCount(1, $body);
        $this->assertSame('G12', $body[0]['class_name']);
    }

    public function test_only_this_years_subjects_are_listed(): void
    {
        $this->course('MTH', $this->section(self::NOW, 'G12'));
        $this->course('OLD', $this->section(self::PAST, 'G11'));

        $codes = collect($this->withUser($this->admin)->getJson('/api/courses')->assertOk()->json('data'))
            ->pluck('code');

        $this->assertSame(['MTH'], $codes->all());
    }

    /**
     * A subject attached to no class has no year to be outside of, and the
     * subjects page is where it gets linked up or cleared away.
     */
    public function test_a_subject_with_no_class_is_still_listed(): void
    {
        Course::create(['code' => 'LOOSE', 'name' => 'Loose', 'grade_level' => 'G12']);
        $this->course('OLD', $this->section(self::PAST, 'G11'));

        $codes = collect($this->withUser($this->admin)->getJson('/api/courses')->assertOk()->json('data'))
            ->pluck('code');

        $this->assertSame(['LOOSE'], $codes->all());
    }

    public function test_a_teachers_own_class_list_follows_the_active_year(): void
    {
        $teacher = $this->user('teacher', 'teacher@school.test');
        $this->section(self::NOW, 'G12')->update(['teacher_id' => $teacher->id]);
        $this->section(self::PAST, 'G11')->update(['teacher_id' => $teacher->id]);

        $this->withUser($teacher)
            ->getJson('/api/teacher/classes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.class_name', 'G12');
    }

    // ---- the classroom -----------------------------------------------------------

    public function test_a_teacher_sees_only_this_years_streams(): void
    {
        $teacher = $this->user('teacher', 'teacher@school.test');
        $this->course('MTH', $this->section(self::NOW, 'G12'))->update(['teacher_id' => $teacher->id]);
        $this->course('OLD', $this->section(self::PAST, 'G11'))->update(['teacher_id' => $teacher->id]);

        $this->withUser($teacher)
            ->getJson('/api/classroom/courses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'MTH');
    }

    public function test_a_student_sees_only_this_years_streams(): void
    {
        $now = $this->section(self::NOW, 'G12');
        $past = $this->section(self::PAST, 'G11');
        $this->course('MTH', $now);
        $this->course('OLD', $past);

        $student = $this->enrolledStudent([$now, $past]);

        $this->withUser($student)
            ->getJson('/api/classroom/courses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'MTH');
    }

    // ---- the calendar ------------------------------------------------------------

    public function test_the_calendar_hides_a_past_years_events(): void
    {
        $this->event('يوم رياضي', self::NOW);
        $this->event('حفل العام الماضي', self::PAST);
        // An event with no year at all is the school's generally.
        $this->event('عطلة وطنية', null);

        $titles = collect(
            $this->withUser($this->admin)
                ->getJson('/api/calendar-events?year=2026&month=9')
                ->assertOk()
                ->json('data'),
        )->pluck('title');

        $this->assertEqualsCanonicalizing(['يوم رياضي', 'عطلة وطنية'], $titles->all());
    }

    // ---- finance -------------------------------------------------------------------

    public function test_advances_default_to_this_year_but_a_named_year_is_honoured(): void
    {
        $this->advance('محمد', self::NOW);
        $this->advance('علي', self::PAST);

        $this->withUser($this->admin)
            ->getJson('/api/finance/advances')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.holder_name', 'محمد');

        // A report that deliberately reaches back still can.
        $this->withUser($this->admin)
            ->getJson('/api/finance/advances?academic_year='.self::PAST)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.holder_name', 'علي');
    }

    public function test_the_finance_class_picker_follows_the_active_year(): void
    {
        $this->section(self::NOW, 'G12');
        $this->section(self::PAST, 'G11');

        $this->withUser($this->admin)
            ->getJson('/api/finance/classes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.class_name', 'G12');
    }

    // ---- switching -------------------------------------------------------------------

    /**
     * Nothing was deleted: activating the old year brings its work back and puts
     * this year's away.
     */
    public function test_activating_the_previous_year_swaps_what_is_shown(): void
    {
        $this->course('MTH', $this->section(self::NOW, 'G12'));
        $this->course('OLD', $this->section(self::PAST, 'G11'));

        $this->withUser($this->admin)
            ->putJson("/api/academic-years/{$this->previous->id}/activate")
            ->assertOk();

        $codes = collect($this->withUser($this->admin)->getJson('/api/courses')->json('data'))->pluck('code');
        $classes = collect($this->withUser($this->admin)->getJson('/api/course-sections')->json('data'))
            ->pluck('class_name');

        $this->assertSame(['OLD'], $codes->all());
        $this->assertSame(['G11'], $classes->all());
    }

    /**
     * A school that has not set a year up yet should still see its own work.
     */
    public function test_with_no_active_year_nothing_is_hidden(): void
    {
        $this->section(self::NOW, 'G12');
        $this->section(self::PAST, 'G11');
        AcademicYear::query()->update(['is_active' => false]);

        $this->withUser($this->admin)
            ->getJson('/api/course-sections')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ---- fixtures ---------------------------------------------------------------------

    private function section(string $year, string $name): CourseSection
    {
        return CourseSection::create([
            'section_code' => $name.'-'.$year,
            'class_name' => $name,
            'academic_year' => $year,
            'term' => 'Quarter 1',
        ]);
    }

    private function course(string $code, CourseSection $section): Course
    {
        return Course::create([
            'code' => $code,
            'name' => $code,
            'grade_level' => $section->class_name,
            'class_section_id' => $section->id,
        ]);
    }

    private function event(string $title, ?string $year): CalendarEvent
    {
        return CalendarEvent::create([
            'title' => $title,
            'kind' => 'activity',
            'color' => '#465fff',
            'starts_on' => '2026-09-10',
            'ends_on' => '2026-09-10',
            'academic_year' => $year,
        ]);
    }

    private function advance(string $holder, string $year): CashAdvance
    {
        return CashAdvance::create([
            'academic_year' => $year,
            'advance_number' => 1,
            'holder_name' => $holder,
            'purpose' => 'مستلزمات',
            'amount' => 100,
            'method' => 'نقداً',
            'issued_on' => '2026-09-01',
        ]);
    }

    /**
     * @param  array<int, CourseSection>  $sections
     */
    private function enrolledStudent(array $sections): User
    {
        $user = User::create([
            'name' => 'Ali',
            'email' => 'ali@school.test',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'student',
            'is_active' => true,
        ]);

        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'S-1',
            'admission_no' => 'S-1',
            'full_name' => 'Ali',
            'grade_level' => 'G12',
            'academic_year' => self::NOW,
            'status' => 'active',
        ]);

        foreach ($sections as $section) {
            Enrollment::create([
                'student_profile_id' => $profile->id,
                'course_section_id' => $section->id,
                'status' => 'active',
            ]);
        }

        return $user;
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
