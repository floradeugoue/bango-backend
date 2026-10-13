<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OtpChannel;
use App\Http\Controllers\Controller;
use App\Models\User;
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

        // 1. Check backup codes if available
        if (! empty($user->backup_codes) && is_array($user->backup_codes)) {
            $backupCodes = $user->backup_codes;
            $index = array_search($code, $backupCodes, true);
            if ($index !== false) {
                unset($backupCodes[$index]);
                $user->backup_codes = array_values($backupCodes);
                $user->save();
                $isValid = true;
            }
        }

        // 2. Check OTP code
        if (! $isValid) {
            $channel = OtpChannel::from($data['channel']);
            $otpResult = $otpService->verifyOtp($data['destination'], $code, $channel, 'second_factor');

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
}
