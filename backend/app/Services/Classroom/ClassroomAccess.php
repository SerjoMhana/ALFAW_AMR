<?php

namespace App\Services\Classroom;

use App\Models\ClassPost;
use App\Models\ClassPostView;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may read a subject's stream, and who may write to it.
 *
 * The rule is the same one the grade book already uses — a subject belongs to
 * the teacher assigned to it — so a teacher never finds they can mark a class
 * they cannot talk to, or the reverse.
 */
class ClassroomAccess
{
    /**
     * Every subject this person may open, with the unread count already worked
     * out. One query for the subjects and one for the counts, whatever the size
     * of the stream behind them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function coursesFor(User $user): Collection
    {
        $courses = $this->visibleCourses($user);

        if ($courses->isEmpty()) {
            return collect();
        }

        $ids = $courses->pluck('id');
        $marks = ClassPostView::where('user_id', $user->id)
            ->whereIn('course_id', $ids)
            ->pluck('last_seen_post_id', 'course_id');

        // Counted as a range on (course_id, id): the index answers it without
        // reading the posts themselves.
        $unread = ClassPost::query()
            ->published()
            ->whereIn('course_id', $ids)
            ->where(function ($query) use ($marks): void {
                // Never opened, so everything in it is new.
                $query->whereNotIn('course_id', $marks->keys()->all());

                foreach ($marks as $courseId => $mark) {
                    $query->orWhere(fn ($inner) => $inner
                        ->where('course_id', $courseId)
                        ->where('id', '>', (int) $mark));
                }
            })
            ->groupBy('course_id')
            ->select('course_id', DB::raw('count(*) as total'))
            ->pluck('total', 'course_id');

        return $courses->map(fn (Course $course) => [
            'id' => $course->id,
            'code' => $course->code,
            'name' => $course->name,
            'class_name' => $course->classSection?->class_name ?: $course->classSection?->section_code,
            'academic_year' => $course->classSection?->academic_year,
            'teacher' => $course->teacher?->name,
            'can_post' => $this->canPost($user, $course),
            'unread' => (int) ($unread[$course->id] ?? 0),
        ])->values();
    }

    /**
     * Asked of one subject, so it is answered about that subject rather than by
     * building the whole visible list and searching it — an admin's list is
     * every course in the school.
     */
    public function canRead(User $user, Course $course): bool
    {
        if ($user->isAdmin() || $user->hasPermission('classroom.manage') || $user->hasPermission('classroom.view')) {
            return true;
        }

        if ($user->user_type === 'teacher') {
            return $this->isSubjectTeacher($user, $course);
        }

        if ($user->user_type === 'student') {
            // A student reaches a subject through the class they sit in, which
            // is why a subject with no class is invisible to everyone.
            return $course->class_section_id !== null && Enrollment::query()
                ->where('course_section_id', $course->class_section_id)
                ->where('status', 'active')
                ->whereHas('studentProfile', fn ($profile) => $profile->where('user_id', $user->id))
                ->exists();
        }

        return false;
    }

    /**
     * Writing is narrower than reading.
     *
     * A subject's own teacher needs no grant — being assigned to teach it is
     * the grant. `classroom.post` is for staff who write to classes they do not
     * teach; it does not carry the right to moderate.
     */
    public function canPost(User $user, Course $course): bool
    {
        if ($user->isAdmin() || $user->hasPermission('classroom.manage') || $user->hasPermission('classroom.post')) {
            return true;
        }

        return $this->isSubjectTeacher($user, $course);
    }

    /**
     * Pinning, seeing drafts, and removing what someone else wrote — the run of
     * one's own classroom, or of every classroom.
     */
    public function canModerate(User $user, Course $course): bool
    {
        if ($user->isAdmin() || $user->hasPermission('classroom.manage')) {
            return true;
        }

        return $this->isSubjectTeacher($user, $course);
    }

    private function isSubjectTeacher(User $user, Course $course): bool
    {
        return $user->user_type === 'teacher' && $course->teacher_id === $user->id;
    }

    /**
     * Commenting is open to everyone who can read — that is the point of a
     * class stream — but only while the author leaves comments on.
     */
    public function canComment(User $user, ClassPost $post): bool
    {
        return $post->comments_enabled && $this->canRead($user, $post->course);
    }

    /**
     * The sidebar list. Nothing is memoised here on purpose: the service can
     * outlive a single question, and a list cached past a change would show a
     * subject that has just moved class — or hide one that has just arrived.
     *
     * @return Collection<int, Course>
     */
    private function visibleCourses(User $user): Collection
    {
        // Only this year's teaching: a stream from a finished year is not
        // part of the day's work.
        $query = Course::query()
            ->whereHas('classSection', fn ($section) => $section->inActiveYear())
            ->with([
                'classSection:id,class_name,section_code,academic_year',
                'teacher:id,name',
            ]);

        if ($user->isAdmin() || $user->hasPermission('classroom.manage')) {
            return $query->get();
        }

        if ($user->user_type === 'teacher') {
            return $query->where('teacher_id', $user->id)->get();
        }

        if ($user->user_type === 'student') {
            $sectionIds = Enrollment::query()
                ->whereHas('studentProfile', fn ($profile) => $profile->where('user_id', $user->id))
                ->where('status', 'active')
                ->pluck('course_section_id');

            return $sectionIds->isEmpty()
                ? collect()
                : $query->whereIn('class_section_id', $sectionIds)->get();
        }

        return $user->hasPermission('classroom.view') ? $query->get() : collect();
    }
}
