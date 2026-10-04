<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Services\ReportCardTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The report card's wording and look belong to the school, so the admin edits
 * them and the printed sheet follows.
 */
class ReportCardDesignerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_template_starts_at_the_schools_current_sheet(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)
            ->getJson('/api/report-card-template')
            ->assertOk()
            ->assertJsonPath('data.school_name', 'Vision International School')
            ->assertJsonPath('data.show_letter_grade', true)
            ->assertJsonStructure(['data', 'defaults', 'logo_url']);
    }

    public function test_edits_are_saved_and_read_back(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)
            ->putJson('/api/report-card-template', [
                'school_name' => 'مدرسة الرؤية الدولية',
                'quarter_title' => 'كشف درجات الفصل',
                'label_grade' => 'الصف:',
                'label_guardian' => 'ولي الأمر:',
                'accent_color' => '#aa3311',
                'show_letter_grade' => false,
                'show_grading_key' => false,
                'teacher_remarks_text' => 'Keep improving.',
                'font_size' => 14,
            ])
            ->assertOk()
            ->assertJsonPath('data.school_name', 'مدرسة الرؤية الدولية')
            ->assertJsonPath('data.show_letter_grade', false)
            ->assertJsonPath('data.label_guardian', 'ولي الأمر:')
            ->assertJsonPath('data.show_grading_key', false);

        // A second reader sees the same sheet.
        $this->assertSame('كشف درجات الفصل', ReportCardTemplate::get()['quarter_title']);
        $this->assertSame(14, ReportCardTemplate::get()['font_size']);
    }

    /**
     * Untouched fields keep their defaults rather than being blanked.
     */
    public function test_saving_one_field_leaves_the_rest_alone(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)
            ->putJson('/api/report-card-template', ['school_name' => 'Only This'])
            ->assertOk();

        $template = ReportCardTemplate::get();
        $this->assertSame('Only This', $template['school_name']);
        $this->assertSame(ReportCardTemplate::defaults()['quarter_title'], $template['quarter_title']);
        $this->assertSame(ReportCardTemplate::defaults()['signature_role'], $template['signature_role']);
    }

    /**
     * The colours land inside a stylesheet, so anything but a hex value would be
     * a way to inject CSS into every printed sheet.
     */
    public function test_a_colour_that_is_not_a_hex_value_is_refused(): void
    {
        $admin = $this->admin();

        foreach (['red', 'rgb(1,2,3)', '#fff', 'blue;} body{display:none', '#12345g'] as $bad) {
            $this->withUser($admin)
                ->putJson('/api/report-card-template', ['accent_color' => $bad])
                ->assertStatus(422);
        }

        $this->assertSame(ReportCardTemplate::defaults()['accent_color'], ReportCardTemplate::get()['accent_color']);
    }

    public function test_an_unknown_field_is_ignored(): void
    {
        $admin = $this->admin();

        $this->withUser($admin)
            ->putJson('/api/report-card-template', ['school_name' => 'Kept', 'evil' => 'dropped'])
            ->assertOk();

        $this->assertArrayNotHasKey('evil', ReportCardTemplate::get());
    }

    public function test_a_logo_is_uploaded_and_removed(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->withUser($admin)
            ->postJson('/api/report-card-template/logo', [
                'logo' => UploadedFile::fake()->image('crest.png', 200, 200),
            ])
            ->assertOk();

        $path = ReportCardTemplate::get()['logo_path'];
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->withUser($admin)
            ->deleteJson('/api/report-card-template/logo')
            ->assertOk();

        $this->assertNull(ReportCardTemplate::get()['logo_path']);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->withUser($admin)
            ->postJson('/api/report-card-template/logo', [
                'logo' => UploadedFile::fake()->create('payload.php', 20, 'text/php'),
            ])
            ->assertStatus(422);

        $this->assertNull(ReportCardTemplate::get()['logo_path']);
    }

    public function test_a_secondary_logo_is_uploaded_and_removed(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->withUser($admin)
            ->postJson('/api/report-card-template/secondary-logo', [
                'logo' => UploadedFile::fake()->image('accreditation.png', 180, 180),
            ])
            ->assertOk()
            ->assertJsonPath('data.show_secondary_logo', true);

        $path = ReportCardTemplate::get()['secondary_logo_path'];
        Storage::disk('public')->assertExists($path);

        $this->withUser($admin)->deleteJson('/api/report-card-template/secondary-logo')->assertOk();
        $this->assertNull(ReportCardTemplate::get()['secondary_logo_path']);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_third_logo_is_uploaded_and_removed(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->withUser($admin)
            ->postJson('/api/report-card-template/third-logo', [
                'logo' => UploadedFile::fake()->image('ministry.png', 180, 180),
            ])
            ->assertOk()
            ->assertJsonPath('data.show_third_logo', true);

        $path = ReportCardTemplate::get()['third_logo_path'];
        Storage::disk('public')->assertExists($path);

        $this->withUser($admin)->deleteJson('/api/report-card-template/third-logo')->assertOk();
        $this->assertNull(ReportCardTemplate::get()['third_logo_path']);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_reset_restores_every_default(): void
    {
        $admin = $this->admin();
        ReportCardTemplate::save(['school_name' => 'Changed', 'font_size' => 18]);

        $this->withUser($admin)
            ->postJson('/api/report-card-template/reset')
            ->assertOk()
            ->assertJsonPath('data.school_name', ReportCardTemplate::defaults()['school_name']);

        $this->assertSame(ReportCardTemplate::defaults()['font_size'], ReportCardTemplate::get()['font_size']);
    }

    public function test_staff_without_settings_manage_cannot_edit_it(): void
    {
        $view = Permission::create(['name' => 'settings.view', 'label' => 'View settings']);
        $staff = $this->user('staff', 'staff@example.com');
        $staff->permissions()->sync([$view->id]);

        // Reading is allowed for anyone who may see settings...
        $this->withUser($staff)->getJson('/api/report-card-template')->assertOk();

        // ...but changing the printed sheet is not.
        $this->withUser($staff)
            ->putJson('/api/report-card-template', ['school_name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame(ReportCardTemplate::defaults()['school_name'], ReportCardTemplate::get()['school_name']);
    }

    public function test_a_student_cannot_read_or_change_the_template(): void
    {
        $student = $this->user('student', 'student@example.com');

        $this->withUser($student)->getJson('/api/report-card-template')->assertForbidden();
        $this->withUser($student)->putJson('/api/report-card-template', ['school_name' => 'X'])->assertForbidden();
    }

    /**
     * The point of the whole feature: what the admin types is what gets printed.
     */
    public function test_the_printed_sheet_uses_the_saved_wording(): void
    {
        ReportCardTemplate::save([
            'school_name' => 'مدرسة الرؤية',
            'quarter_title' => 'Custom Quarter Heading',
            'label_grade' => 'Year Group:',
            'column_subject' => 'Course Name',
            'signer_two' => 'Head of School',
            'show_letter_grade' => false,
            'footer_note' => 'Issued by the registrar.',
            'grading_key_title' => 'My Grade Scale',
            'teacher_remarks_label' => 'Tutor Comment:',
            'teacher_remarks_text' => 'A custom editable comment.',
        ]);

        $html = view('pdf.quarter-report', ['reports' => [$this->sampleReport()]])->render();

        $this->assertStringContainsString('Custom Quarter Heading', $html);
        $this->assertStringContainsString('Year Group:', $html);
        $this->assertStringContainsString('Course Name', $html);
        $this->assertStringContainsString('Head of School', $html);
        $this->assertStringContainsString('Issued by the registrar.', $html);
        $this->assertStringContainsString('My Grade Scale', $html);
        $this->assertStringContainsString('Tutor Comment:', $html);
        $this->assertStringContainsString('A custom editable comment.', $html);

        // The letter column was switched off, so its default heading is gone.
        $this->assertStringNotContainsString('Letter', $html);
        // And the wording it replaced is no longer there.
        $this->assertStringNotContainsString('Provisionary End of Quarter', $html);
    }

    public function test_hiding_the_signature_and_date_removes_them_from_the_sheet(): void
    {
        ReportCardTemplate::save([
            'show_signature' => false,
            'show_report_date' => false,
            'label_date' => 'Report Date',
            'signer_two' => 'Vice Principal, Vision International School',
        ]);

        $html = view('pdf.quarter-report', ['reports' => [$this->sampleReport()]])->render();

        $this->assertStringNotContainsString('Report Date', $html);
        $this->assertStringNotContainsString('Vice Principal', $html);
    }

    /**
     * The shape the quarter blade expects, with no database behind it.
     */
    private function sampleReport(): array
    {
        return [
            'term' => 'Quarter 1',
            'student_profile' => (object) [
                'admission_no' => 'A-1',
                'student_number' => 'S-1',
                'full_name' => 'Ali Hassan',
                'user' => null,
            ],
            'class_section' => (object) ['class_name' => 'G12', 'section_code' => 'G12-A'],
            'subjects' => [
                ['name' => 'English', 'grade' => 89.4, 'letter' => 'B+'],
            ],
        ];
    }

    private function admin(): User
    {
        return $this->user('admin', 'admin@example.com');
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
