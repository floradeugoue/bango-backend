<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminPasswordForgotRequest;
use App\Http\Requests\Admin\Auth\AdminPasswordResetRequest;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminSession;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AdminPasswordResetController extends Controller
{
    public function __construct(
        protected ?TotpService $totpService = null,
    ) {
        $this->totpService = $totpService ?? app(TotpService::class);
    }

    /**
     * POST /auth/password/forgot
     * Demande de réinitialisation sécurisée avec protection anti-énumération.
     */
    public function forgot(AdminPasswordForgotRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $throttleKey = 'admin_forgot_password:'.$request->ip().':'.$email;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'code' => 'rate_limited',
                'message' => "Trop de demandes de réinitialisation. Veuillez patienter {$seconds} secondes.",
                'fields' => [],
                'meta' => ['retryAfterSec' => $seconds],
            ], 429);
        }

        RateLimiter::hit($throttleKey, 900);

        $user = User::where('email', $email)->first();

        // Réponse constante anti-énumération
        $genericResponse = response()->json([
            'sentAt' => Carbon::now()->toIso8601String(),
            'resendAfterSec' => 60,
            'linkValidityMin' => 60,
            'message' => 'Si cette adresse correspond à un compte opérateur actif, les instructions de réinitialisation ont été transmises.',
        ], 200);

        if (! $user || ! $user->isInternalOperator() || $user->isSuspended() || $user->isBlocked() || $user->trashed() || ($user->status ?? null) === 'deleted') {
            return $genericResponse;
        }

        // Génération d'un token cryptographique unique de 64 caractères
        $rawToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $rawToken);

        // Remplacement atomique de l'ancien token pour cet email (stockage du SHA-256 uniquement)
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        DB::table('password_reset_tokens')->insert([
            'email' => $email,
            'token' => $hashedToken,
            'created_at' => Carbon::now(),
        ]);

        AdminAuditLog::logEvent('auth.password.forgot_requested', $user, $request->ip(), $request->userAgent());

        return $genericResponse;
    }

    /**
     * GET /auth/password/reset/{token}
     * Vérifie la validité d'un token de réinitialisation (ResetToken).
     */
    public function showReset(string $token): JsonResponse
    {
        $record = DB::table('password_reset_tokens')
            ->where('token', hash('sha256', $token))
            ->orWhere('token', $token)
            ->first();

        if (! $record) {
            return response()->json([
                'code' => 'token_invalid',
                'status' => 'used',
                'message' => 'Le lien de réinitialisation est invalide ou a déjà été utilisé.',
                'is_valid' => false,
                'fields' => [],
                'meta' => [],
            ], 404);
        }

        $createdAt = Carbon::parse($record->created_at);
        $expiresAt = $createdAt->copy()->addMinutes(60);
        if ($expiresAt->isPast()) {
            DB::table('password_reset_tokens')->where('email', $record->email)->delete();

            return response()->json([
                'code' => 'token_expired',
                'status' => 'expired',
                'email' => $record->email,
                'expiresAt' => $expiresAt->toIso8601String(),
                'message' => 'Ce lien de réinitialisation a expiré.',
                'is_valid' => false,
                'fields' => [],
                'meta' => [],
            ], 410);
        }

        return response()->json([
            'status' => 'valid',
            'email' => $record->email,
            'expiresAt' => $expiresAt->toIso8601String(),
            'is_valid' => true,
            'message' => 'Lien de réinitialisation valide.',
        ], 200);
    }

    /**
     * POST /auth/password/reset/{token}
     * Applique le nouveau mot de passe, vérifie le code 2FA, refuse la réutilisation d'ancien mot de passe et révoque toutes les sessions.
     */
    public function reset(AdminPasswordResetRequest $request, ?string $token = null): Response
    {
        $activeToken = $token ?? $request->input('token');

        if (empty($activeToken)) {
            return response()->json([
                'code' => 'token_invalid',
                'message' => 'Le token de réinitialisation est requis.',
                'fields' => ['token' => 'token_invalid'],
                'meta' => [],
            ], 400);
        }

        return DB::transaction(function () use ($request, $activeToken) {
            $record = DB::table('password_reset_tokens')
                ->where('token', hash('sha256', $activeToken))
                ->orWhere('token', $activeToken)
                ->first();

            if (! $record) {
                return response()->json([
                    'code' => 'token_invalid',
                    'message' => 'Le lien de réinitialisation est invalide ou a déjà été utilisé.',
                    'fields' => [],
                    'meta' => [],
                ], 400);
            }

            $createdAt = Carbon::parse($record->created_at);
            if ($createdAt->addMinutes(60)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $record->email)->delete();

                return response()->json([
                    'code' => 'token_expired',
                    'message' => 'Ce lien de réinitialisation a expiré.',
                    'fields' => [],
                    'meta' => [],
                ], 400);
            }

            $user = User::where('email', $record->email)->first();

            if (! $user) {
                return response()->json([
                    'code' => 'not_found',
                    'message' => 'Opérateur introuvable.',
                    'fields' => [],
                    'meta' => [],
                ], 404);
            }

            // 1. Validation du code 2FA requis par le contrat
            if (! empty($user->two_factor_secret)) {
                $code = (string) $request->input('code');
                if (empty($code) || ! $this->totpService->verifyUser($user, $code)) {
                    return response()->json([
                        'code' => 'otp_invalid',
                        'message' => 'Code 2FA invalide ou requis pour réinitialiser le mot de passe.',
                        'fields' => ['code' => 'otp_invalid'],
                        'meta' => [],
                    ], 401);
                }
            }

            // 2. Refus de réutilisation d'un ancien mot de passe (Point 23)
            $newPassword = (string) $request->input('password');
            if (Hash::check($newPassword, $user->password)) {
                return response()->json([
                    'code' => 'password_reused',
                    'message' => 'Ce mot de passe a déjà été utilisé récemment. Veuillez en choisir un différent.',
                    'fields' => ['password' => 'password_reused'],
                    'meta' => [],
                ], 422);
            }

            // Mise à jour du mot de passe
            $user->update([
                'password' => Hash::make($newPassword),
            ]);

            // Consommation atomique du token (usage unique)
            DB::table('password_reset_tokens')->where('email', $record->email)->delete();

            // Invalidation obligatoire de toutes les sessions actives de l'opérateur pour empêcher tout détournement
            AdminSession::where('user_id', $user->id)->update(['revoked_at' => now()]);

            AdminAuditLog::logEvent('auth.password.reset_success', $user, $request->ip(), $request->userAgent());

            return response()->noContent(204);
        });
    }
}
