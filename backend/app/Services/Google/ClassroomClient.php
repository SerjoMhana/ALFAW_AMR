<?php

namespace App\Services\Google;

/**
 * What this system needs from Google Classroom, and nothing more.
 *
 * Kept as an interface so the whole feature — screens, jobs, tests — runs
 * against an in-memory stand-in before the school's Workspace is wired up, and
 * against Google afterwards without any other file changing.
 */
interface ClassroomClient
{
    /**
     * The course carrying this alias, or null. The alias is our own key, so a
     * lost google_course_id never causes a duplicate course.
     *
     * @return array<string, mixed>|null
     */
    public function findCourseByAlias(string $alias): ?array;

    /**
     * @return array<string, mixed> the created course
     */
    public function createCourse(string $alias, string $name, ?string $section, ?string $description): array;

    /**
     * @param  array<string, string>  $changes  name / section / description
     * @return array<string, mixed>
     */
    public function updateCourse(string $courseId, array $changes): array;

    public function archiveCourse(string $courseId): void;

    /**
     * Adds a teacher. Returns the resulting state: ACTIVE when added outright,
     * INVITED when Google could only send an invitation.
     */
    public function addTeacher(string $courseId, string $email): string;

    public function addStudent(string $courseId, string $email): string;

    /**
     * @return array<int, string> the emails already on the course
     */
    public function listStudentEmails(string $courseId): array;
}
