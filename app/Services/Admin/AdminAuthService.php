<?php

namespace App\Services\Admin;

use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminChallenge;
use App\Models\Admin\AdminDevice;
use App\Models\Admin\AdminSession;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AdminAuthService
{
    public function __construct(
        protected TotpService $totpService,
        protected BackupCodeService $backupCodeService,
    ) {}

    /**
     * Fournit le contexte de sécurité pour initialiser le parcours de connexion.
     */
    public function getSecurityContext(Request $request): array
    {
        $sessionId = $request->cookie('bango_admin_session') ?? $request->bearerToken();
        $hasActiveSession = false;
        $operatorInfo = null;

        if ($sessionId) {
            $session = AdminSession::with('user.role')->find(hash('sha256', $sessionId))
                ?? AdminSession::with('user.role')->find($sessionId);
            if ($session && ! $session->isRevoked() && ! $session->isExpired()) {
                $hasActiveSession = true;
                $operatorInfo = [
                    'id' => $session->user->id,
                    'name' => $session->user->name,
                    'email' => $session->user->email,
                    'role' => $session->user->role?->slug,
                ];
            }
        }

        $deviceHash = hash('sha256', ($request->ip() ?? '').($request->userAgent() ?? ''));

        return [
            'session' => [
                'has_active_session' => $hasActiveSession,
                'operator' => $operatorInfo,
            ],
            'terminal' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent() ? substr($request->userAgent(), 0, 200) : null,
                'is_secure' => $request->isSecure(),
                'device_fingerprint' => substr($deviceHash, 0, 16),
            ],
            'policy' => [
                'two_factor_required' => true,
                'sliding_timeout_minutes' => 30,
                'password_policy' => AdminPasswordPolicy::getPolicy(),
            ],
            'csrf' => [
                'token_present' => ! empty($request->cookie('bango_admin_csrf')),
            ],
        ];
    }

    /**
     * Traite l'authentification initiale et génère le challenge 2FA.
     */
    public function login(string $email, string $password, Request $request): JsonResponse
    {
        $throttleKey = 'admin_login:'.$request->ip().':'.strtolower($email);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            AdminAuditLog::logEvent('auth.login.throttled', null, $request->ip(), $request->userAgent(), [
                'email' => $email,
                'retry_after' => $seconds,
            ]);

            return response()->json([
                'message' => "Trop de tentatives de connexion. Veuillez réessayer dans {$seconds} secondes.",
                'code' => 'RATE_LIMITED',
            ], 429);
        }

        $user = User::withTrashed()->where('email', strtolower(trim($email)))->first();

        // Protection anti-énumération : vérification timing-safe du mot de passe même si utilisateur inexistant
        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, 900); // 15 minutes window

            AdminAuditLog::logEvent('auth.login.failed', $user, $request->ip(), $request->userAgent(), [
                'email' => $email,
                'reason' => 'invalid_credentials',
            ]);

            return response()->json([
                'code' => 'invalid_credentials',
                'message' => 'Identifiants invalides ou compte inactif.',
                'fields' => ['email' => 'invalid_credentials'],
                'meta' => [
                    'attemptsLeft' => max(0, 5 - RateLimiter::attempts($throttleKey)),
                    'lastFailureAt' => now()->toIso8601String(),
                ],
            ], 401);
        }

        // Vérification de l'état du compte
        if ($user->isLocked()) {
            AdminAuditLog::logEvent('auth.login.locked', $user, $request->ip(), $request->userAgent());

            return response()->json([
                'code' => 'account_locked',
                'message' => 'Ce compte est temporairement verrouillé suite à plusieurs tentatives 2FA infructueuses.',
                'fields' => [],
                'meta' => [
                    'email' => $user->email,
                    'lockedUntil' => $user->locked_until?->toIso8601String(),
                    'maxAttempts' => 5,
                ],
                'locked_until' => $user->locked_until?->toIso8601String(),
            ], 423);
        }

        if ($user->trashed() || ($user->status ?? null) === 'deleted') {
            return response()->json([
                'code' => 'ACCOUNT_DELETED',
                'message' => 'Ce compte opérateur a été désactivé.',
                'fields' => [],
                'meta' => [],
            ], 403);
        }

        if ($user->isBlocked()) {
            AdminAuditLog::logEvent('auth.login.blocked_account', $user, $request->ip(), $request->userAgent());

            return response()->json([
                'code' => 'ACCOUNT_BLOCKED',
                'message' => 'Ce compte opérateur est bloqué.',
                'fields' => [],
                'meta' => [],
            ], 403);
        }

        if ($user->isSuspended()) {
            AdminAuditLog::logEvent('auth.login.blocked_suspended', $user, $request->ip(), $request->userAgent());

            return response()->json([
                'code' => 'ACCOUNT_SUSPENDED',
                'message' => 'Ce compte opérateur est temporairement suspendu.',
                'fields' => [],
                'meta' => [
                    'suspension_reason' => $user->suspension_reason,
                ],
                'suspension_reason' => $user->suspension_reason,
            ], 403);
        }

        if (! $user->isInternalOperator()) {
            AdminAuditLog::logEvent('auth.login.unauthorized_role', $user, $request->ip(), $request->userAgent());

            return response()->json([
                'code' => 'forbidden',
                'message' => 'Accès refusé. Privilèges opérateur du back-office requis.',
                'fields' => [],
                'meta' => [],
            ], 403);
        }

        // Réinitialisation du throttle après vérification valide des identifiants
        RateLimiter::clear($throttleKey);

        // Tous les opérateurs internes requièrent le 2FA
        // Génération d'un challenge aléatoire, expirant, lié à l'opérateur
        $challengeToken = bin2hex(random_bytes(32));

        AdminChallenge::create([
            'user_id' => $user->id,
            'token' => $challengeToken,
            'type' => 'totp',
            'attempts' => 0,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'expires_at' => now()->addMinutes(10),
        ]);

        AdminAuditLog::logEvent('auth.challenge.issued', $user, $request->ip(), $request->userAgent(), [
            'type' => 'totp',
        ]);

        return response()->json([
            'next' => 'otp',
            'challengeId' => $challengeToken,
            'challenge_token' => $challengeToken,
            'operator' => [
                'email' => $user->email,
                'title' => $user->role?->name ?? 'Opérateur',
                'city' => $user->city ?? 'Dakar',
                'avatarUrl' => $user->avatar_url,
            ],
            'challenge_required' => true,
            'challenge_type' => 'totp',
            'expires_in' => 600,
            'message' => 'Identifiants validés. Veuillez saisir votre code d\'authentification à deux facteurs.',
        ], 200);
    }

    /**
     * Valide le challenge 2FA et établit la session avec cookies HttpOnly & CSRF.
     */
    public function verify2fa(string $challengeToken, string $code, Request $request): JsonResponse
    {
        /** @var AdminChallenge|null $challenge */
        $challenge = AdminChallenge::with('user.role')->where('token', $challengeToken)->first();

        if ($challenge && $challenge->user && $challenge->user->isLocked()) {
            return response()->json([
                'code' => 'account_locked',
                'message' => 'Compte verrouillé suite à 5 tentatives 2FA infructueuses.',
                'fields' => [],
                'meta' => [
                    'email' => $challenge->user->email,
                    'lockedUntil' => $challenge->user->locked_until?->toIso8601String(),
                    'maxAttempts' => 5,
                ],
                'locked_until' => $challenge->user->locked_until?->toIso8601String(),
            ], 423);
        }

        if (! $challenge || ! $challenge->isValid()) {
            return response()->json([
                'code' => 'otp_expired',
                'message' => 'Le challenge 2FA est invalide, expiré ou a dépassé le nombre maximal de tentatives.',
                'fields' => ['code' => 'otp_expired'],
                'meta' => [],
            ], 401);
        }

        $user = $challenge->user;

        if ($user->isBlocked() || $user->trashed() || ($user->status ?? null) === 'deleted') {
            return response()->json([
                'code' => 'forbidden',
                'message' => 'Ce compte opérateur n\'est plus autorisé à se connecter.',
                'fields' => [],
                'meta' => [],
            ], 403);
        }

        // Vérification du code TOTP
        $isValid = $this->verifyTotp($user, $code);

        if (! $isValid) {
            return $this->handleFailed2faAttempt($user, $challenge, $request, 'auth.2fa.failed');
        }

        // Invalidation atomique du challenge et réinitialisation du compteur de verrouillage
        $challenge->markUsed();
        Cache::forget("admin_2fa_failed_attempts:{$user->id}");

        // Établissement de la session administrative sécurisée
        return $this->establishSession($user, $request);
    }

    /**
     * Récupération 2FA via code de secours à usage unique.
     */
    public function verifyRecovery(string $challengeToken, string $recoveryCode, Request $request): JsonResponse
    {
        /** @var AdminChallenge|null $challenge */
        $challenge = AdminChallenge::with('user.role')->where('token', $challengeToken)->first();

        if ($challenge && $challenge->user && $challenge->user->isLocked()) {
            return response()->json([
                'code' => 'account_locked',
                'message' => 'Compte verrouillé suite à 5 tentatives 2FA infructueuses.',
                'fields' => [],
                'meta' => [
                    'email' => $challenge->user->email,
                    'lockedUntil' => $challenge->user->locked_until?->toIso8601String(),
                    'maxAttempts' => 5,
                ],
                'locked_until' => $challenge->user->locked_until?->toIso8601String(),
            ], 423);
        }

        if (! $challenge || ! $challenge->isValid()) {
            return response()->json([
                'code' => 'recovery_key_invalid',
                'message' => 'Le challenge 2FA est invalide ou expiré.',
                'fields' => ['key' => 'recovery_key_invalid'],
                'meta' => [],
            ], 401);
        }

        $user = $challenge->user;

        if ($user->isBlocked() || $user->trashed() || ($user->status ?? null) === 'deleted') {
            return response()->json([
                'code' => 'forbidden',
                'message' => 'Ce compte opérateur n\'est plus autorisé à se connecter.',
                'fields' => [],
                'meta' => [],
            ], 403);
        }

        // Consommation atomique protégée contre les accès concurrents
        $consumed = $this->backupCodeService->verifyAndConsume($user, $recoveryCode);

        if (! $consumed) {
            AdminAuditLog::logEvent('auth.recovery.failed', $user, $request->ip(), $request->userAgent());

            return response()->json([
                'code' => 'recovery_key_invalid',
                'message' => 'Clé de secours invalide ou déjà utilisée.',
                'fields' => ['key' => 'recovery_key_invalid'],
                'meta' => [],
            ], 401);
        }

        // Invalidation atomique du challenge et réinitialisation du compteur de verrouillage
        $challenge->markUsed();
        Cache::forget("admin_2fa_failed_attempts:{$user->id}");

        AdminAuditLog::logEvent('auth.recovery.success', $user, $request->ip(), $request->userAgent());

        return $this->establishSession($user, $request, isRecovery: true);
    }

    /**
     * Gestion centralisée des échecs 2FA avec verrouillage par compte après 5 échecs toutes sessions confondues.
     */
    protected function handleFailed2faAttempt(User $user, AdminChallenge $challenge, Request $request, string $eventType): JsonResponse
    {
        $challengeAttempts = $challenge->incrementAttempts();
        $cacheKey = "admin_2fa_failed_attempts:{$user->id}";
        $totalFailed = Cache::increment($cacheKey);
        if ($totalFailed === 1) {
            Cache::put($cacheKey, 1, now()->addHours(1));
        }

        if ($totalFailed >= 5) {
            $user->update([
                'locked_until' => now()->addMinutes(30),
            ]);
            Cache::forget($cacheKey);
            AdminChallenge::where('user_id', $user->id)->update(['expires_at' => now()]);

            AdminAuditLog::logEvent('auth.2fa.account_locked', $user, $request->ip(), $request->userAgent(), [
                'failed_attempts' => $totalFailed,
                'locked_until' => $user->locked_until?->toIso8601String(),
            ]);

            return response()->json([
                'code' => 'account_locked',
                'message' => 'Compte verrouillé suite à 5 tentatives 2FA infructueuses.',
                'fields' => [],
                'meta' => [
                    'email' => $user->email,
                    'device' => $request->userAgent() ?? 'Inconnu',
                    'incidentRef' => 'INC-'.strtoupper(bin2hex(random_bytes(3))),
                    'lockedAt' => now()->toIso8601String(),
                    'failedAttempts' => $totalFailed,
                    'maxAttempts' => 5,
                    'notifiedTo' => $user->email,
                    'lockedUntil' => $user->locked_until?->toIso8601String(),
                ],
                'locked_until' => $user->locked_until?->toIso8601String(),
            ], 423);
        }

        $remaining = max(0, 5 - $totalFailed);

        AdminAuditLog::logEvent($eventType, $user, $request->ip(), $request->userAgent(), [
            'challenge_attempts' => $challengeAttempts,
            'total_failed' => $totalFailed,
            'remaining' => $remaining,
        ]);

        return response()->json([
            'code' => 'otp_invalid',
            'message' => "Code de vérification 2FA invalide. Tentatives restantes : {$remaining}.",
            'fields' => ['code' => 'otp_invalid'],
            'meta' => [
                'attemptsLeft' => $remaining,
                'failedAt' => now()->toIso8601String(),
            ],
            'remaining_attempts' => $remaining,
        ], 401);
    }

    /**
     * Établit la session serveur, régénère l'identifiant pour contrer la fixation, et émet les cookies.
     */
    public function establishSession(User $user, Request $request, bool $isRecovery = false): JsonResponse
    {
        // 1. Génération cryptographique aléatoire de l'ID de session et du jeton CSRF
        $rawSessionId = bin2hex(random_bytes(32));
        $hashedSessionId = hash('sha256', $rawSessionId);
        $csrfToken = bin2hex(random_bytes(32));

        // 2. Création de la session en base de données avec le hash SHA-256 (aucun secret en clair en base)
        $session = AdminSession::create([
            'id' => $hashedSessionId,
            'user_id' => $user->id,
            'csrf_token' => $csrfToken,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'two_factor_verified_at' => now(),
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(12), // Expiration maximale absolue
        ]);

        // 3. Enregistrement / mise à jour de l'appareil
        $deviceHash = hash('sha256', ($request->ip() ?? '').($request->userAgent() ?? ''));
        AdminDevice::updateOrCreate(
            ['user_id' => $user->id, 'device_hash' => $deviceHash],
            [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_seen_at' => now(),
                'is_trusted' => true,
            ]
        );

        // 4. Configuration des cookies (Point 27 : Secure systématiquement, y compris hors production, SameSite=Strict)
        $sessionCookie = cookie(
            name: 'bango_admin_session',
            value: $rawSessionId,
            minutes: 720, // 12h max
            path: '/',
            domain: config('session.domain'),
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: 'Strict'
        );

        $csrfCookie = cookie(
            name: 'bango_admin_csrf',
            value: $csrfToken,
            minutes: 720,
            path: '/',
            domain: config('session.domain'),
            secure: true,
            httpOnly: false,
            raw: false,
            sameSite: 'Strict'
        );

        // 5. Journalisation de la connexion réussie
        AdminAuditLog::logEvent('auth.login.success', $user, $request->ip(), $request->userAgent(), [
            'is_recovery' => $isRecovery,
        ]);

        $permissions = $user->getEffectivePermissions();

        return response()->json([
            'operator' => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->slug ?? 'direction',
                'title' => $user->role?->name ?? 'Opérateur',
                'city' => $user->city ?? 'Dakar',
                'avatarUrl' => $user->avatar_url,
                'status' => $user->status ?? 'active',
                'permissions' => $permissions,
            ],
            'permissions' => $permissions,
            'expiresAt' => $session->expires_at->toIso8601String(),
            'idleTimeoutMin' => 30,
            'firstLogin' => empty($user->last_login_at),
            'message' => 'Connexion administrative réussie.',
            'session' => [
                'expires_in_seconds' => 1800, // 30 minutes inactivité glissante
                'csrf_token' => $csrfToken,
                'expiresAt' => $session->expires_at->toIso8601String(),
            ],
        ], 200)->withCookie($sessionCookie)->withCookie($csrfCookie);
    }

    /**
     * Maintien de session (prolongation glissante de 30 minutes).
     */
    public function touchSession(Request $request): JsonResponse
    {
        /** @var AdminSession|null $session */
        $session = $request->attributes->get('admin_session');

        if (! $session) {
            return response()->json(['message' => 'Aucune session active à prolonger.'], 401);
        }

        $session->touchActivity();

        AdminAuditLog::logEvent('auth.session.touch', $session->user, $request->ip(), $request->userAgent());

        return response()->json([
            'expiresAt' => $session->expires_at->toIso8601String(),
            'expires_in_seconds' => 1800,
            'last_activity_at' => $session->last_activity_at->toIso8601String(),
            'message' => 'Session prolongée avec succès.',
        ], 200);
    }

    /**
     * Déconnexion sécurisée : invalidation de session, révocation et expiration des cookies.
     * Point 27 : Répond avec HTTP 204.
     */
    public function logout(Request $request): Response|JsonResponse
    {
        /** @var AdminSession|null $session */
        $session = $request->attributes->get('admin_session');

        if (! $session) {
            $sessionId = $request->cookie('bango_admin_session') ?? $request->bearerToken();
            if ($sessionId) {
                $session = AdminSession::find(hash('sha256', $sessionId)) ?? AdminSession::find($sessionId);
            }
        }

        if ($session) {
            $session->revoke();
            AdminAuditLog::logEvent('auth.logout', $session->user, $request->ip(), $request->userAgent());
        }

        // Expiration immédiate des cookies avec SameSite=Strict et Secure
        $forgetSession = cookie()->forget('bango_admin_session', '/', config('session.domain'));
        $forgetCsrf = cookie()->forget('bango_admin_csrf', '/', config('session.domain'));

        return response()->noContent(204)
            ->withCookie($forgetSession)
            ->withCookie($forgetCsrf);
    }

    /**
     * Vérification cryptographique TOTP ou code de secours.
     */
    protected function verifyTotp(User $user, string $code): bool
    {
        // 1. Si l'utilisateur possède un secret TOTP configuré
        if (! empty($user->two_factor_secret)) {
            return $this->totpService->verifyUser($user, $code);
        }

        // 2. Vérification avec code de secours si configuré
        if (! empty($user->backup_codes) && is_array($user->backup_codes)) {
            return $this->backupCodeService->verifyAndConsume($user, $code);
        }

        return false;
    }
}
