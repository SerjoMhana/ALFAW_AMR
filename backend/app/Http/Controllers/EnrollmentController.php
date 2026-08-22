<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnrollmentRequest;
use App\Http\Requests\UpdateEnrollmentRequest;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;

class EnrollmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Enrollment::with(['studentProfile.user', 'courseSection.courses', 'courseSection.teacher'])
                ->latest()
                ->get(),
        ]);
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = Enrollment::create([
            ...$request->validated(),
            'status' => $request->input('status', 'active'),
        ]);

        return response()->json([
            'data' => $enrollment->load(['studentProfile.user', 'courseSection.courses']),
        ], 201);
    }

    public function show(Enrollment $enrollment): JsonResponse
    {
        return response()->json([
            'data' => $enrollment->load(['studentProfile.user', 'courseSection.courses', 'courseSection.teacher']),
        ]);
    }

    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment->update($request->validated());

        return response()->json([
            'data' => $enrollment->load(['studentProfile.user', 'courseSection.courses']),
        ]);
    }

    public function destroy(Enrollment $enrollment): JsonResponse
    {
        $enrollment->delete();

        return response()->json(status: 204);
    }
}
