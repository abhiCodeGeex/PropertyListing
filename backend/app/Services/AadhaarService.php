<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AadhaarService
{
    protected $baseUrl;

    protected $apiKey;

    protected $apiToken;

    public function __construct()
    {
        $this->baseUrl = config('services.aadhaar.base_url');
        $this->apiKey = config('services.aadhaar.api_key');
        $this->apiToken = config('services.aadhaar.api_token');
    }

    /**
     * Get a valid sandbox JWT, auto-refreshing via Cache when it expires.
     *
     * The AADHAAR_API_TOKEN env value is a static JWT that expires in ~24 hours.
     * We cache the fresh token for 55 minutes (tokens are valid for 1 hour)
     * and re-authenticate transparently before it expires.
     */
    protected function getAuthToken(): string
    {
        return Cache::remember('aadhaar_auth_token', 3300, function () {
            $response = Http::withHeaders([
                'accept'       => 'application/json',
                'x-api-key'    => $this->apiKey,
                'x-api-secret' => config('services.aadhaar.api_secret'),
                'x-api-version' => '1.0',
            ])->post('https://api.sandbox.co.in/authenticate');

            if ($response->failed()) {
                // Fall back to the static env token rather than crashing.
                Log::warning('Aadhaar sandbox re-authentication failed; using static token', [
                    'status' => $response->status(),
                ]);
                return $this->apiToken;
            }

            $token = $response->json('access_token');
            if (empty($token)) {
                Log::warning('Aadhaar sandbox auth response missing access_token; using static token');
                return $this->apiToken;
            }

            return $token;
        });
    }

    /**
     * Generate OTP for Aadhaar
     */
    public function generateOtp($aadhaarNumber)
    {
        return Http::withHeaders([
            'accept'        => 'application/json',
            'x-api-key'     => $this->apiKey,
            'authorization' => $this->getAuthToken(),
            'content-type'  => 'application/json',
            'x-api-version' => '2.0',
        ])->post("{$this->baseUrl}/otp", [
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
            'x-api-key'     => $this->apiKey,
            'x-api-version' => '2.0',
        ])->post("{$this->baseUrl}/otp/verify", $payload);

        Log::info('Aadhaar OTP Verification Response', [
            'status' => $response->status(),
            'body' => $response->json(),
        ]);

        return $response->json();
    }
}
