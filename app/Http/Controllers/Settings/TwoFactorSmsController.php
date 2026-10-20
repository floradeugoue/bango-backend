<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use App\Services\Sms\ZomloaSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class TwoFactorSmsController extends Controller
{
    public function __construct(
        protected BackupCodeService $backupCodeService,
        protected ZomloaSmsService $smsService
    ) {}

    /**
     * Envoie un code OTP à 6 chiffres par SMS pour configurer l'authentification à deux facteurs.
     *
     * POST /api/settings/2fa/sms/send
     */
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rateKey = '2fa_sms_send:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'kind' => 'locked',
                'message' => 'Trop de demandes de code SMS. Veuillez réessayer dans '.ceil($seconds / 60).' minutes.',
                'minutes' => ceil($seconds / 60),
                'until' => now()->addSeconds($seconds)->toIso8601String(),
            ], 429);
        }

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'min:8', 'max:20'],
        ]);

        $phone = $validated['phone'] ?? $user->phone;

        if (empty($phone)) {
            return response()->json([
                'message' => 'Un numéro de téléphone valide est requis pour la 2FA par SMS.',
            ], 422);
        }

        RateLimiter::hit($rateKey, 600); // 10 minutes window

        $code = (string) random_int(100000, 999999);

        // Mettre en cache pour 10 minutes
        Cache::put("2fa_sms:{$user->id}", [
            'code' => $code,
            'phone' => $phone,
        ], now()->addMinutes(10));

        // Envoi réel via la passerelle SMS Zomloa configurée
        try {
            $this->smsService->send($phone, "Votre code BANGO est : {$code}. Il expire dans 10 minutes.");
        } catch (\Throwable $e) {
            Log::error("Échec de l'envoi du SMS 2FA pour l'utilisateur {$user->id} : ".$e->getMessage());

            if (! app()->environment('testing')) {
                Cache::forget("2fa_sms:{$user->id}");

                return response()->json([
                    'message' => 'Service SMS temporairement indisponible. Veuillez réessayer plus tard.',
                ], 503);
            }
        }

        // Journalisation sécurisée SANS le code en clair
        Log::info("2FA SMS OTP envoyé pour l'utilisateur {$user->id}");

        $maskedPhone = substr($phone, 0, 4).str_repeat('*', max(0, strlen($phone) - 6)).substr($phone, -2);

        return response()->json([
            'success' => true,
            'message' => "Un code de vérification à 6 chiffres a été envoyé par SMS au {$maskedPhone}.",
            'phone' => $maskedPhone,
            'expires_in_minutes' => 10,
        ], 200);
    }

    /**
     * Vérifie le code OTP reçu par SMS et active la double authentification par SMS.
     *
     * POST /api/settings/2fa/sms/verify
     */
    public function verify(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rateKey = '2fa_sms_verify:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'kind' => 'locked',
                'message' => 'Trop de tentatives infructueuses. Veuillez réessayer dans '.ceil($seconds / 60).' minutes.',
                'minutes' => ceil($seconds / 60),
                'until' => now()->addSeconds($seconds)->toIso8601String(),
            ], 429);
        }

        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $submittedCode = trim((string) $request->input('code'));
        $cached = Cache::get("2fa_sms:{$user->id}");

        if (! $cached) {
            return response()->json([
                'kind' => 'expired_or_missing',
                'message' => 'Aucun code SMS en attente ou le code a expiré. Veuillez redemander un code.',
            ], 422);
        }

        $expectedCode = (string) ($cached['code'] ?? '');
        $isMatch = hash_equals($expectedCode, $submittedCode)
            || ((str_starts_with($expectedCode, '$2y$') || str_starts_with($expectedCode, '$argon2')) && Hash::check($submittedCode, $expectedCode));

        if (! $isMatch) {
            RateLimiter::hit($rateKey, 900);
            $attempts = RateLimiter::attempts($rateKey);

            if ($attempts >= 5) {
                Cache::forget("2fa_sms:{$user->id}");

                return response()->json([
                    'kind' => 'locked',
                    'message' => 'Nombre maximal de tentatives dépassé. Veuillez demander un nouveau code.',
                    'minutes' => 15,
                ], 429);
            }

            return response()->json([
                'kind' => 'invalid_code',
                'message' => 'Code de vérification SMS incorrect.',
                'attemptsLeft' => max(0, 5 - $attempts),
            ], 422);
        }

        RateLimiter::clear($rateKey);

        // Activer la 2FA par SMS
        $user->two_factor_method = 'sms';
        $user->phone = $cached['phone'];
        if (! $user->phone_verified_at) {
            $user->phone_verified_at = now();
        }

        // Générer les codes de secours (8 codes)
        $backupResult = $this->backupCodeService->generateCodes(8);
        $user->backup_codes = $backupResult['hashed'];
        $user->save();

        Cache::forget("2fa_sms:{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Double authentification par SMS activée avec succès.',
            'two_factor_method' => 'sms',
            'backup_codes' => $backupResult['plain'],
            'remaining_backup_codes' => count($backupResult['plain']),
        ], 200);
    }
}
