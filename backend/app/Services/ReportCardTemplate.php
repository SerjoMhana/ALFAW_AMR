<?php

namespace App\Services;

use App\Models\SchoolSetting;
use Illuminate\Support\Facades\Storage;

/**
 * Everything on a report card that is the school's wording rather than the
 * student's data: the name at the top, the logo, the titles, the labels beside
 * each field, the column headings, the signature and the colours.
 *
 * Held as one JSON setting so the whole sheet is edited and saved together, and
 * so a field added here needs no migration.
 */
class ReportCardTemplate
{
    public const KEY = 'report_card_template';

    /** Where an uploaded logo lives, relative to the public disk. */
    public const LOGO_DIR = 'report-card';

    /**
     * The sheet as it reads today, so an untouched school sees no change.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            // ---- identity ----
            'school_name' => 'Vision International School',
            'logo_path' => null,          // null falls back to the bundled logo
            'secondary_logo_path' => null,
            'third_logo_path' => null,
            'show_logo' => true,
            'show_secondary_logo' => false,
            'show_third_logo' => false,
            'logo_width' => 110,
            'secondary_logo_width' => 90,
            'third_logo_width' => 90,
            'logo_label' => 'Alfawz International School',
            'secondary_logo_label' => '',
            'third_logo_label' => '',
            'school_address' => '',
            'school_phone' => '',
            'principal_name' => '',

            // ---- titles ----
            'quarter_title' => 'Provisionary End of Quarter Progress Report Card',
            'semester_title' => 'Semester {semester} Progress Report Card',
            'final_title' => 'Final Report Card',

            // ---- the details block ----
            'label_student_number' => 'Student Number:',
            'label_student_name' => "Student's Full Name:",
            'label_roll_number' => 'Roll No:',
            'label_registration_number' => 'Registration No:',
            'label_guardian' => 'Parent/Guardian:',
            'label_grade' => 'Grade:',
            'label_date' => 'Report Date',
            'label_school' => 'School:',
            'label_principal' => 'Principal:',
            'label_teacher' => 'Teacher:',
            'label_absence_total' => 'Total Days Absent:',
            'show_report_date' => true,
            'show_absence_total' => true,
            'show_roll_number' => true,
            'show_registration_number' => true,
            'show_guardian' => true,

            // ---- the marks table ----
            'column_subject' => 'Subject',
            'column_marks' => "Marks
(out of 100)",
            'column_letter' => "Letter
Grade",
            'column_credit' => 'Credit',
            'show_letter_grade' => true,

            // ---- summary, grading key and remarks ----
            'show_gpa' => true,
            'label_gpa' => 'GPA',
            'show_quarter_summary' => true,
            'label_grand_total' => 'Grand Total',
            'label_status' => 'Status',
            'label_pass' => 'PASS',
            'label_fail' => 'FAIL',
            'show_grading_key' => true,
            'grading_key_title' => 'Grading Key',
            'grading_key_text' => 'A+ = 97-100 | A = 94-96 | A- = 90-93 | B+ = 87-89 | B = 84-86 | B- = 80-83 | C+ = 77-79 | C = 74-76 | C- = 70-73 | D+ = 67-69 | D = 64-66 | D- = 60-63 | F = 0-59',
            'show_teacher_remarks' => true,
            'teacher_remarks_label' => 'Class Teacher Remarks:',
            'teacher_remarks_text' => 'Shows good understanding and steady progress. With a little more effort, can reach higher excellence.',
            'column_final_grade' => 'Final Grade',
            'column_quarter_1' => 'Quarter 1',
            'column_quarter_2' => 'Quarter 2',
            'column_quarter_3' => 'Quarter 3',
            'column_quarter_4' => 'Quarter 4',
            'column_semester_1' => 'Semester 1',
            'column_semester_2' => 'Semester 2',

            // ---- signature ----
            'show_signature' => true,
            'signature_name' => '____________________',
            'signature_role' => 'Vice Principal, Vision International School',
            'signature_label' => "Principal's Signature",
            'signer_one' => 'Principal',
            'signer_two' => 'Academic Director',
            'signer_three' => 'School Director',

            // ---- look ----
            'accent_color' => '#1f5eff',
            'header_bg' => '#e5e7eb',
            'label_bg' => '#f3f4f6',
            'border_color' => '#444444',
            'text_color' => '#172033',
            'font_size' => 12,

            // ---- footer ----
            'footer_note' => '',
        ];
    }

    /**
     * The saved sheet, with any field the admin has not set filled from the
     * defaults — so a template saved before a field existed still renders.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $stored = json_decode((string) SchoolSetting::get(self::KEY, '{}'), true);

        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function save(array $values): array
    {
        // Only known fields are kept, so a stray key cannot pollute the record.
        $clean = array_intersect_key($values, self::defaults());

        SchoolSetting::set(self::KEY, json_encode(array_merge(self::get(), $clean), JSON_UNESCAPED_UNICODE));

        return self::get();
    }

    public static function reset(): array
    {
        $template = self::get();

        foreach (['logo_path', 'secondary_logo_path', 'third_logo_path'] as $key) {
            if ($template[$key]) {
                Storage::disk('public')->delete($template[$key]);
            }
        }

        SchoolSetting::set(self::KEY, null);

        return self::defaults();
    }

    /**
     * The logo as a data URI, which is what dompdf needs — it cannot fetch a URL.
     * Falls back to the bundled logo, then to nothing at all.
     */
    public static function logoDataUri(string $slot = 'primary'): ?string
    {
        $template = self::get();
        $secondary = $slot === 'secondary';
        $third = $slot === 'third';

        if (($secondary && ! $template['show_secondary_logo'])
            || ($third && ! $template['show_third_logo'])
            || (! $secondary && ! $third && ! $template['show_logo'])) {
            return null;
        }

        $candidates = [];

        $pathKey = $third ? 'third_logo_path' : ($secondary ? 'secondary_logo_path' : 'logo_path');
        if ($template[$pathKey]) {
            $candidates[] = Storage::disk('public')->path($template[$pathKey]);
        }

        if (! $secondary && ! $third) {
            $candidates[] = public_path('images/school-logo.png');
        }

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path)) {
                $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

                return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        return null;
    }

    /**
     * The logo as a browser-reachable URL for the designer's live preview.
     */
    public static function logoUrl(string $slot = 'primary'): ?string
    {
        $template = self::get();
        $secondary = $slot === 'secondary';
        $third = $slot === 'third';
        $pathKey = $third ? 'third_logo_path' : ($secondary ? 'secondary_logo_path' : 'logo_path');

        if ($template[$pathKey]) {
            return Storage::disk('public')->url($template[$pathKey]);
        }

        return ! $secondary && ! $third && is_file(public_path('images/school-logo.png')) ? '/images/school-logo.png' : null;
    }
}
