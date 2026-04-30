<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

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
     * Generate OTP for Aadhaar
     */
    public function generateOtp($aadhaarNumber)
    {
        return Http::withHeaders([
            'accept' => 'application/json',
            'x-api-key' => $this->apiKey,
            'authorization' => $this->apiToken,
            'content-type' => 'application/json',
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
        $payload = [
            '@entity' => 'in.co.sandbox.kyc.aadhaar.okyc.request',
            'reference_id' => (string) $referenceId, // must be string
            'otp' => $otp,
        ];

        return Http::withHeaders([
            'accept' => 'application/json',
            'authorization' => $this->apiToken,  // sandbox JWT
            'content-type' => 'application/json',
            'x-api-key' => $this->apiKey,
            'x-api-version' => '2.0',
        ])->post("{$this->baseUrl}/otp/verify", $payload)
            ->json();
    }
}
