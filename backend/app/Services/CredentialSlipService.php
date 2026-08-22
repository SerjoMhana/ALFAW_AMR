<?php

namespace App\Services;

use App\Models\CourseSection;
use App\Models\ParentGuardian;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/**
 * The sheet a school hands someone so they can sign in for the first time.
 *
 * A stored password cannot be read back — that is the point of hashing it — so
 * printing one means setting a new one. Every slip therefore carries a freshly
 * made password and the old one stops working, which is stated plainly wherever
 * the button appears rather than discovered afterwards.
 */
class CredentialSlipService
{
    /**
     * Readable at a glance and unambiguous read aloud: no O/0, no I/l/1.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    /**
     * One teacher.
     *
     * @return array<string, mixed>
     */
    public function forTeacher(User $teacher, ?string $known = null): array
    {
        return $this->issue($teacher, $teacher->name, 'teacher', known: $known);
    }

    /**
     * One student.
     *
     * @return array<string, mixed>
     */
    public function forStudent(StudentProfile $student, ?string $known = null): array
    {
        $user = $student->user;

        abort_unless($user, 422, 'هذا الطالب لا يملك حساب دخول.');

        return $this->issue(
            $user,
            $student->full_name ?: $user->name,
            'student',
            $student->admission_no ?: $student->student_number,
            $known,
        );
    }

    /**
     * One guardian.
     *
     * @return array<string, mixed>
     */
    public function forGuardian(ParentGuardian $guardian, ?string $known = null): array
    {
        $user = $guardian->user;

        abort_unless($user, 422, 'ولي الأمر هذا لا يملك حساب دخول.');

        return $this->issue($user, $guardian->name ?: $user->name, 'guardian', known: $known);
    }

    /**
     * Every student in a class, in one file — what a school actually prints at
     * the start of a year.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forClass(CourseSection $section): Collection
    {
        return $section->enrollments()
            ->with('studentProfile.user')
            ->where('status', 'active')
            ->get()
            ->map(fn ($enrollment) => $enrollment->studentProfile)
            ->filter(fn (?StudentProfile $student) => $student?->user !== null)
            ->unique('id')
            ->sortBy(fn (StudentProfile $student) => $student->full_name ?: $student->user->name)
            ->map(fn (StudentProfile $student) => $this->forStudent($student))
            ->values();
    }

    /**
     * Returns what to print, setting a new password unless one is already known.
     *
     * A password just typed by the office is printed as it stands — issuing a
     * different one would throw away what they meant to set. Everywhere else
     * there is nothing to read back, so a new one is made.
     *
     * @return array<string, mixed>
     */
    private function issue(
        User $user,
        string $displayName,
        string $role,
        ?string $reference = null,
        ?string $known = null,
    ): array {
        $password = $known ?: $this->makePassword();

        if (! $known) {
            $user->forceFill(['password' => Hash::make($password)])->save();
            // Anything issued under the old password stops working with it.
            $user->tokens()->delete();
        }

        return [
            'name' => $displayName,
            'username' => $user->username ?: $user->email,
            'password' => $password,
            'role' => $role,
            'reference' => $reference,
        ];
    }

    private function makePassword(): string
    {
        /*
         * Ten characters drawn from the unambiguous alphabet — around sixty
         * bits, which no one guesses, while still being typed correctly off a
         * printed sheet by a child. Symbols are left out on purpose: they are
         * the character a keyboard layout gets wrong.
         */
        $alphabet = self::ALPHABET;

        return collect(range(1, 10))
            ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
            ->implode('');
    }
}
