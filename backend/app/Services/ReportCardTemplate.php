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
            'show_logo' => true,
            'logo_width' => 110,

            // ---- titles ----
            'quarter_title' => 'Provisionary End of Quarter Progress Report Card',
            'semester_title' => 'Semester {semester} Progress Report Card',

            // ---- the details block ----
            'label_student_number' => 'Student Number:',
            'label_student_name' => "Student's Full Name:",
            'label_grade' => 'Grade:',
            'label_date' => 'Report Date',
            'label_school' => 'School:',
            'label_principal' => 'Principal:',
            'show_report_date' => true,

            // ---- the marks table ----
            'column_subject' => 'Subject',
            'column_marks' => "Marks
(out of 100)",
            'column_letter' => "Letter
Grade",
            'show_letter_grade' => true,

            // ---- signature ----
            'show_signature' => true,
            'signature_name' => '____________________',
            'signature_role' => 'Vice Principal, Vision International School',
            'signature_label' => "Principal's Signature",

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
        $logo = self::get()['logo_path'];

        if ($logo) {
            Storage::disk('public')->delete($logo);
        }

        SchoolSetting::set(self::KEY, null);

        return self::defaults();
    }

    /**
     * The logo as a data URI, which is what dompdf needs — it cannot fetch a URL.
     * Falls back to the bundled logo, then to nothing at all.
     */
    public static function logoDataUri(): ?string
    {
        $template = self::get();

        if (! $template['show_logo']) {
            return null;
        }

        $candidates = [];

        if ($template['logo_path']) {
            $candidates[] = Storage::disk('public')->path($template['logo_path']);
        }

        $candidates[] = public_path('images/school-logo.png');

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
    public static function logoUrl(): ?string
    {
        $template = self::get();

        if ($template['logo_path']) {
            return Storage::disk('public')->url($template['logo_path']);
        }

        return is_file(public_path('images/school-logo.png')) ? '/images/school-logo.png' : null;
    }
}
