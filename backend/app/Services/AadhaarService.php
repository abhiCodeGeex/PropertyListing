<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AadhaarService
{
    /**
     * Always read credentials directly from the environment so that updating
     * .env values takes effect immediately without needing a cache:clear or
     * server restart.  config() / the config cache is intentionally bypassed
     * for these sensitive, frequently-rotated values.
     */
    protected function apiKey(): string
    {
        return env('AADHAAR_API_KEY', '');
    }

    protected function apiSecret(): string
    {
        return env('AADHAAR_API_SECRET', '');
    }

    protected function apiToken(): string
    {
        return env('AADHAAR_API_TOKEN', '');
    }

    protected function baseUrl(): string
    {
        return env('AADHAAR_API_URL', 'https://api.sandbox.co.in/kyc/aadhaar/okyc');
    }

    /**
     * Get a valid sandbox JWT, auto-refreshing whenever the cached token is
     * expired or missing.
     *
     * Credentials are always read fresh from the environment so that rotating
     * AADHAAR_API_KEY / AADHAAR_API_SECRET in .env is picked up on the very
     * next request without any cache:clear or server restart.
     */
    protected function getAuthToken(): string
    {
        $cached = Cache::get('aadhaar_auth_token');

        // Return the cached token only when it is still valid AND the key it
        // was issued for matches the current env key (detects credential rotation).
        $currentKey = $this->apiKey();
        $cachedKey  = Cache::get('aadhaar_auth_token_key');

        if ($cached && $cachedKey === $currentKey && ! $this->isJwtExpired($cached)) {
            return $cached;
        }

        // Token missing, expired, or issued for a different API key – drop it.
        Cache::forget('aadhaar_auth_token');
        Cache::forget('aadhaar_auth_token_key');

        $response = Http::withHeaders([
            'accept'        => 'application/json',
            'x-api-key'     => $this->apiKey(),
            'x-api-secret'  => $this->apiSecret(),
            'x-api-version' => '1.0',
        ])->post('https://api.sandbox.co.in/authenticate');

        if ($response->failed()) {
            Log::warning('Aadhaar sandbox re-authentication failed; using static token', [
                'status' => $response->status(),
            ]);

            return $this->apiToken();
        }

        $token = $response->json('access_token');

        if (empty($token)) {
            Log::warning('Aadhaar sandbox auth response missing access_token; using static token');

            return $this->apiToken();
        }

        // Cache the fresh token for 55 minutes (tokens are valid for 1 hour).
        // Also store the API key it was issued for so we can detect rotation.
        Cache::put('aadhaar_auth_token', $token, 3300);
        Cache::put('aadhaar_auth_token_key', $currentKey, 3300);

        return $token;
    }

    /**
     * Decode a JWT and check whether its `exp` claim has passed.
     * Returns true (= expired) when the token expires within 60 seconds,
     * so we never hand out a token that will expire mid-request.
     */
    private function isJwtExpired(string $token): bool
    {
        try {
            $parts = explode('.', $token);

            if (count($parts) !== 3) {
                return true;
            }

            // JWT payload is the second segment, base64url-encoded.
            $payload = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true
            );

            if (empty($payload['exp'])) {
                return false; // No expiry claim – assume still valid.
            }

            // Treat the token as expired if it expires within 60 seconds.
            return $payload['exp'] < (time() + 60);
        } catch (\Throwable $e) {
            Log::warning('Could not decode Aadhaar JWT to check expiry', [
                'error' => $e->getMessage(),
            ]);

            return true; // Treat unreadable token as expired to force refresh.
        }
    }

    /**
     * Generate OTP for Aadhaar
     */
    public function generateOtp($aadhaarNumber)
    {
        return Http::withHeaders([
            'accept'        => 'application/json',
            'x-api-key'     => $this->apiKey(),
            'authorization' => $this->getAuthToken(),
            'content-type'  => 'application/json',
            'x-api-version' => '2.0',
        ])->post("{$this->baseUrl()}/otp", [
            '@entity' => 'in.co.sandbox.kyc.aadhaar.okyc.otp.request',
            'aadhaar_number' => $aadhaarNumber,
            'consent' => 'y',
            'reason' => 'for kyc',
        ])->json();
    }

    /**
     * Verify OTP
     */
    public function verifyOtp($referenceId, $otp)
    {
        // OTP must be sent as a string to preserve leading zeros.
        // For example, OTP "012345" would become 12345 if cast to int,
        // causing verification to fail.
        $payload = [
            '@entity'      => 'in.co.sandbox.kyc.aadhaar.okyc.request',
            'reference_id' => (string) $referenceId,
            'otp'          => (string) $otp,
        ];

        Log::info('Aadhaar OTP Verification Request', [
            'reference_id' => $referenceId,
            'otp_length' => strlen($otp),
            'otp_first_char' => substr($otp, 0, 1),
        ]);

        $response = Http::withHeaders([
            'accept'        => 'application/json',
            'authorization' => $this->getAuthToken(),
            'content-type'  => 'application/json',
            'x-api-key'     => $this->apiKey(),
            'x-api-version' => '2.0',
        ])->post("{$this->baseUrl()}/otp/verify", $payload);

        Log::info('Aadhaar OTP Verification Response', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return $response->json();
    }
}
