<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Enums\OtpChannel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @group Auth
 *
 * API pour la réinitialisation de mot de passe (Forgot Password)
 */
class ForgotPasswordController extends Controller
{
    /**
     * Demander une réinitialisation de mot de passe
     *
     * Permet d'envoyer un code OTP par email ou SMS pour réinitialiser le mot de passe.
     * Pour des raisons de sécurité, cette API ne confirme jamais si un utilisateur existe ou non.
     *
     * @bodyParam channel string required Le canal d'envoi. Exemple: sms, email
     * @bodyParam identifier string required L'identifiant (numéro de téléphone ou email).
     *
     * @response 200 {
     *   "success": true,
     *   "message": "If your account exists, an OTP has been sent."
     * }
     */
    public function forgotPassword(Request $request, OtpService $otpService): JsonResponse
    {
        $this->validateOtpRequest($request);

        $channel = OtpChannel::from($request->channel);
        $identifier = $request->identifier;

        $field = $channel === OtpChannel::Sms ? 'phone' : 'email';
        $user = User::where($field, $identifier)->first();

        // Security: Avoid account enumeration. Always return success even if user doesn't exist.
        if (!$user) {
            return response()->json([
                'success' => true,
                'message' => 'If your account exists, an OTP has been sent.'
            ]);
        }

        $result = $otpService->sendOtp($identifier, $channel, 'forgot_password');

        if (is_array($result)) {
            // We shouldn't leak the exact rate limiting or errors if it allows enumeration,
            // but for rate limits it's usually fine to return 429.
            if ($result['status'] === 429) {
                return response()->json($result['data'], $result['status']);
            }
            // For 503, just fail silently to not leak existence, or we can return 503.
            // Returning 503 is okay as it just says service is down.
            return response()->json($result['data'], $result['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'If your account exists, an OTP has been sent.'
        ]);
    }

    /**
     * Vérifier l'OTP de réinitialisation
     *
     * Vérifie le code OTP reçu. Si valide, retourne un token temporaire pour changer le mot de passe.
     *
     * @bodyParam channel string required Le canal d'envoi. Exemple: sms, email
     * @bodyParam identifier string required L'identifiant (numéro de téléphone ou email).
     * @bodyParam code string required Le code OTP à 6 chiffres.
     *
     * @response 200 {
     *   "success": true,
     *   "reset_token": "a1b2c3d4..."
     * }
     */
    public function verify(Request $request, OtpService $otpService): JsonResponse
    {
        $this->validateOtpRequest($request, true);

        $channel = OtpChannel::from($request->channel);
        $identifier = $request->identifier;

        $result = $otpService->verifyOtp($identifier, $request->code, $channel, 'forgot_password');

        if (is_array($result)) {
            return response()->json($result['data'], $result['status']);
        }

        $token = Str::random(64);
        
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $identifier],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        return response()->json([
            'success' => true,
            'reset_token' => $token
        ]);
    }

    /**
     * Réinitialiser le mot de passe
     *
     * Permet de définir un nouveau mot de passe à l'aide du token temporaire.
     *
     * @bodyParam channel string required Le canal d'envoi. Exemple: sms, email
     * @bodyParam identifier string required L'identifiant (numéro de téléphone ou email).
     * @bodyParam token string required Le token obtenu après vérification de l'OTP.
     * @bodyParam password string required Le nouveau mot de passe.
     * @bodyParam password_confirmation string required La confirmation du mot de passe.
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Password reset successfully."
     * }
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'channel' => ['required', Rule::enum(OtpChannel::class)],
            'identifier' => ['required', 'string'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed']
        ]);

        $channel = OtpChannel::from($request->channel);
        $identifier = $request->identifier;
        
        $field = $channel === OtpChannel::Sms ? 'phone' : 'email';
        $user = User::where($field, $identifier)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid token or identifier.'
            ], 422);
        }

        $record = DB::table('password_reset_tokens')->where('email', $identifier)->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json([
                'message' => 'Invalid or expired reset token.'
            ], 422);
        }

        // Expire token after 60 minutes
        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $identifier)->delete();
            return response()->json([
                'message' => 'Token has expired.'
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        DB::table('password_reset_tokens')->where('email', $identifier)->delete();

        // Revoke all existing sessions/tokens
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully.'
        ]);
    }

    private function validateOtpRequest(Request $request, bool $withCode = false): void
    {
        $rules = [
            'channel' => ['required', Rule::enum(OtpChannel::class)],
            'identifier' => ['required', 'string']
        ];

        if ($request->channel === OtpChannel::Email->value) {
            $rules['identifier'][] = 'email';
        }

        if ($withCode) {
            $rules['code'] = ['required', 'string', 'size:6'];
        }

        $request->validate($rules);
    }
}
