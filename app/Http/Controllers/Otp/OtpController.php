<?php

namespace App\Http\Controllers\Otp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Otp;
use App\Models\User;
use Carbon\Carbon;
use App\Enums\OtpFailure;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class OtpController extends Controller
{
    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required|string']);
        
        $phone = $request->phone;
        $key = 'otp.request.' . $phone;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'kind' => OtpFailure::Locked->value,
                'minutes' => ceil($seconds / 60),
                'until' => now()->addSeconds($seconds)->toIso8601String()
            ], 429);
        }

        // Check if phone already taken by another user
        $existing = User::where('phone', $phone)->first();
        if ($existing && $request->user() && $existing->id !== $request->user()->id) {
            return response()->json([
                'kind' => OtpFailure::NumberTaken->value,
                'handle' => $existing->handle,
                'createdAt' => $existing->created_at?->toIso8601String()
            ], 409);
        }

        RateLimiter::hit($key, 60); // 1 minute delay between requests

        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        Otp::updateOrCreate(
            ['identifier' => $phone],
            [
                'code' => $code,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'locked_until' => null
            ]
        );

        // Here we would dispatch an SMS job: SendSmsJob::dispatch($phone, $code)
        // For development, we assume it's sent.

        return response()->json(['success' => true]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'code' => 'required|string|size:6'
        ]);

        $phone = $request->phone;
        $otp = Otp::where('identifier', $phone)->first();

        if (!$otp) {
            return response()->json([
                'kind' => OtpFailure::Invalid->value,
                'attemptsLeft' => 0
            ], 422);
        }

        if ($otp->locked_until && $otp->locked_until->isFuture()) {
            return response()->json([
                'kind' => OtpFailure::Locked->value,
                'minutes' => ceil(now()->diffInMinutes($otp->locked_until)),
                'until' => $otp->locked_until->toIso8601String()
            ], 429);
        }

        if ($otp->expires_at->isPast()) {
            return response()->json([
                'kind' => OtpFailure::Expired->value
            ], 422);
        }

        if ($otp->code !== $request->code) {
            $otp->increment('attempts');
            
            if ($otp->attempts >= 3) {
                $otp->update(['locked_until' => now()->addMinutes(15)]);
                return response()->json([
                    'kind' => OtpFailure::Locked->value,
                    'minutes' => 15,
                    'until' => now()->addMinutes(15)->toIso8601String()
                ], 429);
            }

            return response()->json([
                'kind' => OtpFailure::Invalid->value,
                'attemptsLeft' => 3 - $otp->attempts
            ], 422);
        }

        // Verified!
        $otp->delete();

        if ($request->user()) {
            $request->user()->update(['phone' => $phone]);
        }

        return response()->json(['success' => true]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'method' => 'nullable|in:sms,email,call'
        ]);

        // Logic similar to requestOtp but we return a resendAfterSeconds
        $phone = $request->phone;
        $key = 'otp.resend.' . $phone;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'kind' => OtpFailure::Locked->value,
                'minutes' => ceil($seconds / 60),
                'until' => now()->addSeconds($seconds)->toIso8601String()
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        Otp::updateOrCreate(
            ['identifier' => $phone],
            [
                'code' => $code,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'locked_until' => null
            ]
        );

        return response()->json([
            'resendAfterSeconds' => 60
        ]);
    }
}
