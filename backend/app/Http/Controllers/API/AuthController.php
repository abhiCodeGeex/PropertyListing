<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function authPayload(User $user): array
    {
        return [
            'user' => $user->only(['id', 'name', 'username', 'email', 'photo_url']),
            'roles' => $user->getRoleNames(),
            'profile' => $user->profile ?? null,
        ];
    }

    /** Login user */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ], [], [
            'email' => 'email',
            'password' => 'password',
        ]);

        $user = User::with('profile')->where('email', trim($credentials['email']))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Please verify your email before logging in.',
                'unverified' => true,
            ], 403);
        }

        $tokenResult = $user->createToken('API Token');
        $token = $tokenResult->accessToken;

        return response()->json([
            'token' => $token,
            ...$this->authPayload($user),
        ]);
    }

    /** Signup user */
    public function signup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'recaptcha' => 'required',
            'role' => 'required|string|in:owner,tenant',
        ], [], [
            'name' => 'name',
            'email' => 'email',
            'password' => 'password',
            'role' => 'role',
            'recaptcha' => 'captcha',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** Verify Google reCAPTCHA */
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asForm()
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => config('services.recaptcha.secret'),
                    'response' => $request->recaptcha,
                ]);
        } catch (\Throwable $e) {
            Log::warning('reCAPTCHA verification request failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Could not verify captcha. Check your connection and try again.',
            ], 422);
        }

        if ($response->failed() || ! $response->json('success')) {
            return response()->json(['message' => 'Captcha verification failed. Please try again.'], 422);
        }

        /** Create user inside transaction */
        DB::beginTransaction();

        try {
            $displayName = trim($request->name);
            $baseUsername = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($displayName)) ?: 'user';
            $username = $baseUsername;
            $suffix = 1;

            while (User::where('username', $username)->exists()) {
                $username = "{$baseUsername}_{$suffix}";
                $suffix++;
            }

            /** Create User */
            $user = User::create([
                'name' => $displayName,
                'username' => $username,
                'email' => trim($request->email),
                'password' => Hash::make($request->password),
            ]);

            /** Assign Role */
            $user->syncRoles([$request->role]);

            /** Create App Notification */
            NotificationService::create(
                userId: $user->id,
                type: 'signup',
                title: 'Welcome!',
                model: $user,
                mailClass: null,
                reason: 'User registered',
                role: $request->role ?? 'user'
            );

            DB::commit();
            $user->notify(new VerifyEmailNotification);

            return response()->json([
                'message' => 'Signup successful. Verification email sent.',
                'user' => $user->only(['id', 'name', 'username', 'email']),
                'role' => $request->role,
            ], 201);
        } catch (\Throwable $e) {

            DB::rollBack();
            Log::error('Signup failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'We could not complete signup. Please try again.',
            ], 500);
        }
    }

    /** Logout user */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }
}
