<?php

namespace App\Http\Controllers;

use App\Models\SchoolSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'semester_report_message' => SchoolSetting::semesterReportMessage(),
                'default_semester_report_message' => SchoolSetting::DEFAULT_SEMESTER_REPORT_MESSAGE,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'semester_report_message' => ['present', 'nullable', 'string', 'max:5000'],
        ]);

        SchoolSetting::set(
            SchoolSetting::SEMESTER_REPORT_MESSAGE,
            $validated['semester_report_message'] ?? '',
        );

        return $this->index();
    }
}
