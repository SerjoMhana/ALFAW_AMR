<?php

namespace App\Services\Classroom;

use App\Models\ClassPost;
use App\Models\ClassPostAttachment;
use App\Models\ClassPostComment;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads a subject's wall a page at a time.
 *
 * Paging is by cursor rather than by page number: `?before=<id>` walks straight
 * down the (course_id, id) index, so the thousandth page costs the same as the
 * first. Offset paging would make the school's oldest posts the slowest to
 * reach, which is exactly backwards.
 */
class ClassroomStream
{
    public function __construct(private readonly ClassroomAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function page(Course $course, User $viewer, ?int $before = null): array
    {
        $size = (int) config('classroom.stream.page_size', 20);
        $canModerate = $this->access->canModerate($viewer, $course);

        $query = ClassPost::query()
            ->where('course_id', $course->id)
            // A draft is the author's own until they publish it.
            ->when(! $canModerate, fn ($rows) => $rows->published())
            ->when($before, fn ($rows, $cursor) => $rows->where('id', '<', $cursor))
            // Pinned posts are lifted out of the flow entirely rather than
            // shown twice — once at the top and again in their old place.
            ->whereNull('pinned_at')
            ->orderByDesc('id')
            ->limit($size + 1);

        $rows = $query->get();
        $hasMore = $rows->count() > $size;
        $posts = $rows->take($size);

        // Empty but still an Eloquent collection, so concatenating the page
        // below keeps the type that can eager-load.
        $pinned = $before ? EloquentCollection::make() : ClassPost::query()
            ->where('course_id', $course->id)
            ->whereNotNull('pinned_at')
            ->when(! $canModerate, fn ($rows) => $rows->published())
            ->orderByDesc('pinned_at')
            ->get();

        $all = $pinned->concat($posts);
        $this->hydrate($all);

        return [
            'course' => [
                'id' => $course->id,
                'name' => $course->name,
                'code' => $course->code,
                'class_name' => $course->classSection?->class_name ?: $course->classSection?->section_code,
                'academic_year' => $course->classSection?->academic_year,
                'teacher' => $course->teacher?->name,
                'can_post' => $this->access->canPost($viewer, $course),
            ],
            'posts' => $all->map(fn (ClassPost $post) => $this->present($post, $viewer, $canModerate))->values(),
            // Null when the end is reached, so the page knows to stop asking.
            'next_cursor' => $hasMore ? $posts->last()?->id : null,
        ];
    }

    /**
     * Loads the authors, files and preview comments for one page of posts —
     * three queries for the whole page rather than three per post.
     *
     * @param  EloquentCollection<int, ClassPost>  $posts
     */
    private function hydrate(EloquentCollection $posts): void
    {
        if ($posts->isEmpty()) {
            return;
        }

        $posts->load(['author:id,name,user_type', 'attachments']);

        $preview = (int) config('classroom.stream.comment_preview', 3);
        $ids = $posts->pluck('id');

        /*
         * The newest few comments of every post on the page, in one pass.
         *
         * Ranking inside the query matters: fetching them all and slicing in
         * PHP would drag a post's entire comment history across for the sake of
         * showing three of them.
         */
        $ranked = ClassPostComment::query()
            ->selectRaw('id, row_number() over (partition by class_post_id order by id desc) as rank')
            ->whereIn('class_post_id', $ids)
            ->whereNull('deleted_at');

        $previewIds = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rank', '<=', $preview)
            ->pluck('id');

        $comments = ClassPostComment::query()
            ->with('user:id,name,user_type')
            ->whereIn('id', $previewIds)
            ->orderBy('id')
            ->get()
            ->groupBy('class_post_id');

        $posts->each(fn (ClassPost $post) => $post->setRelation(
            'comments',
            $comments->get($post->id, collect()),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ClassPost $post, User $viewer, bool $canModerate): array
    {
        $isAuthor = $post->author_id === $viewer->id;

        return [
            'id' => $post->id,
            'type' => $post->type,
            'title' => $post->title,
            'body' => $post->body,
            'author' => ['id' => $post->author?->id, 'name' => $post->author?->name],
            'published_at' => $post->published_at?->toIso8601String(),
            'is_published' => $post->isPublished(),
            'is_pinned' => $post->isPinned(),
            'comments_enabled' => $post->comments_enabled,
            'comments_count' => $post->comments_count,
            'attachments' => $post->attachments->map(fn (ClassPostAttachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'size' => $file->size,
                'mime' => $file->mime,
                'is_pdf' => $file->isPdf(),
            ])->values(),
            'comments' => $post->comments->map(fn (ClassPostComment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user' => ['id' => $comment->user?->id, 'name' => $comment->user?->name],
                'created_at' => $comment->created_at?->toIso8601String(),
                'can_delete' => $canModerate || $comment->user_id === $viewer->id,
            ])->values(),
            'can_edit' => $isAuthor || $canModerate,
            'can_delete' => $isAuthor || $canModerate,
            'can_pin' => $canModerate,
        ];
    }
}
