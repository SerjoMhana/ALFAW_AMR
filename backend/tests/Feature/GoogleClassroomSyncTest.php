<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GoogleClassroomLink;
use App\Models\GoogleClassroomMember;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Google\ClassroomClient;
use App\Services\Google\ClassroomSyncService;
use App\Services\Google\FakeClassroomClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One Google Classroom course per subject, driven from this system.
 *
 * The tests run against an in-memory Classroom rather than Google, so they are
 * fast, offline, and exercise exactly the code the real client sits behind.
 */
class GoogleClassroomSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeClassroomClient $classroom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classroom = new FakeClassroomClient;
        $this->app->instance(ClassroomClient::class, $this->classroom);
        config(['google.classroom.domain' => 'vis.edu.ly']);
    }

    public function test_a_course_is_created_for_a_subject(): void
    {
        $admin = $this->admin();
        [, $course] = $this->classWithSubject();

        $this->withUser($admin)
            ->postJson("/api/google-classroom/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.state', 'PROVISIONED');

        $link = GoogleClassroomLink::where('course_id', $course->id)->firstOrFail();
        $this->assertNotNull($link->google_course_id);
        $this->assertStringContainsString('Mathematics', $link->name);
    }

    /**
     * The whole point of the alias: running the sync twice must not leave the
     * school with two Classroom courses for one subject.
     */
    public function test_creating_twice_reuses_the_same_course(): void
    {
        $admin = $this->admin();
        [, $course] = $this->classWithSubject();

        $this->withUser($admin)->postJson("/api/google-classroom/courses/{$course->id}")->assertOk();
        $first = GoogleClassroomLink::where('course_id', $course->id)->firstOrFail()->google_course_id;

        $this->withUser($admin)->postJson("/api/google-classroom/courses/{$course->id}")->assertOk();

        $this->assertSame(1, GoogleClassroomLink::where('course_id', $course->id)->count());
        $this->assertSame($first, GoogleClassroomLink::where('course_id', $course->id)->firstOrFail()->google_course_id);
        $this->assertCount(1, $this->classroom->courses());
    }

    /**
     * Even if the stored id is lost, the alias finds the course again.
     */
    public function test_a_lost_id_is_recovered_rather_than_duplicated(): void
    {
        [, $course] = $this->classWithSubject();
        $sync = app(ClassroomSyncService::class);

        $sync->ensureCourse($course);
        GoogleClassroomLink::where('course_id', $course->id)->update(['google_course_id' => null]);

        $sync->ensureCourse($course->fresh());

        $this->assertCount(1, $this->classroom->courses());
        $this->assertNotNull(GoogleClassroomLink::where('course_id', $course->id)->firstOrFail()->google_course_id);
    }

    public function test_the_course_is_renamed(): void
    {
        $admin = $this->admin();
        [, $course] = $this->classWithSubject();

        $this->withUser($admin)
            ->putJson("/api/google-classroom/courses/{$course->id}", ['name' => 'Maths G12 — Morning'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Maths G12 — Morning');

        $this->assertSame('Maths G12 — Morning', GoogleClassroomLink::where('course_id', $course->id)->firstOrFail()->name);
    }

    public function test_the_assigned_teacher_is_added(): void
    {
        $admin = $this->admin();
        [, $course, $teacher] = $this->classWithSubject();
        $teacher->update(['google_email' => 'mohamed@vis.edu.ly']);

        $this->withUser($admin)
            ->postJson("/api/google-classroom/courses/{$course->id}/teacher")
            ->assertOk()
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.email', 'mohamed@vis.edu.ly');

        $link = GoogleClassroomLink::where('course_id', $course->id)->firstOrFail();
        $this->assertContains('mohamed@vis.edu.ly', $this->classroom->teacherEmails($link->google_course_id));
    }

    public function test_a_teacher_without_a_workspace_address_is_refused_clearly(): void
    {
        $admin = $this->admin();
        [, $course] = $this->classWithSubject();

        // Surfaced with the reason, not silently skipped.
        $this->withUser($admin)
            ->postJson("/api/google-classroom/courses/{$course->id}/teacher")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لم يُربط بريد Google Workspace للمستخدم Teacher.');

        $this->assertSame(0, GoogleClassroomMember::where('role', 'teacher')->count());
    }

    public function test_chosen_students_are_enrolled(): void
    {
        $admin = $this->admin();
        [$section, $course] = $this->classWithSubject();
        $ali = $this->student($section, 'Ali', 'S-1', 'ali@vis.edu.ly');
        $sara = $this->student($section, 'Sara', 'S-2', 'sara@vis.edu.ly');

        $this->withUser($admin)
            ->postJson("/api/google-classroom/courses/{$course->id}/students", [
                'student_profile_ids' => [$ali->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.added', ['ali@vis.edu.ly']);

        $link = GoogleClassroomLink::where('course_id', $course->id)->firstOrFail();
        $this->assertSame(['ali@vis.edu.ly'], $this->classroom->listStudentEmails($link->google_course_id));
        $this->assertSame(1, $link->members()->where('role', 'student')->count());
    }

    public function test_an_empty_selection_enrols_the_whole_class(): void
    {
        $admin = $this->admin();
        [$section, $course] = $this->classWithSubject();
        $this->student($section, 'Ali', 'S-1', 'ali@vis.edu.ly');
        $this->student($section, 'Sara', 'S-2', 'sara@vis.edu.ly');

        $this->withUser($admin)
            ->postJson("/api/google-classroom/courses/{$course->id}/students", ['student_profile_ids' => []])
            ->assertOk()
            ->assertJsonCount(2, 'data.added');
    }

    public function test_a_student_without_an_address_is_reported_not_skipped_silently(): void
    {
        $admin = $this->admin();
        [$section, $course] = $this->classWithSubject();
        $this->student($section, 'Ali', 'S-1', 'ali@vis.edu.ly');
        $this->student($section, 'Omar', 'S-2', null);

        $this->withUser($admin)
            ->postJson("/api/google-classroom/courses/{$course->id}/students", ['student_profile_ids' => []])
            ->assertOk()
            ->assertJsonCount(1, 'data.added')
            ->assertJsonPath('data.skipped.0.reason', 'no_google_email');
    }

    public function test_enrolling_twice_does_not_add_the_student_again(): void
    {
        $admin = $this->admin();
        [$section, $course] = $this->classWithSubject();
        $this->student($section, 'Ali', 'S-1', 'ali@vis.edu.ly');

        $this->withUser($admin)->postJson("/api/google-classroom/courses/{$course->id}/students", ['student_profile_ids' => []])->assertOk();
        $this->withUser($admin)
            ->postJson("/api/google-classroom/courses/{$course->id}/students", ['student_profile_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.added', []);

        $link = GoogleClassroomLink::where('course_id', $course->id)->firstOrFail();
        $this->assertCount(1, $this->classroom->listStudentEmails($link->google_course_id));
    }

    /**
     * Where the school's admin has not granted direct-add rights, Google can
     * only invite — the roster is not final until the student accepts.
     */
    public function test_an_invitation_is_recorded_as_invited_not_active(): void
    {
        $admin = $this->admin();
        $this->classroom->inviteOnly = true;
        [$section, $course] = $this->classWithSubject();
        $this->student($section, 'Ali', 'S-1', 'ali@vis.edu.ly');

        $this->withUser($admin)
            ->postJson("/api/google-classroom/courses/{$course->id}/students", ['student_profile_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.invited', ['ali@vis.edu.ly'])
            ->assertJsonPath('data.added', []);

        $this->assertSame('INVITED', GoogleClassroomMember::where('role', 'student')->firstOrFail()->state);
    }

    /**
     * Playing with the stand-in must not leave the school thinking a subject is
     * already in Classroom once Google is really connected.
     */
    public function test_a_stand_in_course_does_not_masquerade_as_a_real_one(): void
    {
        $admin = $this->admin();
        [$section, $course] = $this->classWithSubject();
        $this->student($section, 'Ali', 'S-1', 'ali@vis.edu.ly');

        // Tried out while the integration was off.
        $this->withUser($admin)->postJson("/api/google-classroom/courses/{$course->id}")->assertOk();
        $this->withUser($admin)->postJson("/api/google-classroom/courses/{$course->id}/students", ['student_profile_ids' => []])->assertOk();

        // The school finishes the Workspace setup.
        config(['google.classroom.enabled' => true]);

        $subject = $this->withUser($admin)
            ->getJson("/api/google-classroom/classes/{$section->id}")
            ->assertOk()
            ->json('data.subjects.0');

        $this->assertFalse($subject['linked']);
        $this->assertNull($subject['google_course_id']);
        $this->assertNull($subject['enrollment_code']);
        $this->assertSame(0, $subject['students_enrolled']);
        $this->assertSame(1, $subject['students_missing']);
    }

    public function test_reconnecting_creates_the_course_for_real_and_clears_the_mark(): void
    {
        [, $course] = $this->classWithSubject();
        $sync = app(ClassroomSyncService::class);

        $sync->ensureCourse($course);
        $this->assertTrue(GoogleClassroomLink::where('course_id', $course->id)->firstOrFail()->simulated);

        // The school connects Google: a Classroom that has never seen us.
        config(['google.classroom.enabled' => true]);
        $this->classroom = new FakeClassroomClient;
        $this->app->instance(ClassroomClient::class, $this->classroom);

        app(ClassroomSyncService::class)->ensureCourse($course->fresh());

        $link = GoogleClassroomLink::where('course_id', $course->id)->firstOrFail();
        $this->assertFalse($link->simulated);
        $this->assertTrue($link->isLinked());
        // Created once, not twice: the alias still governs.
        $this->assertCount(1, $this->classroom->courses());
    }

    public function test_the_status_screen_reports_what_still_needs_doing(): void
    {
        $admin = $this->admin();
        [$section, $course] = $this->classWithSubject();
        $this->student($section, 'Ali', 'S-1', 'ali@vis.edu.ly');
        $this->student($section, 'Omar', 'S-2', null);

        $body = $this->withUser($admin)
            ->getJson("/api/google-classroom/classes/{$section->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($body['subjects'][0]['linked']);
        $this->assertSame(2, $body['subjects'][0]['students_missing']);
        $this->assertTrue($body['students'][0]['ready']);
        $this->assertFalse($body['students'][1]['ready']);
    }

    // ---- linking the address ------------------------------------------------

    public function test_an_address_is_linked_to_a_person(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $this->withUser($admin)
            ->putJson("/api/google-classroom/users/{$teacher->id}/email", ['google_email' => 'mohamed@vis.edu.ly'])
            ->assertOk()
            ->assertJsonPath('data.google_email', 'mohamed@vis.edu.ly');
    }

    public function test_an_address_outside_the_school_domain_is_refused(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();

        $this->withUser($admin)
            ->putJson("/api/google-classroom/users/{$teacher->id}/email", ['google_email' => 'someone@gmail.com'])
            ->assertStatus(422);

        $this->assertNull($teacher->refresh()->google_email);
    }

    public function test_two_people_cannot_share_one_address(): void
    {
        $admin = $this->admin();
        $first = $this->teacher();
        $second = $this->user('teacher', 'second@example.com');
        $first->update(['google_email' => 'shared@vis.edu.ly']);

        $this->withUser($admin)
            ->putJson("/api/google-classroom/users/{$second->id}/email", ['google_email' => 'shared@vis.edu.ly'])
            ->assertStatus(422);
    }

    public function test_an_address_can_be_cleared(): void
    {
        $admin = $this->admin();
        $teacher = $this->teacher();
        $teacher->update(['google_email' => 'mohamed@vis.edu.ly']);

        $this->withUser($admin)
            ->putJson("/api/google-classroom/users/{$teacher->id}/email", ['google_email' => null])
            ->assertOk();

        $this->assertNull($teacher->refresh()->google_email);
    }

    public function test_a_whole_class_is_mapped_in_one_pass(): void
    {
        $admin = $this->admin();
        [$section] = $this->classWithSubject();
        $ali = $this->student($section, 'Ali', 'S-1', null);
        $sara = $this->student($section, 'Sara', 'S-2', null);

        $this->withUser($admin)
            ->putJson('/api/google-classroom/emails', ['emails' => [
                ['user_id' => $ali->user_id, 'google_email' => 'ali@vis.edu.ly'],
                ['user_id' => $sara->user_id, 'google_email' => 'sara@vis.edu.ly'],
            ]])
            ->assertOk()
            ->assertJsonPath('data.saved', 2);

        $this->assertSame('ali@vis.edu.ly', $ali->user->refresh()->google_email);
        $this->assertSame('sara@vis.edu.ly', $sara->user->refresh()->google_email);
    }

    /**
     * A half-applied mapping would leave the admin guessing which rows landed.
     */
    public function test_one_bad_row_rejects_the_whole_batch(): void
    {
        $admin = $this->admin();
        [$section] = $this->classWithSubject();
        $ali = $this->student($section, 'Ali', 'S-1', null);
        $sara = $this->student($section, 'Sara', 'S-2', null);

        $this->withUser($admin)
            ->putJson('/api/google-classroom/emails', ['emails' => [
                ['user_id' => $ali->user_id, 'google_email' => 'ali@vis.edu.ly'],
                ['user_id' => $sara->user_id, 'google_email' => 'sara@gmail.com'],
            ]])
            ->assertStatus(422);

        $this->assertNull($ali->user->refresh()->google_email);
    }

    public function test_the_batch_refuses_to_give_two_people_one_address(): void
    {
        $admin = $this->admin();
        [$section] = $this->classWithSubject();
        $ali = $this->student($section, 'Ali', 'S-1', null);
        $sara = $this->student($section, 'Sara', 'S-2', null);

        $this->withUser($admin)
            ->putJson('/api/google-classroom/emails', ['emails' => [
                ['user_id' => $ali->user_id, 'google_email' => 'same@vis.edu.ly'],
                ['user_id' => $sara->user_id, 'google_email' => 'same@vis.edu.ly'],
            ]])
            ->assertStatus(422);

        $this->assertNull($ali->user->refresh()->google_email);
    }

    public function test_the_batch_refuses_an_address_already_used_elsewhere(): void
    {
        $admin = $this->admin();
        [$section, , $teacher] = $this->classWithSubject();
        $teacher->update(['google_email' => 'taken@vis.edu.ly']);
        $ali = $this->student($section, 'Ali', 'S-1', null);

        $this->withUser($admin)
            ->putJson('/api/google-classroom/emails', ['emails' => [
                ['user_id' => $ali->user_id, 'google_email' => 'taken@vis.edu.ly'],
            ]])
            ->assertStatus(422);

        $this->assertNull($ali->user->refresh()->google_email);
    }

    public function test_addresses_are_suggested_for_students_that_lack_one(): void
    {
        $admin = $this->admin();
        [$section] = $this->classWithSubject();
        $this->student($section, 'Ali', 'S-1', 'ali@vis.edu.ly');
        $this->student($section, 'Omar', 'A-2002', null);

        $this->withUser($admin)
            ->postJson("/api/google-classroom/classes/{$section->id}/suggest-emails", ['pattern' => 'admission_no'])
            ->assertOk()
            // Only the one missing an address is suggested.
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.google_email', 'a-2002@vis.edu.ly');
    }

    public function test_the_settings_endpoint_says_whether_it_is_live(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)
            ->getJson('/api/google-classroom/settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.domain', 'vis.edu.ly');
    }

    public function test_staff_without_settings_manage_are_kept_out(): void
    {
        $staff = $this->user('staff', 'staff@example.com');
        [$section, $course] = $this->classWithSubject();

        $this->withUser($staff)->getJson("/api/google-classroom/classes/{$section->id}")->assertForbidden();
        $this->withUser($staff)->postJson("/api/google-classroom/courses/{$course->id}")->assertForbidden();
    }

    // ---- fixtures -----------------------------------------------------------

    /**
     * @return array{0: CourseSection, 1: Course, 2: User}
     */
    private function classWithSubject(): array
    {
        $teacher = $this->teacher();
        $section = CourseSection::create([
            'section_code' => 'G12-A',
            'class_name' => 'G12',
            'academic_year' => '2026-2027',
            'term' => 'Quarter 1',
            'teacher_id' => $teacher->id,
        ]);
        $course = Course::create([
            'code' => 'MTH',
            'name' => 'Mathematics',
            'grade_level' => 'G12',
            'class_section_id' => $section->id,
            'teacher_id' => $teacher->id,
        ]);

        return [$section, $course, $teacher];
    }

    private function student(CourseSection $section, string $name, string $number, ?string $googleEmail): StudentProfile
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower($number).'@school.test',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'student',
            'is_active' => true,
            'google_email' => $googleEmail,
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

    private function teacher(): User
    {
        return $this->user('teacher', 'mohamed@example.com');
    }

    private function admin(): User
    {
        return $this->user('admin', 'admin@example.com');
    }

    private function user(string $type, string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => ucfirst($type),
                'password' => Hash::make('Str0ng!Passw0rd'),
                'user_type' => $type,
                'is_active' => true,
            ],
        );
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
