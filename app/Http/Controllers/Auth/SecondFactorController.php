<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpChannel;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use App\Services\Auth\TotpService;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;

class SecondFactorController extends Controller
{
    public const MAX_CHALLENGE_ATTEMPTS = 5;

    public const MAX_USER_ATTEMPTS = 10;

    public const LOCKOUT_MINUTES = 15;

    public function __invoke(Request $request, OtpService $otpService): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'challenge' => ['nullable', 'string'],
            'identifier' => ['nullable', 'string'],
        ]);

        $challengeId = $request->input('challenge');

        if (! $challengeId && $request->filled('identifier')) {
            $challengeId = Cache::get('2fa.challenge.id.'.$request->input('identifier'));
        }

        if (! $challengeId) {
            $challengeId = Cache::get('2fa.challenge.ip.'.$request->ip());
        }

        // 1. Vérification de l'existence et de l'expiration du challenge
        if (! $challengeId || ! Cache::has('2fa.challenge.'.$challengeId)) {
            return response()->json([
                'kind' => 'challenge-expired',
                'message' => 'Le challenge de double authentification a expiré ou est invalide.',
            ], 422);
        }

        $data = Cache::get('2fa.challenge.'.$challengeId);
        $userId = $data['user_id'] ?? null;
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            // Anti-énumération : retour 422 identique à un challenge expiré
            Cache::forget('2fa.challenge.'.$challengeId);

            return response()->json([
                'kind' => 'challenge-expired',
                'message' => 'Le challenge de double authentification a expiré ou est invalide.',
            ], 422);
        }

        // 2. Limitation globale par compte utilisateur (empêche le contournement par création répétée de challenges)
        $userRateKey = '2fa.attempts.user:'.$user->id;
        if (RateLimiter::tooManyAttempts($userRateKey, self::MAX_USER_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($userRateKey);
            Cache::forget('2fa.challenge.'.$challengeId);

            return response()->json([
                'kind' => 'locked',
                'message' => 'Trop de tentatives de validation pour ce compte. Veuillez réessayer plus tard.',
                'minutes' => ceil($seconds / 60),
                'until' => now()->addSeconds($seconds)->toIso8601String(),
            ], 429);
        }

        // 3. Limitation par challenge individuel (5 tentatives max)
        $challengeAttemptsKey = '2fa.attempts.challenge:'.$challengeId;
        $challengeAttempts = (int) Cache::get($challengeAttemptsKey, 0);

        if ($challengeAttempts >= self::MAX_CHALLENGE_ATTEMPTS) {
            Cache::forget('2fa.challenge.'.$challengeId);
            Cache::forget($challengeAttemptsKey);

            return response()->json([
                'kind' => 'locked',
                'message' => 'Nombre maximal de tentatives atteint pour ce challenge.',
                'minutes' => self::LOCKOUT_MINUTES,
                'until' => now()->addMinutes(self::LOCKOUT_MINUTES)->toIso8601String(),
            ], 429);
        }

        $code = trim($request->input('code'));
        $isValid = false;
        $usedBackupCode = false;

        $backupCodeService = app(BackupCodeService::class);
        $totpService = app(TotpService::class);

        // A. Vérification prioritaire et atomique des codes de secours
        if ($backupCodeService->verifyAndConsume($user, $code)) {
            $isValid = true;
            $usedBackupCode = true;
        }

        // B. Vérification du code TOTP d'application d'authentification
        $channel = $data['channel'] ?? $user->two_factor_method ?? 'sms';

        if (! $isValid && in_array($channel, ['app', 'totp'], true)) {
            if ($totpService->verifyUser($user, $code)) {
                $isValid = true;
            } else {
                $this->recordFailedAttempt($challengeAttemptsKey, $userRateKey);
                $remaining = max(0, self::MAX_CHALLENGE_ATTEMPTS - ($challengeAttempts + 1));

                if ($remaining === 0) {
                    Cache::forget('2fa.challenge.'.$challengeId);
                    Cache::forget($challengeAttemptsKey);

                    return response()->json([
                        'kind' => 'locked',
                        'message' => 'Nombre maximal de tentatives atteint pour ce challenge.',
                        'minutes' => self::LOCKOUT_MINUTES,
                        'until' => now()->addMinutes(self::LOCKOUT_MINUTES)->toIso8601String(),
                    ], 429);
                }

                return response()->json([
                    'kind' => 'invalid-code',
                    'message' => 'Code d\'authentification TOTP ou code de secours invalide.',
                    'attemptsLeft' => $remaining,
                ], 422);
            }
        }

        // C. Vérification du code OTP SMS ou Email
        if (! $isValid) {
            $otpChannel = OtpChannel::tryFrom($channel) ?? OtpChannel::Sms;
            $destination = $data['destination'] ?? ($otpChannel === OtpChannel::Email ? $user->email : $user->phone);

            if (! $destination) {
                return response()->json([
                    'kind' => 'invalid-code',
                    'message' => 'Destination de second facteur introuvable.',
                ], 422);
            }

            $otpResult = $otpService->verifyOtp($destination, $code, $otpChannel, 'second_factor');

            if ($otpResult === true) {
                $isValid = true;
            } else {
                $this->recordFailedAttempt($challengeAttemptsKey, $userRateKey);
                $status = $otpResult['status'] ?? 422;
                $responseData = $otpResult['data'] ?? ['kind' => 'invalid-code'];

                $newAttempts = $challengeAttempts + 1;
                if ($status === 429 || $newAttempts >= self::MAX_CHALLENGE_ATTEMPTS) {
                    Cache::forget('2fa.challenge.'.$challengeId);
                    Cache::forget($challengeAttemptsKey);

                    return response()->json([
                        'kind' => 'locked',
                        'message' => 'Nombre maximal de tentatives atteint pour ce challenge.',
                        'minutes' => self::LOCKOUT_MINUTES,
                        'until' => now()->addMinutes(self::LOCKOUT_MINUTES)->toIso8601String(),
                    ], 429);
                }

                return response()->json($responseData, $status);
            }
        }

        // 4. Invalidation immédiate du challenge (empêche le rejeu et la délivrance de tokens multiples)
        Cache::forget('2fa.challenge.'.$challengeId);
        Cache::forget('2fa.challenge.ip.'.$request->ip());
        Cache::forget($challengeAttemptsKey);
        if ($user->email) {
            Cache::forget('2fa.challenge.id.'.$user->email);
        }
        if ($user->phone) {
            Cache::forget('2fa.challenge.id.'.$user->phone);
        }

        // Réinitialisation du limiteur de compte en cas de validation réussie
        RateLimiter::clear($userRateKey);

        // 5. Configuration de la durée de vie et émission sécurisée du token Passport
        if (! empty($data['persistent'])) {
            Passport::personalAccessTokensExpireIn(now()->addYear());
        } else {
            Passport::personalAccessTokensExpireIn(now()->addDays(7));
        }

        $deviceName = $data['device_name'] ?? 'Application mobile';
        $token = $user->createToken($deviceName)->accessToken;

        $responsePayload = [
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'handle' => $user->handle,
                'display_name' => $user->display_name,
                'avatar_url' => $user->avatar_url,
                'is_verified' => $user->is_verified,
            ],
        ];

        if ($usedBackupCode) {
            $responsePayload['backup_code_used'] = true;
            $responsePayload['remaining_backup_codes'] = $backupCodeService->getRemainingCount($user);
        }

        return response()->json($responsePayload, 200);
    }

    private function recordFailedAttempt(string $challengeAttemptsKey, string $userRateKey): void
    {
        $attempts = (int) Cache::get($challengeAttemptsKey, 0) + 1;
        Cache::put($challengeAttemptsKey, $attempts, now()->addMinutes(10));
        RateLimiter::hit($userRateKey, self::LOCKOUT_MINUTES * 60);
    }
}
