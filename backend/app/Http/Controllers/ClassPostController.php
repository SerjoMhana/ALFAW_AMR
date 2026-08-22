<?php

namespace App\Http\Controllers;

use App\Models\ClassPost;
use App\Models\Course;
use App\Services\Classroom\ClassroomAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClassPostController extends Controller
{
    public function __construct(private readonly ClassroomAccess $access) {}

    /**
     * A post starts as a draft when files are still to come, so the class never
     * sees an announcement whose attachment has not finished uploading.
     */
    public function store(Request $request, Course $course): JsonResponse
    {
        abort_unless($this->access->canPost($request->user(), $course), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in([ClassPost::TYPE_ANNOUNCEMENT, ClassPost::TYPE_MATERIAL])],
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:'.config('classroom.stream.max_body_length')],
            'comments_enabled' => ['boolean'],
            'publish' => ['boolean'],
        ]);

        abort_if(blank($data['title'] ?? null) && blank($data['body'] ?? null), 422, 'اكتب عنواناً أو نصاً للمنشور.');

        $post = ClassPost::create([
            'course_id' => $course->id,
            'author_id' => $request->user()->id,
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'comments_enabled' => $data['comments_enabled'] ?? true,
            'published_at' => ($data['publish'] ?? true) ? now() : null,
            'academic_year' => $course->loadMissing('classSection')->classSection?->academic_year,
        ]);

        return response()->json(['data' => $post], 201);
    }

    public function update(Request $request, ClassPost $post): JsonResponse
    {
        $this->authorizeWrite($request, $post);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:'.config('classroom.stream.max_body_length')],
            'comments_enabled' => ['boolean'],
        ]);

        $post->update($data);

        return response()->json(['data' => $post->fresh()]);
    }

    /**
     * Publishing is separate so the composer can attach the files first.
     */
    public function publish(Request $request, ClassPost $post): JsonResponse
    {
        $this->authorizeWrite($request, $post);

        $post->update(['published_at' => $post->published_at ?? now()]);

        return response()->json(['data' => $post->fresh()]);
    }

    public function pin(Request $request, ClassPost $post): JsonResponse
    {
        abort_unless($this->access->canModerate($request->user(), $post->course), 403);

        $post->update(['pinned_at' => $post->pinned_at ? null : now()]);

        return response()->json(['data' => ['is_pinned' => $post->fresh()->isPinned()]]);
    }

    /**
     * Soft deleted: a post pulled by mistake takes its comments and files with
     * it, and a school should be able to get them back.
     */
    public function destroy(Request $request, ClassPost $post): JsonResponse
    {
        $this->authorizeWrite($request, $post);

        $post->delete();

        return response()->json(['message' => 'تم حذف المنشور.']);
    }

    private function authorizeWrite(Request $request, ClassPost $post): void
    {
        $user = $request->user();
        $post->loadMissing('course');

        // Authorship alone is not enough: a teacher moved off the subject keeps
        // nothing but the record of having written it.
        abort_unless(
            ($post->author_id === $user->id && $this->access->canRead($user, $post->course))
                || $this->access->canModerate($user, $post->course),
            403,
        );
    }
}
