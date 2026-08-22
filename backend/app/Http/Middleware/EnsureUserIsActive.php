<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A token proves who someone was when they logged in, not that they still work
 * here. Without this, suspending an account leaves its existing tokens working
 * until they expire — a dismissed teacher would keep full access for days.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            // Retire the credential as well as refusing the request, so the
            // account cannot keep knocking.
            $user->currentAccessToken()?->delete();

            abort(401, 'This account is no longer active.');
        }

        return $next($request);
    }
}
