<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();
        $successMessage = 'If the email is registered, a password reset link will be sent shortly.';

        if (! $user) {
            return response()->json(['message' => $successMessage], 200);
        }

        $token = Password::getRepository()->create($user);

        try {
            $user->sendPasswordResetNotification($token);

            return response()->json(['message' => $successMessage], 200);

        } catch (\Exception $e) {
            Log::error('Reset password mail failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to send reset link.',
                'reason' => $e->getMessage(),
            ], 500);
        }
    }
}
