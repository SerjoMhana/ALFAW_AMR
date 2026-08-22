<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use App\Services\Google\ClassroomSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class GoogleClassroomController extends Controller
{
    public function __construct(private readonly ClassroomSyncService $sync) {}

    /**
     * Whether the school has finished the Workspace setup. The screens work
     * either way, but say plainly which mode they are in.
     */
    public function settings(): JsonResponse
    {
        return response()->json([
            'data' => [
                'enabled' => (bool) config('google.classroom.enabled'),
                'domain' => config('google.classroom.domain'),
                'impersonate' => config('google.classroom.impersonate'),
                'credentials_present' => (bool) config('google.classroom.credentials')
                    && is_file((string) config('google.classroom.credentials')),
            ],
        ]);
    }

    public function status(CourseSection $courseSection): JsonResponse
    {
        return response()->json(['data' => $this->sync->status($courseSection)]);
    }

    /**
     * Sets the Workspace address for a person. Everything else depends on this:
     * Google identifies people by their domain address, not by our records.
     */
    public function linkEmail(Request $request, User $user): JsonResponse
    {
        $domain = config('google.classroom.domain');

        $data = $request->validate([
            'google_email' => [
                'present', 'nullable', 'email', 'max:255',
                Rule::unique('users', 'google_email')->ignore($user),
                // When the domain is configured, only addresses on it can work.
                ...($domain ? ['ends_with:@'.$domain] : []),
            ],
        ]);

        $user->update(['google_email' => $data['google_email'] ?: null]);

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'google_email'])]);
    }

    /**
     * Saves a whole class's addresses in one pass. Either every row is valid and
     * stored, or nothing is — a half-applied mapping is worse than none.
     */
    public function saveEmails(Request $request): JsonResponse
    {
        $domain = config('google.classroom.domain');

        $data = $request->validate([
            'emails' => ['present', 'array'],
            'emails.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'emails.*.google_email' => [
                'present', 'nullable', 'email', 'max:255',
                'distinct:ignore_case',
                ...($domain ? ['ends_with:@'.$domain] : []),
            ],
        ]);

        $rows = collect($data['emails']);

        // Two people on one address would send Google contradictory rosters.
        $taken = User::whereIn('google_email', $rows->pluck('google_email')->filter())
            ->whereNotIn('id', $rows->pluck('user_id'))
            ->pluck('google_email');

        if ($taken->isNotEmpty()) {
            return response()->json([
                'message' => 'هذه العناوين مرتبطة بمستخدمين آخرين: '.$taken->implode('، '),
            ], 422);
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                User::whereKey($row['user_id'])->update(['google_email' => $row['google_email'] ?: null]);
            }
        });

        return response()->json(['data' => ['saved' => $rows->count()]]);
    }

    /**
     * Fills addresses in bulk from a pattern, for a school that names accounts
     * predictably. Anything already set is left alone.
     */
    public function suggestEmails(Request $request, CourseSection $courseSection): JsonResponse
    {
        $domain = config('google.classroom.domain');

        abort_unless($domain, 422, 'اضبط GOOGLE_WORKSPACE_DOMAIN أولاً.');

        $request->validate(['pattern' => ['required', Rule::in(['admission_no', 'username'])]]);

        $status = $this->sync->status($courseSection);
        $suggestions = [];

        foreach ($status['students'] as $student) {
            if ($student['google_email']) {
                continue;
            }

            $local = $request->string('pattern')->toString() === 'admission_no'
                ? $student['admission_no']
                : str($student['name'])->slug('.');

            if (filled($local)) {
                $suggestions[] = [
                    'student_profile_id' => $student['id'],
                    'user_id' => $student['user_id'],
                    'name' => $student['name'],
                    'google_email' => strtolower($local).'@'.$domain,
                ];
            }
        }

        return response()->json(['data' => $suggestions]);
    }

    public function createCourse(Course $course): JsonResponse
    {
        $link = $this->sync->ensureCourse($course);

        return response()->json(['data' => $link->fresh()]);
    }

    public function renameCourse(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'data' => $this->sync->rename($course, $data['name'], $data['section'] ?? null),
        ]);
    }

    public function addTeacher(Course $course): JsonResponse
    {
        try {
            return response()->json(['data' => $this->sync->addTeacher($course)]);
        } catch (RuntimeException $e) {
            // A missing teacher or a missing address is something the admin can
            // fix on the spot, so say which rather than failing as a server error.
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function addStudents(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'student_profile_ids' => ['present', 'array'],
            'student_profile_ids.*' => ['integer', 'exists:student_profiles,id'],
        ]);

        return response()->json(['data' => $this->sync->addStudents($course, $data['student_profile_ids'])]);
    }

    public function archiveCourse(Course $course): JsonResponse
    {
        $this->sync->archive($course);

        return response()->json(['message' => 'تمت أرشفة الفصل في Google Classroom.']);
    }
}
