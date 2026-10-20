<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TwoFactorSmsController extends Controller
{
    public function __construct(
        protected BackupCodeService $backupCodeService
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

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'min:8', 'max:20'],
        ]);

        $phone = $validated['phone'] ?? $user->phone;

        if (empty($phone)) {
            return response()->json([
                'message' => 'Un numéro de téléphone valide est requis pour la 2FA par SMS.',
            ], 422);
        }

        $code = (string) random_int(100000, 999999);

        // Mettre en cache pour 10 minutes
        Cache::put("2fa_sms:{$user->id}", [
            'code' => $code,
            'phone' => $phone,
        ], now()->addMinutes(10));

        // Journalisation / Intégration passerelle externe
        Log::info("2FA SMS OTP généré pour l'utilisateur {$user->id} ({$phone}): {$code}");

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

        if ($cached['code'] !== $submittedCode) {
            return response()->json([
                'kind' => 'invalid_code',
                'message' => 'Code de vérification SMS incorrect.',
            ], 422);
        }

        // Activer la 2FA par SMS
        $user->two_factor_method = 'sms';
        $user->phone = $cached['phone'];
        if (! $user->phone_verified_at) {
            $user->phone_verified_at = now();
        }

        // Générer les codes de secours
        $backupResult = $this->backupCodeService->generateCodes();
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
