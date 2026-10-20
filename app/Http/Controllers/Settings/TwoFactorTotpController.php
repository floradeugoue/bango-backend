<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class TwoFactorTotpController extends Controller
{
    public function __construct(
        protected TotpService $totpService,
        protected BackupCodeService $backupCodeService
    ) {}

    /**
     * Start TOTP setup by generating a fresh Base32 secret and otpauth:// URI.
     */
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $secret = $this->totpService->generateSecret();
        $account = $user->email ?? $user->handle ?? (string) $user->id;
        $uri = $this->totpService->getOtpAuthUri($secret, $account, 'BANGO');

        // Store pending secret for 15 minutes
        Cache::put("2fa_pending_secret:{$user->id}", $secret, now()->addMinutes(15));

        return response()->json([
            'secret' => $secret,
            'uri' => $uri,
            'issuer' => 'BANGO',
            'account' => $account,
        ], 200);
    }

    /**
     * Verify submitted TOTP code and permanently activate 2FA on the user's account.
     */
    public function verify(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $code = trim($request->input('code'));
        $pendingSecret = Cache::get("2fa_pending_secret:{$user->id}");

        if (! $pendingSecret) {
            return response()->json([
                'kind' => 'no-pending-setup',
                'message' => 'Aucune configuration de double authentification en attente.',
            ], 422);
        }

        if (! $this->totpService->verifyCode($pendingSecret, $code, $user->id)) {
            return response()->json([
                'kind' => 'invalid-code',
                'message' => 'Code TOTP invalide.',
            ], 422);
        }

        // Activate TOTP on user with encrypted secret
        $user->two_factor_method = 'app';
        $user->two_factor_secret = Crypt::encryptString($pendingSecret);

        // Generate initial single-use backup codes (8 codes)
        $backupResult = $this->backupCodeService->generateCodes(8);
        $user->backup_codes = $backupResult['hashed'];
        $user->save();

        // Clear pending secret
        Cache::forget("2fa_pending_secret:{$user->id}");

        return response()->json([
            'success' => true,
            'message' => 'Double authentification par application activée avec succès.',
            'two_factor_method' => 'app',
            'backup_codes' => $backupResult['plain'],
            'remaining_backup_codes' => 8,
        ], 200);
    }

    /**
     * Disable 2FA for the authenticated user (requires current password or valid 2FA code).
     */
    public function disable(Request $request): JsonResponse
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

        if ($password && Hash::check($password, $user->password)) {
            $authorized = true;
        } elseif ($code && ($this->totpService->verifyUser($user, $code) || $this->backupCodeService->verifyAndConsume($user, $code))) {
            $authorized = true;
        }

        if (! $authorized) {
            return response()->json([
                'kind' => 'unauthorized_action',
                'message' => 'Mot de passe actuel ou code 2FA valide requis pour désactiver la protection.',
            ], 403);
        }

        $user->two_factor_method = 'off';
        $user->two_factor_secret = null;
        $user->backup_codes = [];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Double authentification désactivée avec succès.',
            'two_factor_method' => 'off',
        ], 200);
    }
}
