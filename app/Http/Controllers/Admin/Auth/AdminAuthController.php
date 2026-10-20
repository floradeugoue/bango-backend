<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\Admin2FaRecoveryRequest;
use App\Http\Requests\Admin\Auth\Admin2FaVerifyRequest;
use App\Http\Requests\Admin\Auth\AdminLoginRequest;
use App\Models\Admin\AdminChallenge;
use App\Services\Admin\AdminAuthService;
use App\Services\Admin\AdminPasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthController extends Controller
{
    public function __construct(
        protected AdminAuthService $authService,
    ) {}

    /**
     * GET /auth/context
     * Fournit les informations nécessaires à l'initialisation du parcours d'authentification.
     */
    public function context(Request $request): JsonResponse
    {
        $context = $this->authService->getSecurityContext($request);

        return response()->json($context, 200);
    }

    /**
     * POST /auth/login
     * Valide les identifiants initiaux et émet un challenge 2FA expirant.
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        return $this->authService->login(
            email: (string) $request->input('email'),
            password: (string) $request->input('password'),
            request: $request
        );
    }

    /**
     * POST /auth/2fa/verify
     * Valide le second facteur, régénère la session et émet les cookies HttpOnly et CSRF.
     */
    public function verify2fa(Admin2FaVerifyRequest $request): JsonResponse
    {
        $challengeToken = (string) ($request->input('challengeId') ?? $request->input('challenge_token'));

        return $this->authService->verify2fa(
            challengeToken: $challengeToken,
            code: (string) $request->input('code'),
            request: $request
        );
    }

    /**
     * GET /auth/2fa/recovery
     * Fournit les informations non sensibles sur le parcours de récupération (RecoveryKeysInfo).
     */
    public function recoveryInfo(Request $request): JsonResponse
    {
        $challengeToken = $request->query('challenge_id')
            ?? $request->query('challengeId')
            ?? $request->query('challenge_token');

        $valid = 0;
        $used = 0;
        $notifiedTo = '';

        if ($challengeToken) {
            $challenge = AdminChallenge::with('user')->where('token', $challengeToken)->first();
            if ($challenge && $challenge->user) {
                $user = $challenge->user;
                $backupCodes = $user->backup_codes ?? [];
                $valid = is_array($backupCodes) ? count($backupCodes) : 0;
                $used = max(0, 10 - $valid);
                $parts = explode('@', $user->email);
                $notifiedTo = Str::mask($parts[0], '*', 2, -1).'@'.($parts[1] ?? 'bango.app');
            }
        }

        return response()->json([
            'valid' => $valid,
            'used' => $used,
            'notifiedTo' => $notifiedTo,
            'message' => 'Saisissez l\'un de vos codes de secours à usage unique générés lors de la configuration de votre compte.',
            'format' => 'XXXX-XXXX (8 caractères)',
            'anti_replay' => 'Chaque code est invalidé de manière atomique et définitive après usage.',
        ], 200);
    }

    /**
     * POST /auth/2fa/recovery
     * Consomme un code de secours et établit la session administrative.
     */
    public function verifyRecovery(Admin2FaRecoveryRequest $request): JsonResponse
    {
        $challengeToken = (string) ($request->input('challengeId') ?? $request->input('challenge_token'));
        $recoveryCode = (string) ($request->input('key') ?? $request->input('recovery_code'));

        return $this->authService->verifyRecovery(
            challengeToken: $challengeToken,
            recoveryCode: $recoveryCode,
            request: $request
        );
    }

    /**
     * GET /auth/session
     * Retourne la session de l'opérateur connecté et ses permissions effectives.
     */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();
        $adminSession = $request->attributes->get('admin_session');

        if (! $user || ! $adminSession) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        return response()->json([
            'operator' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->slug,
                'status' => $user->status ?? 'active',
                'permissions' => $user->getEffectivePermissions(),
            ],
            'session' => [
                'id' => substr($adminSession->id, 0, 12).'...', // Empreinte tronquée non rejouable
                'last_activity_at' => $adminSession->last_activity_at->toIso8601String(),
                'expires_at' => $adminSession->expires_at->toIso8601String(),
                'sliding_timeout_minutes' => 30,
            ],
            'workspace' => [
                'environment' => config('app.env'),
                'locale' => app()->getLocale(),
            ],
        ], 200);
    }

    /**
     * POST /auth/session/touch
     * Prolonge la session glissante de 30 minutes.
     */
    public function touch(Request $request): JsonResponse
    {
        return $this->authService->touchSession($request);
    }

    /**
     * POST /auth/logout
     * Invalide la session côté serveur et expire les cookies.
     */
    public function logout(Request $request): Response
    {
        return $this->authService->logout($request);
    }

    /**
     * GET /auth/password/policy
     * Expose la politique de mot de passe centralisée appliquée par le backend.
     */
    public function passwordPolicy(): JsonResponse
    {
        return response()->json(AdminPasswordPolicy::getPolicy(), 200);
    }
}
