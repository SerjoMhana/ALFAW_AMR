<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class AcademicLookupController extends Controller
{
    public function users(): JsonResponse
    {
        return response()->json([
            'teachers' => User::query()
                ->where('user_type', 'teacher')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'students' => User::query()
                ->where('user_type', 'student')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }
}
