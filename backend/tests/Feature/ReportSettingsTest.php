<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReportSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_defaults_to_the_built_in_letter(): void
    {
        $admin = $this->user('admin', 'admin@example.com');

        $this->withUser($admin)
            ->getJson('/api/report-settings')
            ->assertOk()
            ->assertJsonPath(
                'data.semester_report_message',
                SchoolSetting::DEFAULT_SEMESTER_REPORT_MESSAGE,
            );
    }

    public function test_admin_can_change_the_message(): void
    {
        $admin = $this->user('admin', 'admin@example.com');

        $this->withUser($admin)
            ->putJson('/api/report-settings', ['semester_report_message' => 'كلمة المدير الجديدة'])
            ->assertOk()
            ->assertJsonPath('data.semester_report_message', 'كلمة المدير الجديدة');

        $this->assertSame('كلمة المدير الجديدة', SchoolSetting::semesterReportMessage());
    }

    public function test_message_can_be_cleared_to_hide_it_from_the_report(): void
    {
        $admin = $this->user('admin', 'admin@example.com');

        $this->withUser($admin)
            ->putJson('/api/report-settings', ['semester_report_message' => ''])
            ->assertOk()
            ->assertJsonPath('data.semester_report_message', '');

        $this->assertSame('', SchoolSetting::semesterReportMessage());
    }

    public function test_staff_without_manage_permission_cannot_change_the_message(): void
    {
        $view = Permission::create(['name' => 'settings.view', 'label' => 'View settings']);
        $staff = $this->user('staff', 'staff@example.com');
        $staff->permissions()->sync([$view->id]);

        $this->withUser($staff)->getJson('/api/report-settings')->assertOk();

        $this->withUser($staff)
            ->putJson('/api/report-settings', ['semester_report_message' => 'محاولة'])
            ->assertForbidden();

        $this->assertSame(
            SchoolSetting::DEFAULT_SEMESTER_REPORT_MESSAGE,
            SchoolSetting::semesterReportMessage(),
        );
    }

    private function withUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
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
