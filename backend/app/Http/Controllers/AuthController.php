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
    /** Failed attempts allowed against one identifier before it locks. */
    private const MAX_ATTEMPTS = 5;

    /**
     * A whole school shares one public address, so the per-IP ceiling has to sit
     * well above the per-account one or a few typos would lock out everybody.
     */
    private const MAX_ATTEMPTS_PER_IP = 30;

    /** How long the lockout lasts, in seconds. */
    private const DECAY_SECONDS = 900;

    /**
     * A dummy hash to verify against when no such account exists, so a wrong
     * username costs the same time as a wrong password and cannot be told apart.
     */
    private const DUMMY_HASH = '$2y$12$e0MYzXyjpJS7Pd0RVvHwHeF0DVAaZmHtGuJmVJqtvBM/bHYFNjXCu';

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $this->ensureNotRateLimited($request, $credentials['email']);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->orWhere('username', $credentials['email'])
            ->first();

        // Always hash-check something: skipping it for an unknown account leaks
        // which identifiers exist through response timing.
        $passwordMatches = Hash::check($credentials['password'], $user?->password ?? self::DUMMY_HASH);

        if (! $user || ! $passwordMatches) {
            $this->recordFailure($request, $credentials['email']);

            // One message for both cases, so a failed login says nothing about
            // whether the account is real.
            throw ValidationException::withMessages([
                'email' => ['Invalid login credentials.'],
            ]);
        }

        if (! $user->is_active) {
            $this->recordFailure($request, $credentials['email']);

            throw ValidationException::withMessages([
                'email' => ['This account is inactive.'],
            ]);
        }

        $this->clearAttempts($request, $credentials['email']);

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
     * Throttled on the identifier and the IP together, so neither guessing many
     * passwords for one account nor spraying one password across accounts gets
     * an unlimited number of tries.
     */
    private function ensureNotRateLimited(Request $request, string $login): void
    {
        foreach ($this->throttleKeys($request, $login) as $key => $max) {
            if (! RateLimiter::tooManyAttempts($key, $max)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Try again in {$seconds} seconds."],
            ])->status(429);
        }
    }

    private function recordFailure(Request $request, string $login): void
    {
        foreach (array_keys($this->throttleKeys($request, $login)) as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }

    private function clearAttempts(Request $request, string $login): void
    {
        // Only the account's own counter is cleared: a successful login must not
        // wipe the shared IP counter and hand a spraying attacker a fresh budget.
        RateLimiter::clear($this->accountKey($request, $login));
    }

    /**
     * @return array<string, int> throttle key => attempts allowed
     */
    private function throttleKeys(Request $request, string $login): array
    {
        return [
            $this->accountKey($request, $login) => self::MAX_ATTEMPTS,
            'login-ip:'.$request->ip() => self::MAX_ATTEMPTS_PER_IP,
        ];
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
