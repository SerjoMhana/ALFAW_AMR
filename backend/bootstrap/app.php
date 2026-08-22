<?php

use App\Http\Middleware\EnsureUserPermission;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'permission' => EnsureUserPermission::class,
        ]);

        // Requests from the school's own frontend are authenticated by an
        // HttpOnly session cookie rather than a token JavaScript can read, so
        // stealing credentials through XSS is no longer possible. Everything
        // else still authenticates with a bearer token.
        $middleware->statefulApi();

        // Applied to the API only: the strict CSP below suits a JSON/PDF API and
        // would be wrong for any HTML the web routes serve.
        $middleware->api(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An exception is rendered outside the middleware stack, so a 401, 403
        // or 500 would otherwise go out bare.
        $exceptions->respond(fn (Response $response, \Throwable $e, Request $request) => $request->is('api/*')
            ? SecurityHeaders::apply($response, $request->secure())
            : $response);
    })->create();
