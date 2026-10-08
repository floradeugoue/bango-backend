<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use App\Models\ResetPasswordToken;
use Illuminate\Support\Facades\Hash;
use App\Services\Auth\ResetPasswordService;

/**
 * @group Auth
 *
 * API pour le Reset Password classique via lien envoyé par email.
 */
class ResetPasswordController extends Controller
{
    /**
     * Demander un lien de réinitialisation (Reset Password)
     *
     * Permet d'envoyer un email avec un lien contenant un token temporaire pour réinitialiser le mot de passe.
     * Pour des raisons de sécurité, ne confirme jamais si un email existe.
     *
     * @bodyParam email string required L'adresse email de l'utilisateur.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "If your account exists, a reset link has been sent to your email."
     * }
     */
    public function requestLink(Request $request, ResetPasswordService $service): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email']
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            // We use the custom service to avoid duplicating logic
            try {
                $service->sendResetLink($user);
            } catch (\Exception $e) {
                // Return silent success to avoid enumeration
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'If your account exists, a reset link has been sent to your email.'
        ]);
    }

    /**
     * Réinitialiser le mot de passe via lien
     *
     * Valide le token fourni et met à jour le mot de passe.
     *
     * @bodyParam token string required Le token unique fourni dans le lien.
     * @bodyParam password string required Le nouveau mot de passe (min 8 char).
     * @bodyParam password_confirmation string required La confirmation du nouveau mot de passe.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Password reset successfully."
     * }
     * @response 422 {
     *   "message": "Invalid or expired token."
     * }
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed']
        ]);

        $tokenRecord = ResetPasswordToken::where('token', $request->token)->first();

        // Check if token exists, hasn't been used, and isn't expired
        if (!$tokenRecord || 
            $tokenRecord->used_at !== null || 
            $tokenRecord->expires_at->isPast()) {
            return response()->json([
                'message' => 'Invalid or expired token.'
            ], 422);
        }

        $user = $tokenRecord->user;

        // Reset password
        $user->update([
            'password' => Hash::make($request->password)
        ]);

        // Invalidate token
        $tokenRecord->update([
            'used_at' => now()
        ]);

        // Revoke all existing sessions/tokens
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully.'
        ]);
    }
}
