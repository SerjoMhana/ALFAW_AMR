<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignCourseSectionRequest;
use App\Http\Requests\DeleteCoursesRequest;
use App\Http\Requests\BulkStoreCourseRequest;
use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\ClassPost;
use App\Models\GoogleClassroomLink;
use App\Models\GradeSubmission;
use App\Models\StudentScore;
use App\Services\GPAService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CourseController extends Controller
{
    public function __construct(private readonly GPAService $gpa) {}

    /**
     * The subjects of the active year, plus any that belong to no class at all —
     * those have no year to be out of, and this page is where they are linked
     * up or cleared away.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Course::query()
                ->where(fn ($query) => $query
                    ->whereNull('class_section_id')
                    ->orWhereHas('classSection', fn ($section) => $section->inActiveYear()))
                ->with(['classSection', 'teacher'])
                ->latest()
                ->get(),
        ]);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['is_ap'] = $validated['is_ap'] ?? false;
        $validated['credit_hours'] = $validated['credit_hours'] ?? 1;

        return response()->json([
            'data' => Course::create($validated),
        ], 201);
    }

    /**
     * Adds the same list of subjects to one class or to several.
     *
     * Grades share a syllabus more often than not, so the list is typed once
     * and written into every class chosen — each keeping its own grade level.
     */
    public function bulkStore(BulkStoreCourseRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $sections = CourseSection::whereIn('id', $validated['class_section_ids'])->get();

        $courses = DB::transaction(function () use ($validated, $sections) {
            return $sections->flatMap(function (CourseSection $section) use ($validated) {
                $gradeLevel = $this->gradeLevelFor($section);

                return collect($validated['courses'])->map(function (array $course) use ($section, $gradeLevel) {
                    $periods = isset($course['periods_per_week']) ? (int) $course['periods_per_week'] : null;

                    return Course::create([
                        'code' => strtoupper(trim($course['code'])),
                        'name' => trim($course['name']),
                        'class_section_id' => $section->id,
                        'grade_level' => $gradeLevel,
                        'is_ap' => false,
                        'has_exam' => $course['has_exam'] ?? true,
                        'periods_per_week' => $periods,
                        'credit_hours' => $periods ? ($this->gpa->creditsForPeriods($periods) ?? 1) : 1,
                    ]);
                });
            })->values();
        });

        return response()->json([
            'data' => $courses,
            'message' => sprintf(
                'أُضيفت %d مادة إلى %d فصل.',
                count($validated['courses']),
                $sections->count(),
            ),
        ], 201);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json([
            'data' => $course->load(['classSection', 'teacher']),
        ]);
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('periods_per_week', $validated) && $validated['periods_per_week']) {
            $validated['credit_hours'] = $this->gpa->creditsForPeriods((int) $validated['periods_per_week'])
                ?? $course->credit_hours;
        }

        // Moving a subject to another class moves its grade with it, unless the
        // caller said otherwise — a G11 subject dropped into G12 that still
        // reads G11 would be marked against the wrong scheme.
        if (
            array_key_exists('class_section_id', $validated)
            && (int) $validated['class_section_id'] !== (int) $course->class_section_id
            && ! array_key_exists('grade_level', $validated)
        ) {
            $validated['grade_level'] = $this->gradeLevelFor(
                CourseSection::findOrFail($validated['class_section_id']),
            );
        }

        $course->update($validated);

        return response()->json([
            'data' => $course->fresh()->load(['classSection', 'teacher']),
        ]);
    }

    /**
     * Attaches several subjects to one class in a single pass.
     *
     * Subjects created before classes existed — or imported without one — are
     * invisible to the students who take them, because a student reaches a
     * subject through their class. This is how they get reconnected without
     * editing them one at a time.
     */
    public function assignSection(AssignCourseSectionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $section = CourseSection::findOrFail($validated['class_section_id']);
        $gradeLevel = $this->gradeLevelFor($section);

        $updated = DB::transaction(fn () => Course::whereIn('id', $validated['course_ids'])
            ->update([
                'class_section_id' => $section->id,
                'grade_level' => $gradeLevel,
            ]));

        return response()->json([
            'data' => ['linked' => $updated, 'class_section_id' => $section->id],
        ]);
    }

    /**
     * What deleting these subjects would take with it.
     *
     * A subject carries its marks, its submitted grade sheets and its classroom
     * posts, so the admin sees the count before agreeing rather than a bare
     * "are you sure".
     */
    public function deletionImpact(DeleteCoursesRequest $request): JsonResponse
    {
        $ids = $request->validated('course_ids');

        $counts = [
            'courses' => Course::whereIn('id', $ids)->count(),
            'scores' => StudentScore::whereIn('course_id', $ids)->count(),
            'grade_submissions' => GradeSubmission::whereIn('course_id', $ids)->count(),
            'posts' => ClassPost::withTrashed()->whereIn('course_id', $ids)->count(),
            'classroom_links' => GoogleClassroomLink::whereIn('course_id', $ids)->count(),
        ];

        return response()->json([
            'data' => [
                'counts' => $counts,
                // The subjects themselves are what was asked for; anything else
                // is a consequence worth reading before pressing delete.
                'carries_data' => $counts['scores'] + $counts['grade_submissions']
                    + $counts['posts'] + $counts['classroom_links'] > 0,
                'names' => Course::whereIn('id', $ids)->orderBy('name')->pluck('name'),
            ],
        ]);
    }

    /**
     * Deletes several subjects at once, for clearing out ones created by
     * mistake without opening each in turn.
     */
    public function destroyMany(DeleteCoursesRequest $request): JsonResponse
    {
        $ids = $request->validated('course_ids');

        $deleted = DB::transaction(fn () => Course::whereIn('id', $ids)->delete());

        return response()->json([
            'data' => ['deleted' => $deleted],
            'message' => "حُذفت {$deleted} مادة.",
        ]);
    }

    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return response()->json(status: 204);
    }

    private function gradeLevelFor(CourseSection $section): string
    {
        $label = $section->class_name ?: $section->section_code;

        if (preg_match('/(?:G|Grade)\s*(\d{1,2})/i', $label, $matches)) {
            return 'G'.$matches[1];
        }

        return mb_substr($label, 0, 20);
    }
}
