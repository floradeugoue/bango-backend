<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SigninRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;

class SigninController extends Controller
{
    public function __invoke(SigninRequest $request): JsonResponse
    {
        $key = 'signin.' . Str::transliterate(Str::lower($request->identifier)) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'kind' => 'locked',
                'minutes' => ceil($seconds / 60),
                'until' => now()->addSeconds($seconds)->toIso8601String()
            ], 429);
        }

        $user = User::where('email', $request->identifier)
                    ->orWhere('phone', $request->identifier)
                    ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 60 * 15); // lock for 15 mins after 5 attempts
            
            return response()->json([
                'kind' => 'invalid-credentials',
                'attemptsLeft' => RateLimiter::retriesLeft($key, 5)
            ], 401);
        }

        if ($user->status === 'suspended' || $user->status === 'deleted') {
            return response()->json(['kind' => $user->status], 403);
        }

        RateLimiter::clear($key);

        // persistent means we could potentially issue a longer lived token if configured, 
        // but with Passport default is long enough, or we use a personal access token.
        $token = $user->createToken('auth_token')->accessToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'handle' => $user->handle,
                'display_name' => $user->display_name,
                'avatar_url' => $user->avatar_url,
                'is_verified' => $user->is_verified,
            ]
        ], 200);
    }
}
