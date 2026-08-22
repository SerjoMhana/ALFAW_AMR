<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Services\GPAService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GPAController extends Controller
{
    public function __construct(private readonly GPAService $gpa) {}

    public function student(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        abort_unless($studentProfile, 404, 'Student profile not found.');

        return response()->json([
            'term_gpa' => $this->gpa->termGpa(
                $studentProfile,
                $request->query('term', 'Quarter 1'),
                $request->query('academic_year'),
            ),
        ]);
    }

    public function admin(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        abort_unless($request->user()->isAdmin() || $request->user()->hasPermission('students.view'), 403);

        return response()->json([
            'term_gpa' => $this->gpa->termGpa(
                $studentProfile,
                $request->query('term', 'Quarter 1'),
                $request->query('academic_year'),
            ),
        ]);
    }
}
