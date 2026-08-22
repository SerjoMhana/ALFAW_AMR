<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers that tell the browser what this API is and is not allowed to do.
 *
 * The API serves JSON and PDFs, never HTML someone should embed or a page that
 * should guess at content types, so the strict answers are the right ones.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request), $request->secure());
    }

    /**
     * Shared with the exception handler: a response rendered from an exception
     * never comes back through the middleware, and a 401 or a 500 needs these
     * headers just as much as a 200 does.
     */
    public static function apply(Response $response, bool $secure = false): Response
    {
        // Nothing here is meant to be framed — blocks clickjacking outright.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Never let the browser second-guess a declared content type, which is
        // what turns an uploaded file into a script.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Keep student names and ids out of the Referer header on any hop off-site.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        // The API needs none of these, so refuse them rather than inherit a default.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // A JSON API has no legitimate reason to run or load anything.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

        // Only meaningful over TLS, and harmless before it.
        if ($secure) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
