<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_response_carries_the_hardening_headers(): void
    {
        $response = $this->getJson('/api/students');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Content-Security-Policy');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_the_headers_are_present_on_authenticated_responses_too(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Str0ng!Passw0rd'),
            'user_type' => 'admin',
            'is_active' => true,
        ]);

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Only the school's own frontend may call the API from a browser.
     */
    /**
     * Credentials are allowed because the SPA authenticates by cookie — which
     * makes it critical that the origin list is exact and never a wildcard.
     */
    public function test_cors_allows_the_configured_origin_only(): void
    {
        $this->assertContains('http://localhost:5173', config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertTrue(config('cors.supports_credentials'));
    }

    public function test_an_unknown_origin_is_not_echoed_back(): void
    {
        $response = $this->call(
            'OPTIONS',
            '/api/students',
            server: [
                'HTTP_ORIGIN' => 'https://evil.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $this->assertNotSame(
            'https://evil.example.com',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }
}
