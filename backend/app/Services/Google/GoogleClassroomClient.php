<?php

namespace App\Services\Google;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the real Google Classroom REST API.
 */
class GoogleClassroomClient implements ClassroomClient
{
    private const BASE = 'https://classroom.googleapis.com/v1';

    public const SCOPES = [
        'https://www.googleapis.com/auth/classroom.courses',
        'https://www.googleapis.com/auth/classroom.rosters',
        'https://www.googleapis.com/auth/classroom.profile.emails',
    ];

    public function __construct(private readonly GoogleServiceAccount $account) {}

    public function findCourseByAlias(string $alias): ?array
    {
        // A project-scoped alias is visible to this application only, so it
        // cannot collide with names the school uses in Classroom itself.
        $response = $this->request()->get(self::BASE.'/courses/'.rawurlencode('p:'.$alias));

        if ($response->status() === 404) {
            return null;
        }

        return $this->ok($response, "looking up the course for alias {$alias}");
    }

    public function createCourse(string $alias, string $name, ?string $section, ?string $description): array
    {
        $response = $this->request()->post(self::BASE.'/courses', array_filter([
            'id' => 'p:'.$alias,
            'name' => $name,
            'section' => $section,
            'description' => $description,
            // The impersonated user owns it; a service account cannot.
            'ownerId' => 'me',
            'courseState' => 'PROVISIONED',
        ]));

        return $this->ok($response, "creating the course {$name}");
    }

    public function updateCourse(string $courseId, array $changes): array
    {
        $allowed = array_intersect_key($changes, array_flip(['name', 'section', 'description']));

        $response = $this->request()->patch(
            self::BASE.'/courses/'.rawurlencode($courseId).'?updateMask='.implode(',', array_keys($allowed)),
            $allowed,
        );

        return $this->ok($response, "renaming the course {$courseId}");
    }

    public function archiveCourse(string $courseId): void
    {
        $response = $this->request()->patch(
            self::BASE.'/courses/'.rawurlencode($courseId).'?updateMask=courseState',
            ['courseState' => 'ARCHIVED'],
        );

        $this->ok($response, "archiving the course {$courseId}");
    }

    public function addTeacher(string $courseId, string $email): string
    {
        return $this->addMember($courseId, 'teachers', $email);
    }

    public function addStudent(string $courseId, string $email): string
    {
        return $this->addMember($courseId, 'students', $email);
    }

    public function listStudentEmails(string $courseId): array
    {
        $emails = [];
        $pageToken = null;

        do {
            $response = $this->request()->get(self::BASE.'/courses/'.rawurlencode($courseId).'/students', array_filter([
                'pageSize' => 100,
                'pageToken' => $pageToken,
            ]));

            $body = $this->ok($response, "listing students of {$courseId}");

            foreach ($body['students'] ?? [] as $student) {
                if (isset($student['profile']['emailAddress'])) {
                    $emails[] = strtolower($student['profile']['emailAddress']);
                }
            }

            $pageToken = $body['nextPageToken'] ?? null;
        } while ($pageToken);

        return $emails;
    }

    /**
     * Adding outright needs the caller to have the right; otherwise Google only
     * allows an invitation the person must accept. Both are success — they just
     * leave the roster in different states.
     */
    private function addMember(string $courseId, string $collection, string $email): string
    {
        $response = $this->request()->post(
            self::BASE.'/courses/'.rawurlencode($courseId).'/'.$collection,
            ['userId' => $email],
        );

        // Already on the course: the desired state, not an error.
        if ($response->status() === 409) {
            return 'ACTIVE';
        }

        if ($response->status() === 403) {
            $invite = $this->request()->post(self::BASE.'/invitations', [
                'courseId' => $courseId,
                'userId' => $email,
                'role' => $collection === 'teachers' ? 'TEACHER' : 'STUDENT',
            ]);

            if ($invite->status() === 409) {
                return 'INVITED';
            }

            $this->ok($invite, "inviting {$email}");

            return 'INVITED';
        }

        $this->ok($response, "adding {$email} to {$courseId}");

        return 'ACTIVE';
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->account->accessToken(self::SCOPES))
            ->acceptJson()
            ->timeout(20)
            // Google rate-limits per minute; a short backoff rides out a burst.
            ->retry(3, 1000, fn ($e, $request) => true, throw: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function ok(\Illuminate\Http\Client\Response $response, string $what): array
    {
        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new RuntimeException("Google rejected {$what}: {$message}");
        }

        return $response->json() ?? [];
    }
}
