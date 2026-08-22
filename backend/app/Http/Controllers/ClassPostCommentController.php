<?php

namespace App\Http\Controllers;

use App\Models\ClassPost;
use App\Models\ClassPostComment;
use App\Services\Classroom\ClassroomAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassPostCommentController extends Controller
{
    public function __construct(private readonly ClassroomAccess $access) {}

    /**
     * The full thread of one post, oldest first, a page at a time — the stream
     * itself only carries the last few.
     */
    public function index(Request $request, ClassPost $post): JsonResponse
    {
        abort_unless($this->access->canRead($request->user(), $post->loadMissing('course')->course), 403);

        $request->validate(['after' => ['nullable', 'integer', 'min:0']]);

        $canModerate = $this->access->canModerate($request->user(), $post->course);
        $size = 50;

        $rows = ClassPostComment::query()
            ->with('user:id,name,user_type')
            ->where('class_post_id', $post->id)
            ->when($request->integer('after'), fn ($query, $after) => $query->where('id', '>', $after))
            ->orderBy('id')
            ->limit($size + 1)
            ->get();

        $hasMore = $rows->count() > $size;
        $comments = $rows->take($size);

        return response()->json([
            'data' => $comments->map(fn (ClassPostComment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user' => ['id' => $comment->user?->id, 'name' => $comment->user?->name],
                'created_at' => $comment->created_at?->toIso8601String(),
                'can_delete' => $canModerate || $comment->user_id === $request->user()->id,
            ])->values(),
            'next_cursor' => $hasMore ? $comments->last()?->id : null,
        ]);
    }

    public function store(Request $request, ClassPost $post): JsonResponse
    {
        $post->loadMissing('course');

        abort_unless($this->access->canComment($request->user(), $post), 403);
        abort_unless($post->isPublished(), 422, 'لا يمكن التعليق على منشور غير منشور.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.config('classroom.stream.max_comment_length')],
        ]);

        $comment = ClassPostComment::create([
            'class_post_id' => $post->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        // Kept on the post so the stream never has to count.
        $post->increment('comments_count');

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
                'created_at' => $comment->created_at?->toIso8601String(),
                'can_delete' => true,
            ],
        ], 201);
    }

    public function destroy(Request $request, ClassPostComment $comment): JsonResponse
    {
        $comment->loadMissing('post.course');
        $user = $request->user();

        abort_unless(
            $comment->user_id === $user->id || $this->access->canModerate($user, $comment->post->course),
            403,
        );

        $comment->delete();
        // Guarded inside the statement so two deletions at once cannot both
        // read the same value and take it below zero — which MySQL rejects
        // outright on an unsigned column, 500ing the request.
        ClassPost::whereKey($comment->class_post_id)
            ->where('comments_count', '>', 0)
            ->decrement('comments_count');

        return response()->json(['message' => 'تم حذف التعليق.']);
    }
}
