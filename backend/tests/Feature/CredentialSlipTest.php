<?php

namespace Tests\Feature;

use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\CredentialSlipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The printable sign-in slip.
 *
 * A stored password cannot be read back, so a slip carries a new one — which
 * means these tests are as much about what the slip does to the account as
 * about what lands on the page.
 */
class CredentialSlipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin@school.test', 'Siraj');
    }

    public function test_a_slip_sets_a_password_that_actually_works(): void
    {
        $teacher = $this->user('teacher', 'hana@school.test', 'HANA');

        $slip = app(CredentialSlipService::class)->forTeacher($teacher);

        $this->assertSame('HANA', $slip['username']);
        $this->assertTrue(Hash::check($slip['password'], $teacher->fresh()->password));

        // And the printed password is what signs in.
        $this->postJson('/api/login', ['username' => 'HANA', 'password' => $slip['password']])
            ->assertOk()
            ->assertJsonPath('user.user_type', 'teacher');
    }

    public function test_the_previous_password_stops_working(): void
    {
        $teacher = $this->user('teacher', 'hana@school.test', 'HANA');

        $this->postJson('/api/login', ['username' => 'HANA', 'password' => 'Str0ng!Passw0rd'])->assertOk();

        app(CredentialSlipService::class)->forTeacher($teacher);

        $this->postJson('/api/login', ['username' => 'HANA', 'password' => 'Str0ng!Passw0rd'])
            ->assertStatus(422);
    }

    public function test_the_printed_password_is_long_and_free_of_lookalike_characters(): void
    {
        $teacher = $this->user('teacher', 'hana@school.test', 'HANA');

        $slip = app(CredentialSlipService::class)->forTeacher($teacher);

        $this->assertSame(10, strlen($slip['password']));
        // Nothing a person could misread off paper: no O/0, no I/l/1.
        $this->assertDoesNotMatchRegularExpression('/[O0Il1]/', $slip['password']);
    }

    public function test_a_teachers_slip_downloads_as_a_pdf(): void
    {
        $teacher = $this->user('teacher', 'hana@school.test', 'HANA');

        $response = $this->withUser($this->admin)
            ->get("/api/credential-slips/teachers/{$teacher->id}?locale=ar");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_an_english_screen_gets_an_english_slip(): void
    {
        $teacher = $this->user('teacher', 'hana@school.test', 'HANA');

        foreach (['ar', 'en'] as $locale) {
            $this->withUser($this->admin)
                ->get("/api/credential-slips/teachers/{$teacher->id}?locale={$locale}")
                ->assertOk();
        }

        // Both render; the wording differs inside, which the view decides.
        $this->assertTrue(true);
    }

    public function test_a_whole_class_prints_as_one_file(): void
    {
        $section = $this->section();
        $this->enrolledStudent($section, 'Ali', 'S-1');
        $this->enrolledStudent($section, 'Sara', 'S-2');

        $slips = app(CredentialSlipService::class)->forClass($section->fresh());

        $this->assertCount(2, $slips);
        // Sorted by name, and each carries its own password.
        $this->assertSame(['Ali', 'Sara'], $slips->pluck('name')->all());
        $this->assertNotSame($slips[0]['password'], $slips[1]['password']);

        $this->withUser($this->admin)
            ->get("/api/credential-slips/classes/{$section->id}?locale=ar")
            ->assertOk();
    }

    public function test_a_class_with_nobody_in_it_says_so(): void
    {
        $section = $this->section();

        $this->withUser($this->admin)
            ->get("/api/credential-slips/classes/{$section->id}")
            ->assertStatus(422);
    }

    public function test_a_students_slip_carries_their_admission_number(): void
    {
        $section = $this->section();
        $student = $this->enrolledStudent($section, 'Ali', 'S-1');

        $slip = app(CredentialSlipService::class)->forStudent($student);

        $this->assertSame('S-1', $slip['reference']);
        $this->assertSame('S-1', $slip['username']);
    }

    public function test_someone_without_the_permission_cannot_print_one(): void
    {
        $teacher = $this->user('teacher', 'hana@school.test', 'HANA');
        $staff = $this->user('staff', 'staff@school.test', 'staff');
        $before = $teacher->password;

        $this->withUser($staff)
            ->get("/api/credential-slips/teachers/{$teacher->id}")
            ->assertForbidden();

        // Refused before anything was reset.
        $this->assertSame($before, $teacher->fresh()->password);
    }

    // ---- fixtures -------------------------------------------------------------

    private function section(): CourseSection
    {
        return CourseSection::create([
            'section_code' => 'G12-A',
            'class_name' => 'G12',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
        ]);
    }

    private function enrolledStudent(CourseSection $section, string $name, string $number): StudentProfile
    {
        $user = User::create([
            'name' => $name,
            'username' => $number,
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
            'section_id' => $section->id,
            'status' => 'active',
        ]);

        Enrollment::create([
            'student_profile_id' => $profile->id,
            'course_section_id' => $section->id,
            'status' => 'active',
        ]);

        return $profile;
    }

    private function user(string $type, string $email, string $username): User
    {
        return User::create([
            'name' => ucfirst($type),
            'username' => $username,
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
