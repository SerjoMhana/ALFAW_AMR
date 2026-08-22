<?php

namespace App\Http\Controllers;

use App\Models\GradeAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->hasPermission('view_grade_audit_logs'), 403);

        $logs = GradeAuditLog::query()
            ->with(['studentScore.studentProfile.user', 'studentScore.gradingItem', 'studentScore.courseSection', 'changedBy'])
            ->when($request->query('student_profile_id'), function ($query, $studentProfileId) {
                $query->whereHas('studentScore', fn ($scoreQuery) => $scoreQuery->where('student_profile_id', $studentProfileId));
            })
            ->when($request->query('course_section_id'), function ($query, $courseSectionId) {
                $query->whereHas('studentScore', fn ($scoreQuery) => $scoreQuery->where('course_section_id', $courseSectionId));
            })
            ->when($request->query('term'), function ($query, $term) {
                $query->whereHas('studentScore', fn ($scoreQuery) => $scoreQuery->where('term', $term));
            })
            ->latest()
            ->paginate((int) $request->query('per_page', 25));

        return response()->json($logs);
    }
}
