<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * The school's own frontend authenticates with an HttpOnly session cookie
 * instead of a token held in JavaScript, so a script injected into the page has
 * no credential to steal. Everything else still uses bearer tokens.
 */
class CookieSessionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum decides statefulness from the request's origin; these headers
        // are what a browser on the frontend actually sends.
        config(['sanctum.stateful' => ['localhost:5173']]);
    }

    private function fromSpa(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);
    }

    public function test_a_browser_login_returns_no_token_at_all(): void
    {
        $this->admin();

        $response = $this->fromSpa()
            ->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'email', 'permissions']]);

        // Nothing a script could read or store.
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertArrayNotHasKey('expires_at', $response->json());
    }

    public function test_the_session_cookie_cannot_be_read_by_javascript(): void
    {
        $this->admin();

        $response = $this->fromSpa()
            ->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])
            ->assertOk();

        $session = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($session, 'no session cookie was issued');
        $this->assertTrue($session->isHttpOnly(), 'the session cookie must be HttpOnly');
        $this->assertSame('lax', strtolower((string) $session->getSameSite()));
    }

    public function test_the_cookie_carries_the_session_to_the_next_request(): void
    {
        $this->admin();

        $this->fromSpa()
            ->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])
            ->assertOk();

        // No token supplied — the cookie the test client kept is what identifies us.
        $this->fromSpa()->getJson('/api/user')->assertOk()->assertJsonPath('user.email', 'admin@example.com');
    }

    public function test_logging_out_ends_the_session(): void
    {
        $this->admin();

        $this->fromSpa()->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])->assertOk();
        $this->fromSpa()->postJson('/api/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->fromSpa()->getJson('/api/user')->assertUnauthorized();
    }

    /**
     * A non-browser client is not stateful, so it still gets a token — mobile
     * apps and integrations keep working.
     */
    public function test_a_non_browser_client_still_receives_a_token(): void
    {
        $this->admin();

        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'Str0ng!Passw0rd'])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'user']);
    }

    public function test_a_bearer_token_still_authenticates(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/user')->assertOk();
    }

    /**
     * The frontend origin is named explicitly; a hostile site cannot have the
     * browser attach the session cookie to its own requests.
     */
    public function test_only_the_named_origins_may_send_credentials(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));

        foreach (config('cors.allowed_origins') as $origin) {
            $this->assertStringStartsWith('http', $origin);
        }
    }

    /**
     * The cookie settings are the whole defence, so assert them rather than
     * trusting a default: readable by JavaScript would undo the change, and
     * SameSite=None would let any site send it.
     */
    public function test_the_session_cookie_settings_are_the_safe_ones(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', strtolower((string) config('session.same_site')));
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
