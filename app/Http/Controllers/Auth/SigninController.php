<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SigninRequest;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

class SigninController extends Controller
{
    public function __invoke(SigninRequest $request): JsonResponse
    {
        $key = 'signin.'.Str::transliterate(Str::lower($request->identifier)).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'kind' => 'locked',
                'minutes' => ceil($seconds / 60),
                'until' => now()->addSeconds($seconds)->toIso8601String(),
            ], 429);
        }

        $user = User::withTrashed()
            ->where('email', $request->identifier)
            ->orWhere('phone', $request->identifier)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 60 * 15); // lock for 15 mins after 5 attempts

            return response()->json([
                'kind' => 'invalid-credentials',
                'attemptsLeft' => RateLimiter::retriesLeft($key, 5),
            ], 401);
        }

        if ($user->trashed()) {
            if ($user->scheduled_for_deletion_at && $user->scheduled_for_deletion_at->isFuture()) {
                return response()->json([
                    'kind' => 'scheduled_for_deletion',
                    'scheduled_for_deletion_at' => $user->scheduled_for_deletion_at->toIso8601String(),
                ], 403);
            }

            // If the 30 days have passed, we don't let them log in or restore (or they are permanently deleted by a job)
            return response()->json(['kind' => 'deleted'], 403);
        }

        if ($user->status === 'blocked') {
            return response()->json(['kind' => 'blocked'], 403);
        }

        if ($user->status === 'suspended' || $user->status === 'deleted') {
            return response()->json(['kind' => $user->status], 403);
        }

        if ($user->status === 'paused') {
            $user->update(['status' => 'active']);
        }

        RateLimiter::clear($key);

        $deviceName = $request->input('device_name') ?: $request->header('X-Device-Name') ?: 'Application mobile';

        // 2FA check
        if (! empty($user->two_factor_method) && $user->two_factor_method !== 'off') {
            $channel = $user->two_factor_method === 'email' ? OtpChannel::Email : OtpChannel::Sms;
            $destination = $user->two_factor_method === 'email' ? $user->email : $user->phone;

            if (empty($destination)) {
                $channel = OtpChannel::Email;
                $destination = $user->email;
            }

            $challengeId = Str::random(40);
            Cache::put('2fa.challenge.'.$challengeId, [
                'user_id' => $user->id,
                'persistent' => $request->boolean('persistent'),
                'device_name' => $deviceName,
                'destination' => $destination,
                'channel' => $channel->value,
            ], now()->addMinutes(10));

            Cache::put('2fa.challenge.ip.'.$request->ip(), $challengeId, now()->addMinutes(10));
            if ($user->email) {
                Cache::put('2fa.challenge.id.'.$user->email, $challengeId, now()->addMinutes(10));
            }
            if ($user->phone) {
                Cache::put('2fa.challenge.id.'.$user->phone, $challengeId, now()->addMinutes(10));
            }

            $otpService = app(OtpService::class);
            $otpService->sendOtp($destination, $channel, 'second_factor');

            return response()->json([
                'kind' => 'second-factor',
                'destination' => $this->maskDestination($destination, $channel),
                'challenge' => $challengeId,
            ], 200);
        }

        if ($request->boolean('persistent')) {
            Passport::personalAccessTokensExpireIn(now()->addYear());
        } else {
            Passport::personalAccessTokensExpireIn(now()->addDays(7));
        }

        $token = $user->createToken($deviceName)->accessToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'handle' => $user->handle,
                'display_name' => $user->display_name,
                'avatar_url' => $user->avatar_url,
                'is_verified' => $user->is_verified,
            ],
        ], 200);
    }

    private function maskDestination(string $target, OtpChannel $channel): string
    {
        if ($channel === OtpChannel::Email) {
            $parts = explode('@', $target);
            $name = $parts[0];
            $domain = $parts[1] ?? '';
            $len = strlen($name);
            $maskedName = $len > 2 ? substr($name, 0, 1).str_repeat('*', $len - 2).substr($name, -1) : $name.'***';

            return $maskedName.'@'.$domain;
        }

        $len = strlen($target);
        if ($len > 6) {
            return substr($target, 0, 4).' '.str_repeat('*', $len - 6).substr($target, -2);
        }

        return $target;
    }
}
