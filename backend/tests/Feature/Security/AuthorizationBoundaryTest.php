<?php

namespace Tests\Feature\Security;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\ParentGuardian;
use App\Models\Permission;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesGradingFixtures;
use Tests\TestCase;

/**
 * Holding a valid token is not the same as being allowed. Every role must stay
 * inside its own data, and nobody may promote themselves by editing a payload.
 */
class AuthorizationBoundaryTest extends TestCase
{
    use CreatesGradingFixtures;
    use RefreshDatabase;

    public function test_a_student_cannot_reach_administrative_data(): void
    {
        $student = $this->user('student', 'student@example.com');

        foreach (['/api/students', '/api/users', '/api/teachers', '/api/finance/students', '/api/grading-structure'] as $path) {
            $this->withUser($student)->getJson($path)->assertForbidden();
        }
    }

    public function test_a_teacher_cannot_reach_administrative_data(): void
    {
        $teacher = $this->user('teacher', 'teacher@example.com');

        foreach (['/api/users', '/api/finance/students', '/api/permissions'] as $path) {
            $this->withUser($teacher)->getJson($path)->assertForbidden();
        }
    }

    public function test_a_parent_cannot_read_another_parents_child(): void
    {
        [$mine, $theirs] = $this->twoFamilies();

        $this->withUser($mine['parentUser'])
            ->getJson("/api/parent/children/{$mine['student']->id}/grades?term=Quarter%201")
            ->assertOk();

        // Same shape of request, someone else's child. Refused either as
        // forbidden or as not-found; both keep the record out of reach, and 404
        // additionally hides that the child exists at all.
        $this->assertContains(
            $this->withUser($mine['parentUser'])
                ->getJson("/api/parent/children/{$theirs['student']->id}/grades?term=Quarter%201")
                ->status(),
            [403, 404],
        );

        $this->assertContains(
            $this->withUser($mine['parentUser'])
                ->getJson("/api/parent/children/{$theirs['student']->id}/balance")
                ->status(),
            [403, 404],
        );
    }

    public function test_a_student_cannot_read_another_students_record(): void
    {
        $studentA = $this->user('student', 'a@example.com');
        $profileB = $this->profileFor($this->user('student', 'b@example.com'), 'S-B');

        $this->withUser($studentA)
            ->getJson("/api/students/{$profileB->id}")
            ->assertForbidden();
    }

    /**
     * Mass assignment is the classic way a normal account promotes itself.
     */
    public function test_a_user_cannot_make_themselves_an_admin(): void
    {
        $staff = $this->user('staff', 'staff@example.com');

        $this->withUser($staff)
            ->putJson("/api/users/{$staff->id}", ['user_type' => 'admin'])
            ->assertForbidden();

        $this->assertSame('staff', $staff->refresh()->user_type);
    }

    public function test_a_staff_member_cannot_grant_themselves_permissions(): void
    {
        Permission::create(['name' => 'users.view', 'label' => 'View users']);
        $manage = Permission::create(['name' => 'users.manage', 'label' => 'Manage users']);
        $staff = $this->user('staff', 'staff@example.com');

        $this->withUser($staff)
            ->putJson("/api/staff-permissions/{$staff->id}", ['permissions' => [$manage->id]])
            ->assertForbidden();

        $this->assertSame(0, $staff->permissions()->count());
    }

    /**
     * Separation of duties: the person who asks for a discount must not be the
     * person who approves it.
     */
    public function test_requesting_a_discount_does_not_confer_approving_it(): void
    {
        $request = Permission::create(['name' => 'finance.discounts.request', 'label' => 'Request']);
        Permission::create(['name' => 'finance.discounts.approve', 'label' => 'Approve']);
        $clerk = $this->user('staff', 'clerk@example.com');
        $clerk->permissions()->sync([$request->id]);

        $this->withUser($clerk)->getJson('/api/finance/discount-requests')->assertForbidden();
    }

    public function test_recording_a_payment_does_not_confer_voiding_one(): void
    {
        $record = Permission::create(['name' => 'finance.payments.record', 'label' => 'Record']);
        Permission::create(['name' => 'finance.payments.void', 'label' => 'Void']);
        $cashier = $this->user('staff', 'cashier@example.com');
        $cashier->permissions()->sync([$record->id]);

        // Refused either way; Laravel resolves the route model before the
        // route's own middleware, so a missing id answers 404 rather than 403.
        $this->assertContains(
            $this->withUser($cashier)->postJson('/api/finance/payments/1/void', ['reason' => 'x'])->status(),
            [403, 404],
        );
    }

    public function test_a_deactivated_users_existing_token_stops_working(): void
    {
        $staff = $this->user('staff', 'staff@example.com');
        $token = $staff->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertOk();

        $staff->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    /**
     * @return array{0: array{parentUser: User, student: StudentProfile}, 1: array{parentUser: User, student: StudentProfile}}
     */
    private function twoFamilies(): array
    {
        // Grades are only readable while the quarter is open.
        $this->openTerm('2025-2026', 'Quarter 1');

        $teacher = $this->user('teacher', 'teacher@example.com');
        $section = CourseSection::create([
            'teacher_id' => $teacher->id,
            'section_code' => 'G1-A',
            'class_name' => 'G1',
            'academic_year' => '2025-2026',
            'term' => 'Quarter 1',
        ]);
        Course::create([
            'code' => 'G1MTH', 'name' => 'Maths', 'grade_level' => 'G1',
            'class_section_id' => $section->id, 'teacher_id' => $teacher->id,
        ]);

        $families = [];

        foreach (['one', 'two'] as $index => $label) {
            $parentUser = $this->user('parent', "parent-{$label}@example.com");
            $student = $this->profileFor($this->user('student', "child-{$label}@example.com"), 'S-'.strtoupper($label));
            $student->update(['section_id' => $section->id]);
            Enrollment::create([
                'student_profile_id' => $student->id,
                'course_section_id' => $section->id,
                'status' => 'active',
            ]);

            $guardian = ParentGuardian::create([
                'user_id' => $parentUser->id,
                'parent_admission_no' => 'PA-'.strtoupper($label),
                'first_name' => 'Parent',
                'last_name' => $label,
                'full_name' => 'Parent '.$label,
                'relation' => 'father',
            ]);
            $guardian->students()->attach($student->id, ['relation' => 'father', 'is_primary' => true]);

            $families[] = ['parentUser' => $parentUser, 'student' => $student];
        }

        return $families;
    }

    private function profileFor(User $user, string $number): StudentProfile
    {
        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => $number,
            'full_name' => $user->name,
            'grade_level' => 'G1',
            'academic_year' => '2025-2026',
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
