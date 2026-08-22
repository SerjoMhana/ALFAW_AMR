<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Nothing but the login endpoint may be reachable without a token, and a token
 * has to be earned, expire, and die on logout.
 */
class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The guarantee in one test: every API route sits behind auth. A route added
     * outside the authenticated group fails here rather than in production.
     */
    public function test_every_api_route_requires_authentication(): void
    {
        $allowedPublic = ['api/login'];
        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            if (in_array($route->uri(), $allowedPublic, true)) {
                continue;
            }

            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                $unprotected[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $unprotected, 'These API routes are reachable without a token: '.implode(', ', $unprotected));
    }

    public function test_an_anonymous_request_is_rejected(): void
    {
        $this->getJson('/api/students')->assertUnauthorized();
        $this->getJson('/api/users')->assertUnauthorized();
        $this->getJson('/api/finance/students')->assertUnauthorized();
        $this->postJson('/api/grade-tiers', ['name' => 'X', 'min_grade' => 1, 'max_grade' => 4])->assertUnauthorized();
    }

    public function test_a_made_up_token_is_rejected(): void
    {
        $this->withToken('1|totallyMadeUpTokenValue000000000000000000')
            ->getJson('/api/students')
            ->assertUnauthorized();
    }

    public function test_login_issues_a_token_that_works(): void
    {
        $user = $this->admin();

        $token = $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'email', 'user_type', 'permissions']])
            ->json('token');

        $this->withToken($token)->getJson('/api/user')->assertOk();
    }

    public function test_the_token_never_contains_the_password(): void
    {
        $this->admin();

        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])
            ->assertOk()
            ->assertJsonMissingPath('user.password');
    }

    public function test_logging_out_kills_the_token(): void
    {
        $this->admin();
        $token = $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])->json('token');

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = $this->admin();
        $token = $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])->json('token');

        PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        $this->admin()->update(['is_active' => false]);

        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])
            ->assertStatus(422);
    }

    /**
     * A wrong password and a nonexistent account must be indistinguishable, or
     * the login form becomes a way to enumerate who has an account.
     */
    public function test_a_wrong_password_and_an_unknown_account_answer_alike(): void
    {
        $this->admin();

        $wrongPassword = $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'not-the-password']);
        RateLimiter::clear('login-ip:127.0.0.1');
        $noSuchUser = $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'not-the-password']);

        $this->assertSame($wrongPassword->status(), $noSuchUser->status());
        $this->assertSame($wrongPassword->json('message'), $noSuchUser->json('message'));
        $this->assertSame($wrongPassword->json('errors'), $noSuchUser->json('errors'));
    }

    public function test_repeated_failures_lock_the_account_out(): void
    {
        $this->admin();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
                ->assertStatus(422);
        }

        // The sixth is refused outright, and the right password no longer helps.
        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
            ->assertStatus(429);

        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])
            ->assertStatus(429);
    }

    public function test_a_successful_login_clears_the_account_lockout_counter(): void
    {
        $this->admin();

        foreach (range(1, 3) as $ignored) {
            $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'wrong'])->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])->assertOk();

        // The counter reset, so a fresh run of failures is needed to lock again.
        foreach (range(1, 4) as $ignored) {
            $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'wrong'])->assertStatus(422);
        }
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);
    }
}
