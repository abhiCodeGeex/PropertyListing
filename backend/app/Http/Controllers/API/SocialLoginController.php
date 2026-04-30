<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialLoginController extends Controller
{
    private function oauthCallbackResponse(array $payload)
    {
        return response()->view('oauth.callback', [
            'frontendUrl' => rtrim(config('app.url_frontend', env('FRONTEND_URL', 'http://localhost:4200')), '/'),
            'payloadJson' => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function redirect(string $provider)
    {
        $client = new Client(['verify' => false]);

        // Popup-friendly OAuth (stateless)
        return Socialite::driver($provider)->setHttpClient($client)->stateless()->redirect();
    }

    public function callback(Request $request, string $provider)
    {
        try {
            $client = new Client(['verify' => false]);
            $social = Socialite::driver($provider)->setHttpClient($client)->stateless()->user();

            $email = $social->getEmail();
            $user = $email ? User::where('email', $email)->first() : null;

            if (! $user) {
                $user = User::where('provider', $provider)
                    ->where('provider_id', $social->getId())
                    ->first();
            }

            if (! $user) {
                $displayName = $social->getName() ?: ($social->getNickname() ?: 'User '.Str::random(6));
                $baseUsername = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($social->getNickname() ?: $displayName)) ?: 'user';
                $username = $baseUsername;
                $suffix = 1;

                while (User::where('username', $username)->exists()) {
                    $username = "{$baseUsername}_{$suffix}";
                    $suffix++;
                }

                $user = User::create([
                    'name' => $displayName,
                    'username' => $username,
                    'email' => $email ?: ($social->getId().'@'.$provider.'.local'),
                    'password' => bcrypt(Str::random(32)),
                    'provider' => $provider,
                    'provider_id' => $social->getId(),
                    'photo_url' => $social->getAvatar(),
                ]);
            } else {
                $user->update([
                    'name' => $user->name ?: ($social->getName() ?: $social->getNickname()),
                    'provider' => $provider,
                    'provider_id' => $social->getId(),
                    'photo_url' => $social->getAvatar(),
                ]);
            }

            if (method_exists($user, 'roles') && $user->roles->isEmpty()) {
                $user->assignRole('tenant');
            }

            $user->loadMissing('profile', 'roles');
            $token = $user->createToken('API Token')->accessToken;

            return $this->oauthCallbackResponse([
                'token' => $token,
                'user' => $user->only(['id', 'name', 'username', 'email', 'photo_url']),
                'roles' => $user->roles->pluck('name')->values()->all(),
                'profile' => $user->profile?->toArray(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Social login failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return $this->oauthCallbackResponse([
                'message' => 'Social login could not be completed. Please try again.',
            ]);
        }
    }
}
