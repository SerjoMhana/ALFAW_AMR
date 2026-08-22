<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * One calendar for the whole school: the office writes it, everybody reads it.
 */
class SchoolCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user('admin', 'admin@school.test');
    }

    public function test_the_office_adds_an_event(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/calendar-events', [
                'title' => 'رحلة مدرسية',
                'kind' => 'activity',
                'starts_on' => '2026-09-10',
                'color' => '#ff8800',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'رحلة مدرسية')
            ->assertJsonPath('data.color', '#ff8800')
            // One day unless told otherwise.
            ->assertJsonPath('data.ends_on', '2026-09-10')
            ->assertJsonPath('data.days', 1);
    }

    public function test_the_colour_defaults_to_the_kind(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/calendar-events', [
                'title' => 'عطلة المولد',
                'kind' => 'holiday',
                'starts_on' => '2026-09-15',
            ])
            ->assertCreated()
            ->assertJsonPath('data.color', CalendarEvent::KIND_COLORS['holiday']);
    }

    public function test_a_colour_that_is_not_a_hex_value_is_refused(): void
    {
        // The value is written into a style attribute, so nothing else goes in.
        $this->withUser($this->admin)
            ->postJson('/api/calendar-events', [
                'title' => 'x',
                'kind' => 'activity',
                'starts_on' => '2026-09-10',
                'color' => 'red; background:url(javascript:alert(1))',
            ])
            ->assertStatus(422);
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/calendar-events', [
                'title' => 'أسبوع الامتحانات',
                'kind' => 'exam',
                'starts_on' => '2026-09-20',
                'ends_on' => '2026-09-18',
            ])
            ->assertStatus(422);
    }

    public function test_a_timed_event_keeps_its_hours_and_an_all_day_one_drops_them(): void
    {
        $this->withUser($this->admin)
            ->postJson('/api/calendar-events', [
                'title' => 'اجتماع أولياء الأمور',
                'kind' => 'meeting',
                'starts_on' => '2026-09-12',
                'all_day' => false,
                'starts_at' => '17:00',
                'ends_at' => '19:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '17:00');

        $this->withUser($this->admin)
            ->postJson('/api/calendar-events', [
                'title' => 'يوم رياضي',
                'kind' => 'activity',
                'starts_on' => '2026-09-13',
                'all_day' => true,
                'starts_at' => '08:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', null);
    }

    /**
     * A trip that starts in September and ends in October belongs on both.
     */
    public function test_an_event_spanning_two_months_appears_in_both(): void
    {
        $this->event('رحلة', '2026-09-28', '2026-10-03');

        $this->withUser($this->admin)
            ->getJson('/api/calendar-events?year=2026&month=9')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withUser($this->admin)
            ->getJson('/api/calendar-events?year=2026&month=10')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withUser($this->admin)
            ->getJson('/api/calendar-events?year=2026&month=11')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ---- who sees it, who changes it ------------------------------------------

    public function test_students_parents_and_teachers_all_read_the_calendar(): void
    {
        $this->event('يوم رياضي', '2026-09-10', '2026-09-10');

        foreach (['student', 'parent', 'teacher'] as $type) {
            $reader = $this->user($type, "{$type}@school.test");

            $this->withUser($reader)
                ->getJson('/api/calendar-events?year=2026&month=9')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('can_manage', false);
        }
    }

    public function test_a_reader_cannot_write_to_the_calendar(): void
    {
        $event = $this->event('يوم رياضي', '2026-09-10', '2026-09-10');
        $teacher = $this->user('teacher', 'teacher@school.test');

        $this->withUser($teacher)
            ->postJson('/api/calendar-events', [
                'title' => 'من عندي',
                'kind' => 'activity',
                'starts_on' => '2026-09-11',
            ])
            ->assertForbidden();

        $this->withUser($teacher)
            ->putJson("/api/calendar-events/{$event->id}", [
                'title' => 'تعديل',
                'kind' => 'activity',
                'starts_on' => '2026-09-10',
            ])
            ->assertForbidden();

        $this->withUser($teacher)->deleteJson("/api/calendar-events/{$event->id}")->assertForbidden();
        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_staff_handed_the_permission_may_write(): void
    {
        $staff = $this->user('staff', 'secretary@school.test');
        $staff->permissions()->attach(
            Permission::firstOrCreate(['name' => 'calendar.manage'], ['label' => 'Manage the school calendar'])->id,
        );

        $this->withUser($staff)
            ->postJson('/api/calendar-events', [
                'title' => 'اجتماع',
                'kind' => 'meeting',
                'starts_on' => '2026-09-14',
            ])
            ->assertCreated();

        $this->withUser($staff)
            ->getJson('/api/calendar-events?year=2026&month=9')
            ->assertJsonPath('can_manage', true);
    }

    public function test_an_event_is_edited_and_removed(): void
    {
        $event = $this->event('نشاط', '2026-09-10', '2026-09-10');

        $this->withUser($this->admin)
            ->putJson("/api/calendar-events/{$event->id}", [
                'title' => 'نشاط معدّل',
                'kind' => 'activity',
                'starts_on' => '2026-09-11',
                'ends_on' => '2026-09-12',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'نشاط معدّل')
            ->assertJsonPath('data.days', 2);

        $this->withUser($this->admin)->deleteJson("/api/calendar-events/{$event->id}")->assertOk();
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_the_calendar_can_be_filtered_to_one_kind(): void
    {
        $this->event('عطلة', '2026-09-10', '2026-09-10', 'holiday');
        $this->event('نشاط', '2026-09-11', '2026-09-11', 'activity');

        $this->withUser($this->admin)
            ->getJson('/api/calendar-events?year=2026&month=9&kind=holiday')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'عطلة');
    }

    // ---- fixtures -------------------------------------------------------------

    private function event(string $title, string $from, string $to, string $kind = 'activity'): CalendarEvent
    {
        return CalendarEvent::create([
            'title' => $title,
            'kind' => $kind,
            'color' => CalendarEvent::KIND_COLORS[$kind],
            'starts_on' => $from,
            'ends_on' => $to,
            'all_day' => true,
            'created_by' => $this->admin->id,
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
