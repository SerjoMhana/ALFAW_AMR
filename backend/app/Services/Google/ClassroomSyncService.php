<?php

namespace App\Services\Google;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\GoogleClassroomLink;
use App\Models\GoogleClassroomMember;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps one Google Classroom course per subject in step with this system.
 *
 * A subject here — Mathematics inside G12 — is exactly what Google calls a
 * course, so the mapping is one to one. The class itself (G12) is only the
 * grouping and has no counterpart.
 *
 * Every operation is safe to repeat: the alias finds an existing course rather
 * than making a second one, and a member already on the roster is left alone.
 */
class ClassroomSyncService
{
    public function __construct(private readonly ClassroomClient $client) {}

    /**
     * What the admin sees before deciding anything: every subject in a class,
     * whether it is linked, who teaches it, and how many students are missing.
     *
     * @return array<string, mixed>
     */
    public function status(CourseSection $section): array
    {
        $section->loadMissing(['courses.teacher', 'courses.googleClassroomLink.members']);

        $students = $this->studentsOf($section);

        return [
            'class' => [
                'id' => $section->id,
                'name' => $section->class_name ?: $section->section_code,
                'academic_year' => $section->academic_year,
            ],
            'students' => $students->map(fn (StudentProfile $student) => [
                'id' => $student->id,
                // Google knows people by their Workspace address, which hangs off
                // the user account — so the address is edited there, not here.
                'user_id' => $student->user_id,
                'name' => $student->full_name ?: $student->user?->name,
                'admission_no' => $student->admission_no ?: $student->student_number,
                'google_email' => $student->user?->google_email,
                // Nobody can be enrolled without a Workspace address.
                'ready' => filled($student->user?->google_email),
            ])->values(),
            'subjects' => $section->courses->map(function (Course $course) use ($students) {
                $link = $course->googleClassroomLink;
                // Members recorded against the stand-in say nothing about the
                // real roster, so they stop counting once Google is connected.
                $enrolled = $link
                    ? $link->members
                        ->where('role', GoogleClassroomMember::ROLE_STUDENT)
                        ->reject(fn (GoogleClassroomMember $member) => $member->isStale())
                        ->pluck('user_id')->all()
                    : [];

                return [
                    'course_id' => $course->id,
                    'code' => $course->code,
                    'name' => $course->name,
                    'teacher' => $course->teacher?->name,
                    'teacher_user_id' => $course->teacher_id,
                    'teacher_google_email' => $course->teacher?->google_email,
                    'linked' => (bool) $link?->isLinked(),
                    // A stale row's id and join code lead nowhere, so they are
                    // not shown as if they still worked.
                    'google_course_id' => $link?->isLinked() ? $link->google_course_id : null,
                    'google_name' => $link?->name,
                    'state' => $link?->isLinked() ? $link->state : null,
                    'link' => $link?->isLinked() ? $link->alternate_link : null,
                    'enrollment_code' => $link?->isLinked() ? $link->enrollment_code : null,
                    'last_error' => $link?->last_error,
                    'teacher_added' => (bool) $link?->members
                        ->reject(fn (GoogleClassroomMember $member) => $member->isStale())
                        ->firstWhere('role', GoogleClassroomMember::ROLE_TEACHER),
                    'students_enrolled' => count($enrolled),
                    'students_missing' => $students
                        ->filter(fn (StudentProfile $s) => ! in_array($s->id, $enrolled, true))
                        ->count(),
                ];
            })->values(),
        ];
    }

    /**
     * Creates the course for a subject if it does not already exist, and adopts
     * one that does — so a half-finished run can simply be run again.
     */
    public function ensureCourse(Course $course): GoogleClassroomLink
    {
        $course->loadMissing('classSection');
        $section = $course->classSection;

        $link = GoogleClassroomLink::firstOrNew(['course_id' => $course->id]);
        $link->alias ??= $this->aliasFor($course);

        // A course invented by the stand-in must not be mistaken for a real one
        // once Google is connected — start again from the alias.
        if ($link->isStale()) {
            $link->google_course_id = null;
            $link->enrollment_code = null;
            $link->alternate_link = null;
        }

        $link->name = $link->name ?: $this->defaultName($course);
        $link->section = $section?->class_name ?: $section?->section_code;

        try {
            $remote = $this->client->findCourseByAlias($link->alias)
                ?? $this->client->createCourse(
                    $link->alias,
                    $link->name,
                    $link->section,
                    $section ? "{$section->academic_year} · {$link->section}" : null,
                );

            $this->applyRemote($link, $remote);
        } catch (Throwable $e) {
            $link->last_error = $e->getMessage();
            $link->save();

            throw $e;
        }

        return $link;
    }

    /**
     * Renames the Google course. The name here stays the school's own; Google
     * follows it rather than the other way round.
     */
    public function rename(Course $course, string $name, ?string $section = null): GoogleClassroomLink
    {
        $link = $this->ensureCourse($course);

        $remote = $this->client->updateCourse($link->google_course_id, array_filter([
            'name' => $name,
            'section' => $section,
        ]));

        $link->name = $name;
        if ($section !== null) {
            $link->section = $section;
        }

        $this->applyRemote($link, $remote);

        return $link;
    }

    /**
     * @return array{state: string, email: string}
     */
    public function addTeacher(Course $course): array
    {
        $link = $this->ensureCourse($course);
        $teacher = $course->loadMissing('teacher')->teacher;

        if (! $teacher) {
            throw new \RuntimeException('لا يوجد أستاذ مُسنَد لهذه المادة في المنظومة.');
        }

        $email = $this->requireGoogleEmail($teacher);
        $state = $this->client->addTeacher($link->google_course_id, $email);

        $this->recordMember($link, $teacher, GoogleClassroomMember::ROLE_TEACHER, $email, $state);

        return ['state' => $state, 'email' => $email];
    }

    /**
     * Enrols the chosen students. Anyone without a Workspace address is
     * reported rather than silently skipped.
     *
     * @param  array<int, int>  $studentProfileIds  empty means everyone in the class
     * @return array<string, mixed>
     */
    public function addStudents(Course $course, array $studentProfileIds = []): array
    {
        $link = $this->ensureCourse($course);
        $course->loadMissing('classSection');

        $students = $this->studentsOf($course->classSection)
            ->when($studentProfileIds !== [], fn (Collection $rows) => $rows->whereIn('id', $studentProfileIds));

        // Whoever is already there is left alone, so re-running costs nothing.
        $already = collect($this->client->listStudentEmails($link->google_course_id));

        $added = [];
        $invited = [];
        $skipped = [];

        foreach ($students as $student) {
            $email = $student->user?->google_email;

            if (! filled($email)) {
                $skipped[] = ['name' => $student->full_name ?: $student->user?->name, 'reason' => 'no_google_email'];

                continue;
            }

            if ($already->contains(strtolower($email))) {
                $this->recordMember($link, $student->user, GoogleClassroomMember::ROLE_STUDENT, $email, GoogleClassroomMember::STATE_ACTIVE);

                continue;
            }

            try {
                $state = $this->client->addStudent($link->google_course_id, $email);
                $this->recordMember($link, $student->user, GoogleClassroomMember::ROLE_STUDENT, $email, $state);

                $state === GoogleClassroomMember::STATE_INVITED
                    ? $invited[] = $email
                    : $added[] = $email;
            } catch (Throwable $e) {
                $this->recordMember($link, $student->user, GoogleClassroomMember::ROLE_STUDENT, $email, GoogleClassroomMember::STATE_FAILED, $e->getMessage());
                $skipped[] = ['name' => $student->full_name, 'reason' => $e->getMessage()];
            }
        }

        return [
            'added' => $added,
            'invited' => $invited,
            'skipped' => $skipped,
            'total_enrolled' => $link->members()->where('role', GoogleClassroomMember::ROLE_STUDENT)->count(),
        ];
    }

    public function archive(Course $course): void
    {
        $link = GoogleClassroomLink::where('course_id', $course->id)->first();

        if ($link?->isLinked()) {
            $this->client->archiveCourse($link->google_course_id);
            $link->update(['state' => 'ARCHIVED', 'last_synced_at' => now()]);
        }
    }

    /**
     * A stable key built from things that never change for a given subject in a
     * given year, so the same subject always finds the same course.
     */
    public function aliasFor(Course $course): string
    {
        $section = $course->classSection;
        $year = $section?->academic_year ?: 'no-year';

        return Str::slug("vis-{$year}-{$course->id}-{$course->code}");
    }

    private function defaultName(Course $course): string
    {
        $section = $course->classSection;
        $class = $section?->class_name ?: $section?->section_code;

        return trim("{$course->name} — {$class}");
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function applyRemote(GoogleClassroomLink $link, array $remote): void
    {
        $link->google_course_id = $remote['id'] ?? $link->google_course_id;
        $link->simulated = ! config('google.classroom.enabled');
        $link->state = $remote['courseState'] ?? 'PROVISIONED';
        $link->enrollment_code = $remote['enrollmentCode'] ?? $link->enrollment_code;
        $link->alternate_link = $remote['alternateLink'] ?? $link->alternate_link;
        $link->name = $remote['name'] ?? $link->name;
        $link->last_synced_at = now();
        $link->last_error = null;
        $link->save();
    }

    private function recordMember(
        GoogleClassroomLink $link,
        ?User $user,
        string $role,
        string $email,
        string $state,
        ?string $error = null,
    ): void {
        if (! $user) {
            return;
        }

        GoogleClassroomMember::updateOrCreate(
            ['google_classroom_link_id' => $link->id, 'user_id' => $user->id],
            [
                'role' => $role,
                'google_email' => $email,
                'state' => $state,
                'simulated' => ! config('google.classroom.enabled'),
                'last_error' => $error,
                'synced_at' => now(),
            ],
        );
    }

    private function requireGoogleEmail(User $user): string
    {
        if (! filled($user->google_email)) {
            throw new \RuntimeException("لم يُربط بريد Google Workspace للمستخدم {$user->name}.");
        }

        return $user->google_email;
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    private function studentsOf(?CourseSection $section): Collection
    {
        if (! $section) {
            return collect();
        }

        return Enrollment::query()
            ->with('studentProfile.user')
            ->where('course_section_id', $section->id)
            ->where('status', 'active')
            ->whereHas('studentProfile', fn ($query) => $query->active())
            ->get()
            ->map(fn (Enrollment $enrollment) => $enrollment->studentProfile)
            ->filter()
            ->unique('id')
            ->values();
    }
}
