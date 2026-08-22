<?php

namespace App\Http\Controllers;

use App\Models\ClassPost;
use App\Models\ClassPostAttachment;
use App\Services\Classroom\ClassroomAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files hanging off a post.
 *
 * They are stored on a private disk and handed out by this controller, never
 * linked from public/: a class's worksheets and its students' names should not
 * be one guessed URL away from the open internet.
 */
class ClassPostAttachmentController extends Controller
{
    public function __construct(private readonly ClassroomAccess $access) {}

    /**
     * One file per request, so a post carrying ten PDFs is ten small uploads
     * rather than one that times out halfway.
     */
    public function store(Request $request, ClassPost $post): JsonResponse
    {
        $post->loadMissing('course');
        $this->authorizeWrite($request, $post);

        $max = (int) config('classroom.uploads.max_size');
        $extensions = implode(',', config('classroom.uploads.extensions'));

        $request->validate([
            'file' => ['required', 'file', "max:{$max}", "mimes:{$extensions}"],
        ]);

        $limit = (int) config('classroom.uploads.max_per_post');

        abort_if(
            $post->attachments_count >= $limit,
            422,
            "لا يمكن إرفاق أكثر من {$limit} ملفات في المنشور الواحد.",
        );

        $file = $request->file('file');
        $disk = config('classroom.disk');

        // Foldered by subject and year so a finished year can be archived or
        // moved off the server as one directory.
        $folder = sprintf('classroom/%d/%s', $post->course_id, $post->academic_year ?: 'no-year');
        $name = Str::ulid().'.'.strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs($folder, $name, ['disk' => $disk]);

        $attachment = ClassPostAttachment::create([
            'class_post_id' => $post->id,
            'uploaded_by' => $request->user()->id,
            'disk' => $disk,
            'path' => $path,
            // Kept only as a label; the stored name is the one above.
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath() ?: Storage::disk($disk)->path($path)),
        ]);

        $post->increment('attachments_count');

        return response()->json([
            'data' => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'size' => $attachment->size,
                'mime' => $attachment->mime,
                'is_pdf' => $attachment->isPdf(),
            ],
        ], 201);
    }

    /**
     * Streamed rather than read into memory: a 20 MB PDF should not cost 20 MB
     * of the server's memory per reader.
     */
    public function download(Request $request, ClassPostAttachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('post.course');

        abort_unless($this->access->canRead($request->user(), $attachment->post->course), 403);

        // A draft's files belong to whoever is still writing it.
        abort_unless(
            $attachment->post->isPublished()
                || $attachment->post->author_id === $request->user()->id
                || $this->access->canModerate($request->user(), $attachment->post->course),
            403,
        );

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404, 'الملف غير موجود على الخادم.');

        return $disk->download($attachment->path, $attachment->original_name);
    }

    public function destroy(Request $request, ClassPostAttachment $attachment): JsonResponse
    {
        $attachment->loadMissing('post.course');
        $this->authorizeWrite($request, $attachment->post);

        $post = $attachment->post;
        $attachment->delete();
        // Guarded inside the statement so two deletions at once cannot both
        // read the same value and take it below zero — which MySQL rejects
        // outright on an unsigned column, 500ing the request.
        ClassPost::whereKey($post->id)
            ->where('attachments_count', '>', 0)
            ->decrement('attachments_count');

        return response()->json(['message' => 'تم حذف الملف.']);
    }

    private function authorizeWrite(Request $request, ClassPost $post): void
    {
        $user = $request->user();

        abort_unless(
            ($post->author_id === $user->id && $this->access->canRead($user, $post->course))
                || $this->access->canModerate($user, $post->course),
            403,
        );
    }
}
