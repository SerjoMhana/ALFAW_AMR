<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCourseSectionRequest;
use App\Http\Requests\UpdateCourseSectionRequest;
use App\Models\CourseSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseSectionController extends Controller
{
    /**
     * The classes of the year the school is working in. Activating another year
     * is how a past one is looked at; nothing else brings it back.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CourseSection::inActiveYear()
                ->with(['courses', 'teacher'])
                ->withCount(['enrollments', 'courses'])
                ->latest()
                ->get(),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'data' => CourseSection::inActiveYear()
                ->where('teacher_id', $request->user()->id)
                ->latest()
                ->get(),
        ]);
    }

    public function store(StoreCourseSectionRequest $request): JsonResponse
    {
        $data = $this->normalizeSectionData($request->validated());
        $courseSection = CourseSection::create($data);

        return response()->json([
            'data' => $courseSection->load(['courses', 'teacher']),
        ], 201);
    }

    public function show(CourseSection $courseSection): JsonResponse
    {
        return response()->json([
            'data' => $courseSection->load(['courses', 'teacher', 'enrollments.studentProfile.user']),
        ]);
    }

    public function update(UpdateCourseSectionRequest $request, CourseSection $courseSection): JsonResponse
    {
        $courseSection->update($this->normalizeSectionData($request->validated()));

        return response()->json([
            'data' => $courseSection->load(['courses', 'teacher']),
        ]);
    }

    public function destroy(CourseSection $courseSection): JsonResponse
    {
        $courseSection->delete();

        return response()->json(status: 204);
    }

    private function normalizeSectionData(array $data): array
    {
        // No teacher is a valid answer: a class is often created before the
        // school decides who teaches it, and inventing an account to fill the
        // column would put a real login nobody asked for into the system.
        $data['term'] ??= 'Quarter 1';

        return $data;
    }

}
