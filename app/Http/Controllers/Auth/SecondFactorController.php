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
use Laravel\Passport\Passport;

class SecondFactorController extends Controller
{
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

        if (! $challengeId || ! Cache::has('2fa.challenge.'.$challengeId)) {
            return response()->json([
                'kind' => 'challenge-expired',
                'message' => 'Le challenge de double authentification a expiré ou est invalide.',
            ], 422);
        }

        $data = Cache::get('2fa.challenge.'.$challengeId);
        $user = User::find($data['user_id']);

        if (! $user) {
            return response()->json(['kind' => 'user-not-found'], 404);
        }

        $code = trim($request->input('code'));
        $isValid = false;
        $usedBackupCode = false;

        $backupCodeService = app(BackupCodeService::class);
        $totpService = app(TotpService::class);

        // 1. Check backup codes atomically with row lock
        if ($backupCodeService->verifyAndConsume($user, $code)) {
            $isValid = true;
            $usedBackupCode = true;
        }

        // 2. Check TOTP authenticator app code
        $channel = $data['channel'] ?? $user->two_factor_method ?? 'sms';

        if (! $isValid && $channel === 'app') {
            if ($totpService->verifyUser($user, $code)) {
                $isValid = true;
            } else {
                return response()->json([
                    'kind' => 'invalid-code',
                    'message' => 'Code d\'authentification TOTP ou code de secours invalide.',
                ], 422);
            }
        }

        // 3. Check SMS / Email OTP code
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
                return response()->json($otpResult['data'] ?? ['kind' => 'invalid-code'], $otpResult['status'] ?? 422);
            }
        }

        // Clear challenge cache
        Cache::forget('2fa.challenge.'.$challengeId);
        Cache::forget('2fa.challenge.ip.'.$request->ip());

        // Configure token lifetime
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
}
