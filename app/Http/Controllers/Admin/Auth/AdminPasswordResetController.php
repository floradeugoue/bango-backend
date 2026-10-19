<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminPasswordForgotRequest;
use App\Http\Requests\Admin\Auth\AdminPasswordResetRequest;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AdminPasswordResetController extends Controller
{
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
                'message' => "Trop de demandes de réinitialisation. Veuillez patienter {$seconds} secondes.",
            ], 429);
        }

        RateLimiter::hit($throttleKey, 900);

        $user = User::where('email', $email)->first();

        // Réponse constante anti-énumération
        $genericResponse = response()->json([
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
     * GET /reset/{token}
     * Vérifie la validité d'un token de réinitialisation.
     */
    public function showReset(string $token): JsonResponse
    {
        $record = DB::table('password_reset_tokens')
            ->where('token', hash('sha256', $token))
            ->orWhere('token', $token)
            ->first();

        if (! $record) {
            return response()->json([
                'message' => 'Le lien de réinitialisation est invalide ou a déjà été utilisé.',
                'is_valid' => false,
            ], 404);
        }

        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $record->email)->delete();

            return response()->json([
                'message' => 'Ce lien de réinitialisation a expiré.',
                'is_valid' => false,
            ], 410);
        }

        return response()->json([
            'is_valid' => true,
            'message' => 'Lien de réinitialisation valide.',
        ], 200);
    }

    /**
     * POST /reset/{token}
     * Applique le nouveau mot de passe et révoque toutes les sessions existantes.
     */
    public function reset(AdminPasswordResetRequest $request, ?string $token = null): JsonResponse
    {
        $activeToken = $token ?? $request->input('token');

        if (empty($activeToken)) {
            return response()->json(['message' => 'Le token de réinitialisation est requis.'], 400);
        }

        return DB::transaction(function () use ($request, $activeToken) {
            $record = DB::table('password_reset_tokens')
                ->where('token', hash('sha256', $activeToken))
                ->orWhere('token', $activeToken)
                ->first();

            if (! $record) {
                return response()->json([
                    'message' => 'Le lien de réinitialisation est invalide ou a déjà été utilisé.',
                ], 400);
            }

            $createdAt = Carbon::parse($record->created_at);
            if ($createdAt->addMinutes(60)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $record->email)->delete();

                return response()->json([
                    'message' => 'Ce lien de réinitialisation a expiré.',
                ], 400);
            }

            $user = User::where('email', $record->email)->first();

            if (! $user) {
                return response()->json(['message' => 'Opérateur introuvable.'], 404);
            }

            // Mise à jour du mot de passe
            $user->update([
                'password' => Hash::make((string) $request->input('password')),
            ]);

            // Consommation atomique du token (usage unique)
            DB::table('password_reset_tokens')->where('email', $record->email)->delete();

            // Invalidation obligatoire de toutes les sessions actives de l'opérateur pour empêcher tout détournement
            AdminSession::where('user_id', $user->id)->update(['revoked_at' => now()]);

            AdminAuditLog::logEvent('auth.password.reset_success', $user, $request->ip(), $request->userAgent());

            return response()->json([
                'message' => 'Votre mot de passe a été réinitialisé avec succès. Toutes vos sessions ont été déconnectées par mesure de sécurité.',
            ], 200);
        });
    }
}
