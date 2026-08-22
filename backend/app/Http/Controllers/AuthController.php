<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Attempts against one account before each further try is slowed down.
     *
     * Nobody is ever locked out. A school shares one address and one hurried
     * morning, and a teacher who mistypes twice should not be told to come back
     * in a quarter of an hour. Guessing is made slow instead of impossible:
     * past this many failures each attempt pauses, which costs a person nothing
     * and costs a script everything.
     */
    private const SLOW_AFTER = 10;

    /** The pause added per failure past that, capped by MAX_DELAY_SECONDS. */
    private const DELAY_STEP_MS = 400;

    private const MAX_DELAY_SECONDS = 3;

    /** How long the failure count is remembered. */
    private const DECAY_SECONDS = 900;

    /**
     * A dummy hash to verify against when no such account exists, so a wrong
     * username costs the same time as a wrong password and cannot be told apart.
     */
    private const DUMMY_HASH = '$2y$12$e0MYzXyjpJS7Pd0RVvHwHeF0DVAaZmHtGuJmVJqtvBM/bHYFNjXCu';

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            // Named `username` now, with `email` still accepted so an older
            // client or a saved password manager entry keeps working.
            'username' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['required_without:username', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $login = (string) ($credentials['username'] ?? $credentials['email']);

        $this->slowDownIfGuessing($request, $login);

        // Username first, since that is what the school hands out; the email is
        // still accepted for the accounts that sign in with one.
        $user = User::query()
            ->where('username', $login)
            ->orWhere('email', $login)
            ->first();

        // Always hash-check something: skipping it for an unknown account leaks
        // which identifiers exist through response timing.
        $passwordMatches = Hash::check($credentials['password'], $user?->password ?? self::DUMMY_HASH);

        if (! $user || ! $passwordMatches) {
            $this->recordFailure($request, $login);

            // One message for both cases, so a failed login says nothing about
            // whether the account is real.
            throw ValidationException::withMessages([
                'username' => ['اسم المستخدم أو كلمة المرور غير صحيحة.'],
            ]);
        }

        if (! $user->is_active) {
            $this->recordFailure($request, $login);

            throw ValidationException::withMessages([
                'username' => ['هذا الحساب موقوف. راجع إدارة المدرسة.'],
            ]);
        }

        $this->clearAttempts($request, $login);

        // A browser on the school's own frontend gets an HttpOnly session
        // cookie: no credential is ever exposed to JavaScript, so XSS cannot
        // carry one off. Other clients still get a bearer token.
        if ($request->hasSession()) {
            Auth::guard('web')->login($user);

            // A new session id for the new privilege level defeats fixation.
            $request->session()->regenerate();

            return response()->json(['user' => $this->serializeUser($user)]);
        }

        $token = $user->createToken('api', ['*'], now()->addDays(7));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'user' => $this->serializeUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Token clients hold a real token; a session-authenticated SPA gets a
        // TransientToken stand-in that has nothing to delete.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    /**
     * Pauses before answering once an account has failed many times running.
     *
     * This never refuses and never says "try again later" — it only makes each
     * further guess slower. Ten wrong tries cost nothing; ten thousand become
     * hours, which is what stops a script without ever standing in a teacher's
     * way on a Sunday morning.
     */
    private function slowDownIfGuessing(Request $request, string $login): void
    {
        $failures = RateLimiter::attempts($this->accountKey($request, $login));

        if ($failures < self::SLOW_AFTER) {
            return;
        }

        $delay = min(
            ($failures - self::SLOW_AFTER + 1) * self::DELAY_STEP_MS * 1000,
            self::MAX_DELAY_SECONDS * 1_000_000,
        );

        usleep((int) $delay);
    }

    private function recordFailure(Request $request, string $login): void
    {
        RateLimiter::hit($this->accountKey($request, $login), self::DECAY_SECONDS);
    }

    private function clearAttempts(Request $request, string $login): void
    {
        RateLimiter::clear($this->accountKey($request, $login));
    }

    private function accountKey(Request $request, string $login): string
    {
        return 'login:'.Str::lower($login).'|'.$request->ip();
    }

    private function serializeUser(User $user): array
    {
        $user->loadMissing('permissions');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'is_active' => $user->is_active,
            'permissions' => $user->isAdmin()
                ? ['*']
                : $user->permissions->pluck('name')->values(),
        ];
    }
}
