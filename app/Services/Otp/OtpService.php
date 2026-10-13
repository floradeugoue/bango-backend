<?php

namespace App\Services\Otp;

use App\Enums\OtpChannel;
use App\Enums\OtpFailure;
use App\Models\Otp;
use App\Services\Sms\ZomloaSmsService;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class OtpService
{
    public function __construct(
        protected OtpEmailService $emailService,
        protected ZomloaSmsService $smsService
    ) {}

    /**
     * Send an OTP via the specified channel.
     * Returns an array with an error response if failed (rate limit, etc.),
     * or true if successful.
     */
    public function sendOtp(string $identifier, OtpChannel $channel, string $context = 'default', int $ttlMinutes = 10): bool|array
    {
        $key = 'otp.request.'.$context.'.'.$channel->value.'.'.$identifier;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return [
                'status' => 429,
                'data' => [
                    'kind' => OtpFailure::Locked->value,
                    'minutes' => ceil($seconds / 60),
                    'until' => now()->addSeconds($seconds)->toIso8601String(),
                ],
            ];
        }

        RateLimiter::hit($key, 60);

        $code = (string) random_int(100000, 999999);

        Otp::updateOrCreate(
            ['identifier' => $identifier, 'channel' => $channel->value, 'context' => $context],
            [
                'code' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($ttlMinutes),
                'locked_until' => null,
            ]
        );

        try {
            if ($channel === OtpChannel::Email) {
                Log::info('OTP email send requested', ['email' => $identifier, 'context' => $context]);
                $this->emailService->send($identifier, $code);
            } elseif ($channel === OtpChannel::Sms) {
                Log::info('OTP SMS send requested', ['phone' => $identifier, 'context' => $context]);
                $this->smsService->send($identifier, "Votre code BANGO est : {$code}. Il expire dans {$ttlMinutes} minutes.");
            }
        } catch (Exception $e) {
            Otp::where('identifier', $identifier)
                ->where('channel', $channel->value)
                ->where('context', $context)
                ->delete();

            return [
                'status' => 503,
                'data' => ['message' => 'Service indisponible. Veuillez réessayer plus tard.'],
            ];
        }

        return true;
    }

    /**
     * Verify an OTP.
     * Returns true on success, or an array with an error response.
     */
    public function verifyOtp(string $identifier, string $code, OtpChannel $channel, string $context = 'default'): bool|array
    {
        $otp = Otp::where('identifier', $identifier)
            ->where('channel', $channel->value)
            ->where('context', $context)
            ->first();

        if (! $otp) {
            return [
                'status' => 422,
                'data' => [
                    'kind' => OtpFailure::Invalid->value,
                    'attemptsLeft' => 0,
                ],
            ];
        }

        if ($otp->locked_until && $otp->locked_until->isFuture()) {
            return [
                'status' => 429,
                'data' => [
                    'kind' => OtpFailure::Locked->value,
                    'minutes' => ceil(now()->diffInMinutes($otp->locked_until)),
                    'until' => $otp->locked_until->toIso8601String(),
                ],
            ];
        }

        if ($otp->expires_at->isPast()) {
            return [
                'status' => 422,
                'data' => ['kind' => OtpFailure::Expired->value],
            ];
        }

        $isValid = Hash::check($code, $otp->code) || ($otp->code === $code);

        if (! $isValid) {
            $otp->increment('attempts');

            if ($otp->attempts >= 5) {
                $otp->update(['locked_until' => now()->addMinutes(15)]);

                return [
                    'status' => 429,
                    'data' => [
                        'kind' => OtpFailure::Locked->value,
                        'minutes' => 15,
                        'until' => now()->addMinutes(15)->toIso8601String(),
                    ],
                ];
            }

            return [
                'status' => 422,
                'data' => [
                    'kind' => OtpFailure::Invalid->value,
                    'attemptsLeft' => 5 - $otp->attempts,
                ],
            ];
        }

        // Verified!
        $otp->delete();

        return true;
    }
}
