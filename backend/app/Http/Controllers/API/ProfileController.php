<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\AadhaarService;
use App\Services\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    protected $aadhaarService;

    public function __construct(
        AadhaarService $aadhaarService,
        private readonly StripeConnectService $stripeConnectService
    ) {
        $this->aadhaarService = $aadhaarService;
    }

    private function profileRules(): array
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => 'required|string|max:100|unique:users,username,'.Auth::id(),
            'password' => 'nullable|string|min:8|confirmed',
            'dob' => 'required|date',
            'current_address' => 'required|string|max:255',
            'native_address' => 'required|string|max:255',
            'aadhar' => 'required|digits:12',
            'aadhar_text' => 'nullable|string|max:255',
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
            'marital_status' => 'required|in:single,married,divorced,widowed',
            'gender' => 'required|in:male,female,other',
            'phone' => 'required|regex:/^[6-9]\d{9}$/',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ];
    }

    // Get logged-in user profile
    public function show()
    {
        $user = \App\Models\User::with('profile')
            ->where('id', Auth::id())
            ->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            ...$user->toArray(),
            'payment_settings' => [
                'stripe_connect' => $this->stripeConnectService->statusPayload($user),
            ],
        ]);
    }

    /**
     * Partial update – saves only the fields that are sent (per-step auto-save).
     * All fields are "sometimes" so missing fields are simply ignored.
     */
    public function partialUpdate(Request $request)
    {
        $data = $request->validate([
            // Step 2 – Personal Details
            'first_name'      => 'sometimes|string|max:255',
            'last_name'       => 'sometimes|string|max:255',
            'dob'             => 'sometimes|date',
            'gender'          => 'sometimes|in:male,female,other',
            'marital_status'  => 'sometimes|in:single,married,divorced,widowed',
            'pan'             => ['sometimes', 'nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
            'aadhar'          => 'sometimes|digits:12',
            'aadhar_text'     => 'sometimes|nullable|string|max:255',
            'profile_image'   => 'sometimes|nullable|image|mimes:jpg,jpeg,png|max:2048',

            // Step 3 – Contact Information
            'current_address' => 'sometimes|string|max:2000',
            'native_address'  => 'sometimes|string|max:2000',
            'phone'           => 'sometimes|regex:/^[6-9]\d{9}$/',

            // Step 4 – Account Security (also handled by the full submit, but included for completeness)
            'username'        => 'sometimes|string|max:100|unique:users,username,'.Auth::id(),
            'password'        => 'sometimes|nullable|string|min:8|confirmed',
        ], [], [
            'dob'             => 'date of birth',
            'current_address' => 'current address',
            'native_address'  => 'native address',
            'aadhar'          => 'aadhaar',
            'aadhar_text'     => 'verified aadhaar name',
            'marital_status'  => 'marital status',
            'profile_image'   => 'profile image',
        ]);

        $user = Auth::user();

        // Update user-level fields when present
        if (isset($data['username'])) {
            $user->username = trim($data['username']);
        }

        if (isset($data['first_name']) || isset($data['last_name'])) {
            $profile = $user->profile()->first();
            $firstName = $data['first_name'] ?? ($profile->first_name ?? '');
            $lastName  = $data['last_name']  ?? ($profile->last_name  ?? '');
            $user->name = trim($firstName.' '.$lastName);
        }

        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        // Remove user-level fields before filling profile
        unset($data['username'], $data['email'], $data['password'], $data['password_confirmation']);

        $profile = $user->profile()->firstOrNew(['user_id' => $user->id]);

        // Handle profile image upload
        if ($request->hasFile('profile_image')) {
            if ($profile->profile_image && Storage::disk('public')->exists($profile->profile_image)) {
                Storage::disk('public')->delete($profile->profile_image);
            }
            $data['profile_image'] = $request->file('profile_image')->store('profiles', 'public');
        } else {
            // Don't touch existing image if no new file was sent
            unset($data['profile_image']);
        }

        try {
            $profile->fill($data);
            $profile->save();
        } catch (\Throwable $e) {
            Log::error('Partial profile save failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Failed to save step data. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Step data saved.',
            'user'    => $user,
            'profile' => $profile,
        ]);
    }

    // Create or Update Profile
    public function storeOrUpdate(Request $request)
    {
        $data = $request->validate($this->profileRules(), [], [
            'dob' => 'date of birth',
            'current_address' => 'current address',
            'native_address' => 'native address',
            'aadhar' => 'aadhaar',
            'aadhar_text' => 'verified aadhaar name',
            'marital_status' => 'marital status',
            'profile_image' => 'profile image',
        ]);

        // Update User
        $user = Auth::user();
        $user->username = trim($data['username']);
        $user->name = trim($data['first_name'].' '.$data['last_name']);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        // Remove user table fields from $data before profile update
        unset($data['username'], $data['email'], $data['password']);

        $profile = $user->profile()->firstOrNew(['user_id' => $user->id]);

        // Keep prior verified name when editing an older profile that never posts this hidden field.
        if (empty($data['aadhar_text'])) {
            $data['aadhar_text'] = $profile->aadhar_text
                ?: trim($data['first_name'].' '.$data['last_name']);
        }

        // Handle profile image
        if ($request->hasFile('profile_image')) {
            // Delete old image if exists
            if ($profile->profile_image && Storage::disk('public')->exists($profile->profile_image)) {
                Storage::disk('public')->delete($profile->profile_image);
            }
            // Store new image
            $data['profile_image'] = $request->file('profile_image')->store('profiles', 'public');
        } else {
            // Keep existing image if no new file is uploaded
            $data['profile_image'] = $profile->profile_image ?? null;
        }

        // Update or create profile
        try {
            $profile->fill($data);
            $profile->save();
        } catch (\Throwable $e) {
            Log::error('Profile save failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'We could not save your profile. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
            'profile' => $profile,
        ]);
    }

    public function generateOtp(Request $request)
    {
        $request->validate([
            'aadhaar_number' => 'required|digits:12',
        ], [], [
            'aadhaar_number' => 'aadhaar number',
        ]);

        $response = $this->aadhaarService->generateOtp($request->aadhaar_number);

        return response()->json($response);
    }

    /**
     * Step 2: Verify OTP
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'reference_id' => 'required',
            'otp' => 'required|digits:6',
        ], [], [
            'reference_id' => 'reference id',
            'otp' => 'otp',
        ]);

        // Debug logging for OTP verification
        Log::info('Aadhaar OTP Verification Endpoint', [
            'reference_id' => $request->reference_id,
            'otp' => $request->otp,
            'otp_type' => gettype($request->otp),
            'otp_length' => strlen($request->otp),
            'otp_first_char' => substr($request->otp, 0, 1),
        ]);

        $response = $this->aadhaarService->verifyOtp(
            $request->reference_id,
            $request->otp
        );

        Log::info('Aadhaar OTP Verification Result', [
            'response' => $response,
        ]);

        return response()->json($response);
    }

    public function createSandboxToken(Request $request)
    {
        $url = 'https://api.sandbox.co.in/authenticate';

        $headers = [
            'accept: application/json',
            'x-api-key: '.env('AADHAAR_API_KEY'),
            'x-api-secret: '.env('AADHAAR_API_SECRET'),
            'x-api-version: 1.0',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);

        return response()->json($result);
    }

    public function sendEmailOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
        ]);

        if ($request->user()->email === $request->email) {
            return response()->json([
                'errors' => ['email' => ['This is already your current email address.']],
            ], 422);
        }

        $otp = rand(100000, 999999);
        Cache::put('email_otp_'.$request->user()->id, [
            'otp' => $otp,
            'email' => $request->email,
        ], now()->addMinutes(10));

        Mail::raw("Your OTP is: $otp", function ($m) use ($request) {
            $m->to($request->email)->subject('Email Change OTP');
        });

        return response()->json(['message' => 'OTP sent successfully']);
    }

    public function verifyEmailOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|digits:6',
        ], [], [
            'email' => 'email',
            'otp' => 'otp',
        ]);

        $cached = Cache::get('email_otp_'.$request->user()->id);
        if (! $cached || $cached['otp'] != $request->otp || $cached['email'] != $request->email) {
            return response()->json(['message' => 'Invalid or expired OTP'], 422);
        }

        $user = $request->user();
        $user->email = $request->email;
        $user->save();

        Cache::forget('email_otp_'.$user->id);

        return response()->json(['message' => 'Email updated successfully']);
    }

    public function createStripeConnectAccountLink(Request $request)
    {
        $user = $request->user();
        $this->stripeConnectService->ensureEligibleUser($user);

        return response()->json([
            'message' => 'Stripe onboarding link created successfully.',
            'link' => $this->stripeConnectService->createOnboardingLink($user),
            'payment_settings' => [
                'stripe_connect' => $this->stripeConnectService->statusPayload($user->fresh()),
            ],
        ]);
    }

    public function refreshStripeConnectStatus(Request $request)
    {
        $user = $request->user();
        $this->stripeConnectService->ensureEligibleUser($user);

        return response()->json([
            'message' => 'Stripe Connect status refreshed successfully.',
            'payment_settings' => [
                'stripe_connect' => $this->stripeConnectService->syncAccountStatus($user),
            ],
        ]);
    }
}
