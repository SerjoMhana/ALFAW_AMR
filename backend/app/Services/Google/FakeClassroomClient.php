<?php

namespace App\Services\Google;

/**
 * An in-memory Classroom, used in tests and while the integration is switched
 * off — so the screens, jobs and rules can all be exercised and reviewed before
 * the school's Workspace exists.
 *
 * It answers with the same shapes Google does, so swapping in the real client
 * changes nothing else.
 */
class FakeClassroomClient implements ClassroomClient
{
    /** @var array<string, array<string, mixed>> keyed by course id */
    private array $courses = [];

    /** @var array<string, array<int, string>> */
    private array $students = [];

    /** @var array<string, array<int, string>> */
    private array $teachers = [];

    /** When true, members land as INVITED — the shape when the school's admin
     *  has not granted direct-add rights. */
    public bool $inviteOnly = false;

    public function findCourseByAlias(string $alias): ?array
    {
        foreach ($this->courses as $course) {
            if ($course['alias'] === $alias) {
                return $course;
            }
        }

        return null;
    }

    public function createCourse(string $alias, string $name, ?string $section, ?string $description): array
    {
        $id = 'fake-'.substr(md5($alias), 0, 12);

        return $this->courses[$id] = [
            'id' => $id,
            'alias' => $alias,
            'name' => $name,
            'section' => $section,
            'description' => $description,
            'courseState' => 'PROVISIONED',
            'enrollmentCode' => strtoupper(substr(md5($alias.'code'), 0, 6)),
            'alternateLink' => 'https://classroom.google.com/c/'.$id,
        ];
    }

    public function updateCourse(string $courseId, array $changes): array
    {
        $this->courses[$courseId] = array_merge($this->courses[$courseId] ?? ['id' => $courseId], $changes);

        return $this->courses[$courseId];
    }

    public function archiveCourse(string $courseId): void
    {
        $this->courses[$courseId]['courseState'] = 'ARCHIVED';
    }

    public function addTeacher(string $courseId, string $email): string
    {
        $this->teachers[$courseId][] = strtolower($email);

        return $this->inviteOnly ? 'INVITED' : 'ACTIVE';
    }

    public function addStudent(string $courseId, string $email): string
    {
        $this->students[$courseId][] = strtolower($email);

        return $this->inviteOnly ? 'INVITED' : 'ACTIVE';
    }

    public function listStudentEmails(string $courseId): array
    {
        return array_values(array_unique($this->students[$courseId] ?? []));
    }

    /**
     * @return array<int, string>
     */
    public function teacherEmails(string $courseId): array
    {
        return array_values(array_unique($this->teachers[$courseId] ?? []));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function courses(): array
    {
        return $this->courses;
    }
}
