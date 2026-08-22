<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncTeacherCoursesRequest;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherCourseController extends Controller
{
    /**
     * Every subject available to assign, grouped by class. Used when creating a
     * teacher, where there is no teacher record to read assignments from yet.
     */
    public function catalogue(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'assigned_course_ids' => [],
                'classes' => $this->classesWithSubjects($request->query('academic_year')),
            ],
        ]);
    }

    /**
     * The same catalogue, plus the subjects this teacher currently holds.
     */
    public function index(Request $request, User $user): JsonResponse
    {
        $this->ensureTeacher($user);

        return response()->json([
            'data' => [
                'assigned_course_ids' => $user->courses()->pluck('id'),
                'classes' => $this->classesWithSubjects($request->query('academic_year')),
            ],
        ]);
    }

    private function classesWithSubjects(?string $academicYear)
    {
        return CourseSection::query()
            ->with(['courses.teacher:id,name'])
            ->when($academicYear, fn ($query, $year) => $query->where('academic_year', $year))
            ->orderByDesc('academic_year')
            ->orderBy('class_name')
            ->get()
            ->map(fn (CourseSection $section) => [
                'id' => $section->id,
                'class_name' => $section->class_name ?: $section->section_code,
                'section_code' => $section->section_code,
                'academic_year' => $section->academic_year,
                'subjects' => $section->courses->map(fn (Course $course) => [
                    'id' => $course->id,
                    'code' => $course->code,
                    'name' => $course->name,
                    'teacher_id' => $course->teacher_id,
                    'teacher_name' => $course->teacher?->name,
                ])->values(),
            ])
            ->filter(fn (array $section) => count($section['subjects']) > 0)
            ->values();
    }

    /**
     * Replace this teacher's subject assignments with the given set.
     *
     * Subjects dropped from the set are unassigned; subjects held by another
     * teacher are reassigned, since a subject has exactly one teacher.
     */
    public function sync(SyncTeacherCoursesRequest $request, User $user): JsonResponse
    {
        $this->ensureTeacher($user);

        $courseIds = collect($request->validated('course_ids'))->map(fn ($id) => (int) $id)->unique();

        DB::transaction(function () use ($user, $courseIds): void {
            $user->courses()->whereNotIn('id', $courseIds)->update(['teacher_id' => null]);

            if ($courseIds->isNotEmpty()) {
                Course::whereIn('id', $courseIds)->update(['teacher_id' => $user->id]);
            }
        });

        return response()->json([
            'data' => $user->courses()->with('classSection:id,class_name,section_code,academic_year')->get(),
        ]);
    }

    private function ensureTeacher(User $user): void
    {
        abort_unless($user->user_type === 'teacher', 422, 'This user is not a teacher.');
    }
}
