<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin\AdminAuditLog;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AdminSecurityController extends Controller
{
    public function __construct(
        protected TotpService $totpService,
        protected BackupCodeService $backupCodeService,
    ) {}

    /**
     * GET /admin/v1/me/security
     * Consulter l'état de sécurité 2FA de l'opérateur connecté.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $isEnabled = ! empty($user->two_factor_secret)
            && ! in_array($user->two_factor_method, [null, '', 'off', 'none'], true);

        $confirmedAt = $user->two_factor_confirmed_at;
        $backupCodesCount = is_array($user->backup_codes) ? count($user->backup_codes) : 0;

        return response()->json([
            'two_factor_enabled' => $isEnabled,
            'two_factor_method' => $isEnabled ? ($user->two_factor_method ?? 'totp') : 'off',
            'two_factor_confirmed_at' => $confirmedAt?->toIso8601String(),
            'enabled_at' => $confirmedAt?->toIso8601String(),
            'confirmedAt' => $confirmedAt?->toIso8601String(),
            'twoFactorEnabled' => $isEnabled,
            'twoFactorMethod' => $isEnabled ? ($user->two_factor_method ?? 'totp') : 'off',
            'has_backup_codes' => $backupCodesCount > 0,
            'remaining_backup_codes' => $backupCodesCount,
        ], 200);
    }

    /**
     * POST /admin/v1/me/2fa/setup
     * Génère un nouveau secret TOTP Base32 et l'URI otpauth:// pour l'application Authenticator.
     */
    public function setup2fa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $secret = $this->totpService->generateSecret();
        $account = $user->email ?? $user->handle ?? (string) $user->id;
        $otpauthUri = $this->totpService->getOtpAuthUri($secret, $account, 'BANGO ADMIN');

        // Stockage temporaire du secret en attente pendant 15 minutes
        Cache::put("admin_2fa_pending_secret:{$user->id}", $secret, now()->addMinutes(15));

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $otpauthUri,
            'otpauthUri' => $otpauthUri,
            'issuer' => 'BANGO ADMIN',
            'account' => $account,
        ], 200);
    }

    /**
     * POST /admin/v1/me/2fa/confirm
     * Valide le premier code saisi, active le TOTP et délivre les 10 codes de secours.
     */
    public function confirm2fa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $rateKey = "admin_2fa_confirm:{$user->id}";
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return response()->json([
                'code' => 'rate_limited',
                'message' => "Trop de tentatives infructueuses. Veuillez réessayer dans {$seconds} secondes.",
            ], 429);
        }

        $pendingSecret = Cache::get("admin_2fa_pending_secret:{$user->id}");
        if (! $pendingSecret) {
            return response()->json([
                'code' => 'no_pending_setup',
                'message' => 'Aucune configuration de double authentification en attente ou délai expiré.',
            ], 400);
        }

        $code = trim((string) $request->input('code'));
        if (! $this->totpService->verifyCode($pendingSecret, $code)) {
            RateLimiter::hit($rateKey, 900);
            $attempts = RateLimiter::attempts($rateKey);

            if ($attempts >= 5) {
                Cache::forget("admin_2fa_pending_secret:{$user->id}");

                return response()->json([
                    'code' => 'locked',
                    'message' => 'Trop de tentatives infructueuses. La configuration 2FA a été annulée.',
                ], 429);
            }

            return response()->json([
                'code' => 'otp_invalid',
                'message' => 'Code TOTP invalide.',
                'attempts_left' => max(0, 5 - $attempts),
            ], 422);
        }

        RateLimiter::clear($rateKey);
        Cache::forget("admin_2fa_pending_secret:{$user->id}");

        $backupCodes = $this->backupCodeService->generateCodes(10);

        $user->update([
            'two_factor_method' => 'totp',
            'two_factor_secret' => Crypt::encryptString($pendingSecret),
            'two_factor_confirmed_at' => now(),
            'backup_codes' => $backupCodes['hashed'],
        ]);

        // Journalisation de l'activation
        AdminAuditLog::logEvent('auth.2fa.enabled', $user, $request->ip(), $request->userAgent(), [
            'method' => 'totp',
            'backup_codes_count' => 10,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Double authentification activée avec succès.',
            'recoveryKeys' => $backupCodes['plain'],
            'recovery_keys' => $backupCodes['plain'],
            'backup_codes' => $backupCodes['plain'],
            'two_factor_enabled' => true,
            'count' => 10,
        ], 200);
    }

    /**
     * POST /admin/v1/me/2fa/disable
     * Désactivation sécurisée du TOTP avec mot de passe ou code 2FA valide.
     */
    public function disable2fa(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'password' => ['nullable', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        $password = $request->input('password');
        $code = $request->input('code');

        $authorized = false;

        if (! empty($password) && Hash::check($password, $user->password)) {
            $authorized = true;
        } elseif (! empty($code) && ($this->totpService->verifyUser($user, $code) || $this->backupCodeService->verifyAndConsume($user, $code))) {
            $authorized = true;
        }

        if (! $authorized) {
            return response()->json([
                'code' => 'unauthorized_action',
                'message' => 'Mot de passe actuel ou code 2FA valide requis pour désactiver la protection.',
            ], 403);
        }

        $user->update([
            'two_factor_method' => 'off',
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'backup_codes' => [],
        ]);

        // Journalisation de la désactivation
        AdminAuditLog::logEvent('auth.2fa.disabled', $user, $request->ip(), $request->userAgent(), [
            'method' => 'totp',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Double authentification désactivée avec succès.',
            'two_factor_enabled' => false,
            'two_factor_method' => 'off',
        ], 200);
    }
}
