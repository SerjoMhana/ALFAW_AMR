<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Turns the school's service-account key into a short-lived access token.
 *
 * Google's own SDK would do this, but it drags in the whole API surface for one
 * signed assertion; PHP already has everything needed. The key file is read from
 * disk each time and never logged, cached or returned.
 */
class GoogleServiceAccount
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const CACHE_KEY = 'google:classroom:access-token';

    /**
     * @param  array<int, string>  $scopes
     */
    public function accessToken(array $scopes): string
    {
        // Google issues these for an hour; stopping a minute early avoids using
        // one that expires mid-request.
        return Cache::remember(
            self::CACHE_KEY.':'.md5(implode(' ', $scopes)),
            now()->addMinutes(50),
            fn () => $this->requestToken($scopes),
        );
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function requestToken(array $scopes): string
    {
        $key = $this->credentials();
        $impersonate = config('google.classroom.impersonate');

        if (! $impersonate) {
            throw new RuntimeException('GOOGLE_IMPERSONATE_EMAIL is not set. A course needs a real owner.');
        }

        $now = time();
        $claims = [
            'iss' => $key['client_email'],
            // Domain-wide delegation: act as this person, not as the robot.
            'sub' => $impersonate,
            'scope' => implode(' ', $scopes),
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $assertion = $this->sign($claims, $key['private_key']);

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        if ($response->failed()) {
            // Google's body names the real cause — an unauthorised client id, a
            // scope the admin has not approved — so it is worth surfacing.
            throw new RuntimeException('Google refused the service account: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function sign(array $claims, string $privateKey): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        $header = $encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $encode($claims);
        $signature = '';

        if (! openssl_sign("{$header}.{$payload}", $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the Google assertion; check the private key in the JSON file.');
        }

        return $header.'.'.$payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function credentials(): array
    {
        $path = config('google.classroom.credentials');

        if (! $path || ! is_file($path)) {
            throw new RuntimeException("The Google service-account file was not found at [{$path}]. Set GOOGLE_SERVICE_ACCOUNT_JSON.");
        }

        $key = json_decode((string) file_get_contents($path), true);

        if (! is_array($key) || ! isset($key['client_email'], $key['private_key'])) {
            throw new RuntimeException('The Google service-account file is not a valid key: client_email and private_key are required.');
        }

        return $key;
    }
}
