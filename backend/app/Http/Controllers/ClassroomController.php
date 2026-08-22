<?php

namespace App\Http\Controllers;

use App\Models\ClassPost;
use App\Models\ClassPostView;
use App\Models\Course;
use App\Services\Classroom\ClassroomAccess;
use App\Services\Classroom\ClassroomStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The subject wall: what a teacher posts and a class reads.
 */
class ClassroomController extends Controller
{
    public function __construct(
        private readonly ClassroomAccess $access,
        private readonly ClassroomStream $stream,
    ) {}

    /**
     * The subjects this person may open, newest-first by how much is unread.
     */
    public function courses(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->access->coursesFor($request->user())]);
    }

    public function stream(Request $request, Course $course): JsonResponse
    {
        $this->authorizeRead($request, $course);

        $request->validate(['before' => ['nullable', 'integer', 'min:1']]);

        return response()->json([
            'data' => $this->stream->page(
                $course->load(['classSection:id,class_name,section_code,academic_year', 'teacher:id,name']),
                $request->user(),
                $request->integer('before') ?: null,
            ),
        ]);
    }

    /**
     * Records how far this person has read, as one moving mark rather than a
     * row per post — the difference between a few thousand rows and millions.
     */
    public function markSeen(Request $request, Course $course): JsonResponse
    {
        $this->authorizeRead($request, $course);

        $latest = ClassPost::where('course_id', $course->id)->published()->max('id') ?? 0;

        ClassPostView::updateOrCreate(
            ['user_id' => $request->user()->id, 'course_id' => $course->id],
            ['last_seen_post_id' => $latest, 'seen_at' => now()],
        );

        return response()->json(['data' => ['last_seen_post_id' => $latest]]);
    }

    private function authorizeRead(Request $request, Course $course): void
    {
        abort_unless($this->access->canRead($request->user(), $course), 403);
    }
}
