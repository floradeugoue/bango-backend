<?php

namespace App\Services\Otp;

use App\Models\Otp;
use App\Enums\OtpChannel;
use App\Enums\OtpFailure;
use Illuminate\Support\Facades\RateLimiter;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Services\Otp\OtpEmailService;
use App\Services\Sms\ZomloaSmsService;

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
        $key = 'otp.request.' . $context . '.' . $channel->value . '.' . $identifier;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return [
                'status' => 429,
                'data' => [
                    'kind' => OtpFailure::Locked->value,
                    'minutes' => ceil($seconds / 60),
                    'until' => now()->addSeconds($seconds)->toIso8601String()
                ]
            ];
        }

        RateLimiter::hit($key, 60);

        // TODO: Hash the OTP code before storing it in production for security.
        $code = str_pad((string)rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        Otp::updateOrCreate(
            ['identifier' => $identifier, 'channel' => $channel->value, 'context' => $context],
            [
                'code' => $code,
                'attempts' => 0,
                'expires_at' => now()->addMinutes($ttlMinutes),
                'locked_until' => null
            ]
        );

        try {
            if ($channel === OtpChannel::Email) {
                Log::info('OTP email send requested', ['email' => $identifier, 'context' => $context]);
                $this->emailService->send($identifier, $code);
            } else if ($channel === OtpChannel::Sms) {
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
                'data' => ['message' => 'Service indisponible. Veuillez réessayer plus tard.']
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

        if (!$otp) {
            return [
                'status' => 422,
                'data' => [
                    'kind' => OtpFailure::Invalid->value,
                    'attemptsLeft' => 0
                ]
            ];
        }

        if ($otp->locked_until && $otp->locked_until->isFuture()) {
            return [
                'status' => 429,
                'data' => [
                    'kind' => OtpFailure::Locked->value,
                    'minutes' => ceil(now()->diffInMinutes($otp->locked_until)),
                    'until' => $otp->locked_until->toIso8601String()
                ]
            ];
        }

        if ($otp->expires_at->isPast()) {
            return [
                'status' => 422,
                'data' => ['kind' => OtpFailure::Expired->value]
            ];
        }

        if ($otp->code !== $code) {
            $otp->increment('attempts');
            
            if ($otp->attempts >= 3) {
                $otp->update(['locked_until' => now()->addMinutes(15)]);
                return [
                    'status' => 429,
                    'data' => [
                        'kind' => OtpFailure::Locked->value,
                        'minutes' => 15,
                        'until' => now()->addMinutes(15)->toIso8601String()
                    ]
                ];
            }

            return [
                'status' => 422,
                'data' => [
                    'kind' => OtpFailure::Invalid->value,
                    'attemptsLeft' => 3 - $otp->attempts
                ]
            ];
        }

        // Verified!
        $otp->delete();

        return true;
    }
}
