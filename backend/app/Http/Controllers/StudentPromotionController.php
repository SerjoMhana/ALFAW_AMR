<?php

namespace App\Http\Controllers;

use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Services\StudentPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentPromotionController extends Controller
{
    public function __construct(private readonly StudentPromotionService $promotions) {}

    /**
     * Classes available as a promotion source, with how many students sit in each.
     */
    public function context(Request $request): JsonResponse
    {
        $academicYear = $request->query('academic_year');

        $sections = CourseSection::query()
            ->when($academicYear, fn ($query) => $query->where('academic_year', $academicYear))
            ->orderByDesc('academic_year')
            ->orderBy('class_name')
            ->get();

        $counts = Enrollment::query()
            ->whereIn('course_section_id', $sections->pluck('id'))
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->selectRaw('course_section_id, COUNT(DISTINCT student_profile_id) as total')
            ->groupBy('course_section_id')
            ->pluck('total', 'course_section_id');

        return response()->json([
            'data' => $sections->map(fn (CourseSection $section) => [
                'id' => $section->id,
                'class_name' => $section->class_name ?: $section->section_code,
                'section_code' => $section->section_code,
                'academic_year' => $section->academic_year,
                'grade_number' => $this->promotions->gradeNumberFor($section),
                'students_count' => (int) ($counts[$section->id] ?? 0),
            ])->values(),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

        return response()->json([
            'data' => $this->promotions->preview(
                CourseSection::findOrFail($validated['course_section_id']),
                $validated['target_academic_year'],
                isset($validated['target_section_id'])
                    ? CourseSection::find($validated['target_section_id'])
                    : null,
            ),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, applying: true);

        return response()->json([
            'data' => $this->promotions->apply(
                CourseSection::findOrFail($validated['course_section_id']),
                $validated['target_academic_year'],
                $request->user(),
                $validated['student_profile_ids'] ?? [],
                isset($validated['target_section_id'])
                    ? CourseSection::find($validated['target_section_id'])
                    : null,
            ),
        ]);
    }

    private function validatePayload(Request $request, bool $applying = false): array
    {
        return $request->validate([
            'course_section_id' => ['required', 'integer', 'exists:course_sections,id'],
            'target_academic_year' => ['required', 'string', 'max:20'],
            'target_section_id' => ['nullable', 'integer', 'exists:course_sections,id'],
            'student_profile_ids' => [$applying ? 'sometimes' : 'prohibited', 'array'],
            'student_profile_ids.*' => ['integer', 'exists:student_profiles,id'],
        ]);
    }
}
