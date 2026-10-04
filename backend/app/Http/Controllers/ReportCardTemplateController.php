<?php

namespace App\Http\Controllers;

use App\Services\ReportCardTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportCardTemplateController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => ReportCardTemplate::get(),
            'defaults' => ReportCardTemplate::defaults(),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => ReportCardTemplate::logoUrl('secondary'),
            'third_logo_url' => ReportCardTemplate::logoUrl('third'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_name' => ['sometimes', 'string', 'max:255'],
            'show_logo' => ['sometimes', 'boolean'],
            'logo_width' => ['sometimes', 'integer', 'min:30', 'max:300'],
            'show_secondary_logo' => ['sometimes', 'boolean'],
            'secondary_logo_width' => ['sometimes', 'integer', 'min:30', 'max:300'],
            'show_third_logo' => ['sometimes', 'boolean'],
            'third_logo_width' => ['sometimes', 'integer', 'min:30', 'max:300'],
            'logo_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secondary_logo_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'third_logo_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'school_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'school_phone' => ['sometimes', 'nullable', 'string', 'max:120'],
            'principal_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'quarter_title' => ['sometimes', 'string', 'max:255'],
            'semester_title' => ['sometimes', 'string', 'max:255'],
            'final_title' => ['sometimes', 'string', 'max:255'],

            'label_student_number' => ['sometimes', 'string', 'max:120'],
            'label_student_name' => ['sometimes', 'string', 'max:120'],
            'label_roll_number' => ['sometimes', 'string', 'max:120'],
            'label_registration_number' => ['sometimes', 'string', 'max:120'],
            'label_guardian' => ['sometimes', 'string', 'max:120'],
            'label_grade' => ['sometimes', 'string', 'max:120'],
            'label_date' => ['sometimes', 'string', 'max:120'],
            'label_school' => ['sometimes', 'string', 'max:120'],
            'label_principal' => ['sometimes', 'string', 'max:120'],
            'label_teacher' => ['sometimes', 'string', 'max:120'],
            'label_absence_total' => ['sometimes', 'string', 'max:120'],
            'show_report_date' => ['sometimes', 'boolean'],
            'show_absence_total' => ['sometimes', 'boolean'],
            'show_roll_number' => ['sometimes', 'boolean'],
            'show_registration_number' => ['sometimes', 'boolean'],
            'show_guardian' => ['sometimes', 'boolean'],

            'column_subject' => ['sometimes', 'string', 'max:120'],
            'column_marks' => ['sometimes', 'string', 'max:120'],
            'column_letter' => ['sometimes', 'string', 'max:120'],
            'column_credit' => ['sometimes', 'string', 'max:120'],
            'show_letter_grade' => ['sometimes', 'boolean'],
            'show_gpa' => ['sometimes', 'boolean'],
            'label_gpa' => ['sometimes', 'string', 'max:120'],
            'show_quarter_summary' => ['sometimes', 'boolean'],
            'label_grand_total' => ['sometimes', 'string', 'max:120'],
            'label_status' => ['sometimes', 'string', 'max:120'],
            'label_pass' => ['sometimes', 'string', 'max:120'],
            'label_fail' => ['sometimes', 'string', 'max:120'],
            'show_grading_key' => ['sometimes', 'boolean'],
            'grading_key_title' => ['sometimes', 'string', 'max:255'],
            'grading_key_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'show_teacher_remarks' => ['sometimes', 'boolean'],
            'teacher_remarks_label' => ['sometimes', 'string', 'max:255'],
            'teacher_remarks_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'column_final_grade' => ['sometimes', 'string', 'max:120'],
            'column_quarter_1' => ['sometimes', 'string', 'max:120'],
            'column_quarter_2' => ['sometimes', 'string', 'max:120'],
            'column_quarter_3' => ['sometimes', 'string', 'max:120'],
            'column_quarter_4' => ['sometimes', 'string', 'max:120'],
            'column_semester_1' => ['sometimes', 'string', 'max:120'],
            'column_semester_2' => ['sometimes', 'string', 'max:120'],

            'show_signature' => ['sometimes', 'boolean'],
            'signature_name' => ['sometimes', 'string', 'max:255'],
            'signature_role' => ['sometimes', 'string', 'max:255'],
            'signature_label' => ['sometimes', 'string', 'max:255'],
            'signer_one' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signer_two' => ['sometimes', 'nullable', 'string', 'max:255'],
            'signer_three' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Hex only: these land inside a stylesheet, so anything else would
            // be a way to inject CSS into every printed sheet.
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'header_bg' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'label_bg' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'border_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'text_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_size' => ['sometimes', 'integer', 'min:8', 'max:20'],

            'footer_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => ReportCardTemplate::save($data),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => ReportCardTemplate::logoUrl('secondary'),
            'third_logo_url' => ReportCardTemplate::logoUrl('third'),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);

        $template = ReportCardTemplate::get();

        // Replace rather than accumulate: the old file has no other use.
        if ($template['logo_path']) {
            Storage::disk('public')->delete($template['logo_path']);
        }

        $path = $request->file('logo')->store(ReportCardTemplate::LOGO_DIR, 'public');

        ReportCardTemplate::save(['logo_path' => $path]);

        return response()->json([
            'data' => ReportCardTemplate::get(),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => ReportCardTemplate::logoUrl('secondary'),
            'third_logo_url' => ReportCardTemplate::logoUrl('third'),
        ]);
    }

    public function uploadSecondaryLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048']]);
        $template = ReportCardTemplate::get();

        if ($template['secondary_logo_path']) {
            Storage::disk('public')->delete($template['secondary_logo_path']);
        }

        $path = $request->file('logo')->store(ReportCardTemplate::LOGO_DIR, 'public');
        ReportCardTemplate::save(['secondary_logo_path' => $path, 'show_secondary_logo' => true]);

        return response()->json([
            'data' => ReportCardTemplate::get(),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => ReportCardTemplate::logoUrl('secondary'),
            'third_logo_url' => ReportCardTemplate::logoUrl('third'),
        ]);
    }

    public function uploadThirdLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048']]);
        $template = ReportCardTemplate::get();
        if ($template['third_logo_path']) Storage::disk('public')->delete($template['third_logo_path']);
        $path = $request->file('logo')->store(ReportCardTemplate::LOGO_DIR, 'public');
        ReportCardTemplate::save(['third_logo_path' => $path, 'show_third_logo' => true]);

        return response()->json([
            'data' => ReportCardTemplate::get(),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => ReportCardTemplate::logoUrl('secondary'),
            'third_logo_url' => ReportCardTemplate::logoUrl('third'),
        ]);
    }

    public function destroyLogo(): JsonResponse
    {
        $template = ReportCardTemplate::get();

        if ($template['logo_path']) {
            Storage::disk('public')->delete($template['logo_path']);
            ReportCardTemplate::save(['logo_path' => null]);
        }

        return response()->json([
            'data' => ReportCardTemplate::get(),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => ReportCardTemplate::logoUrl('secondary'),
            'third_logo_url' => ReportCardTemplate::logoUrl('third'),
        ]);
    }

    public function destroySecondaryLogo(): JsonResponse
    {
        $template = ReportCardTemplate::get();

        if ($template['secondary_logo_path']) {
            Storage::disk('public')->delete($template['secondary_logo_path']);
            ReportCardTemplate::save(['secondary_logo_path' => null, 'show_secondary_logo' => false]);
        }

        return response()->json([
            'data' => ReportCardTemplate::get(),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => null,
            'third_logo_url' => ReportCardTemplate::logoUrl('third'),
        ]);
    }

    public function destroyThirdLogo(): JsonResponse
    {
        $template = ReportCardTemplate::get();
        if ($template['third_logo_path']) {
            Storage::disk('public')->delete($template['third_logo_path']);
            ReportCardTemplate::save(['third_logo_path' => null, 'show_third_logo' => false]);
        }

        return response()->json([
            'data' => ReportCardTemplate::get(),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => ReportCardTemplate::logoUrl('secondary'),
            'third_logo_url' => null,
        ]);
    }

    public function reset(): JsonResponse
    {
        return response()->json([
            'data' => ReportCardTemplate::reset(),
            'logo_url' => ReportCardTemplate::logoUrl(),
            'secondary_logo_url' => ReportCardTemplate::logoUrl('secondary'),
            'third_logo_url' => ReportCardTemplate::logoUrl('third'),
        ]);
    }
}
