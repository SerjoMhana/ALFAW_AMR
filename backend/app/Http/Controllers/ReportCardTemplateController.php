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
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'school_name' => ['sometimes', 'string', 'max:255'],
            'show_logo' => ['sometimes', 'boolean'],
            'logo_width' => ['sometimes', 'integer', 'min:30', 'max:300'],

            'quarter_title' => ['sometimes', 'string', 'max:255'],
            'semester_title' => ['sometimes', 'string', 'max:255'],

            'label_student_number' => ['sometimes', 'string', 'max:120'],
            'label_student_name' => ['sometimes', 'string', 'max:120'],
            'label_grade' => ['sometimes', 'string', 'max:120'],
            'label_date' => ['sometimes', 'string', 'max:120'],
            'label_school' => ['sometimes', 'string', 'max:120'],
            'label_principal' => ['sometimes', 'string', 'max:120'],
            'show_report_date' => ['sometimes', 'boolean'],

            'column_subject' => ['sometimes', 'string', 'max:120'],
            'column_marks' => ['sometimes', 'string', 'max:120'],
            'column_letter' => ['sometimes', 'string', 'max:120'],
            'show_letter_grade' => ['sometimes', 'boolean'],

            'show_signature' => ['sometimes', 'boolean'],
            'signature_name' => ['sometimes', 'string', 'max:255'],
            'signature_role' => ['sometimes', 'string', 'max:255'],
            'signature_label' => ['sometimes', 'string', 'max:255'],

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
        ]);
    }

    public function reset(): JsonResponse
    {
        return response()->json([
            'data' => ReportCardTemplate::reset(),
            'logo_url' => ReportCardTemplate::logoUrl(),
        ]);
    }
}
