<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminInvitationAcceptRequest;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminInvitation;
use App\Models\Auth\Role;
use App\Models\User;
use App\Services\Admin\AdminPasswordPolicy;
use App\Services\Auth\BackupCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AdminInvitationController extends Controller
{
    public function __construct(
        protected TotpService $totpService,
        protected BackupCodeService $backupCodeService,
    ) {}

    /**
     * GET /auth/invitations/{token}
     * Retourne l'état de l'invitation selon le contrat frontend (Invitation) tout en restant compatible.
     */
    public function show(string $token): JsonResponse
    {
        /** @var AdminInvitation|null $invitation */
        $invitation = AdminInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isValid()) {
            $status = ! $invitation ? 'invalid' : ($invitation->accepted_at ? 'used' : 'expired');

            return response()->json([
                'code' => 'token_invalid',
                'status' => $status,
                'message' => 'Cette invitation est invalide, expirée ou a déjà été utilisée.',
                'is_valid' => false,
            ], 404);
        }

        $parts = explode('@', $invitation->email);
        $name = ucfirst($parts[0] ?? 'Opérateur');

        $roleTitle = match ($invitation->role) {
            'direction' => 'Direction',
            'moderation' => 'Modérateur',
            'support' => 'Support',
            'finance' => 'Finance',
            'kyc' => 'Agent KYC',
            default => ucfirst((string) $invitation->role),
        };

        return response()->json([
            'status' => 'valid',
            'is_valid' => true,
            'email' => $invitation->email,
            'name' => $name,
            'role' => $invitation->role,
            'roleTitle' => $roleTitle,
            'city' => 'Dakar',
            'invitedBy' => $invitation->inviter?->name ?? 'Direction BANGO',
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ], 200);
    }

    /**
     * POST /auth/invitations/{token}/password
     * Étape 1 du contrat : définit le mot de passe initial et initialise le secret TOTP avec l'URI QR code.
     */
    public function password(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'password' => AdminPasswordPolicy::validationRules($request->has('password_confirmation')),
        ], AdminPasswordPolicy::validationMessages());

        /** @var AdminInvitation|null $invitation */
        $invitation = AdminInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isValid()) {
            return response()->json([
                'code' => 'token_expired',
                'message' => 'Cette invitation est invalide, expirée ou a déjà été utilisée.',
                'success' => false,
            ], 404);
        }

        $user = User::where('email', $invitation->email)->first();
        if ($user && ($user->isBlocked() || $user->trashed() || ($user->status ?? null) === 'deleted')) {
            return response()->json([
                'code' => 'forbidden',
                'message' => 'Ce compte opérateur est bloqué ou désactivé.',
                'success' => false,
            ], 403);
        }

        $totpSecret = $this->totpService->generateSecret();
        $qrCodeUri = $this->totpService->getOtpAuthUri($totpSecret, $invitation->email, 'BANGO ADMIN');

        $tokenHash = hash('sha256', $token);
        Cache::put("admin_invitation_enrollment:{$tokenHash}", [
            'invitation_id' => $invitation->id,
            'password' => (string) $request->input('password'),
            'name' => $request->input('name'),
            'totp_secret' => $totpSecret,
            'totp_verified' => false,
        ], now()->addMinutes(30));

        return response()->json([
            'secret' => $totpSecret,
            'otpauthUri' => $qrCodeUri,
            'issuer' => 'BANGO ADMIN',
            'success' => true,
            'totp_secret' => $totpSecret,
            'qr_code_uri' => $qrCodeUri,
            'message' => 'Mot de passe enregistré. Veuillez enregistrer ce secret dans votre application TOTP.',
        ], 200);
    }

    /**
     * POST /auth/invitations/{token}/totp
     * Étape 2 du contrat : valide le premier code TOTP saisi et délivre les 10 codes de secours.
     */
    public function totp(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        /** @var AdminInvitation|null $invitation */
        $invitation = AdminInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isValid()) {
            return response()->json([
                'code' => 'token_invalid',
                'message' => 'Cette invitation est invalide, expirée ou a déjà été utilisée.',
                'success' => false,
            ], 404);
        }

        $tokenHash = hash('sha256', $token);
        $pending = Cache::get("admin_invitation_enrollment:{$tokenHash}");

        if (! $pending || empty($pending['totp_secret'])) {
            return response()->json([
                'code' => 'validation',
                'message' => 'Veuillez d\'abord définir votre mot de passe.',
                'success' => false,
            ], 400);
        }

        $code = (string) $request->input('code');
        if (! $this->totpService->verifyCode($pending['totp_secret'], $code)) {
            return response()->json([
                'code' => 'otp_invalid',
                'message' => 'Code TOTP invalide.',
                'success' => false,
                'meta' => ['attemptsLeft' => 4],
            ], 401);
        }

        $backupCodes = $this->backupCodeService->generateCodes(10);
        $pending['totp_verified'] = true;
        $pending['backup_codes'] = $backupCodes['hashed'];
        Cache::put("admin_invitation_enrollment:{$tokenHash}", $pending, now()->addMinutes(30));

        return response()->json([
            'recoveryKeys' => $backupCodes['plain'],
            'success' => true,
            'backup_codes' => $backupCodes['plain'],
            'message' => 'TOTP validé avec succès. Conservez précieusement ces 10 codes de secours.',
        ], 200);
    }

    /**
     * POST /auth/invitations/{token}/complete
     * Étape 3 du contrat : finalise l'activation du compte opérateur avec 2FA validé et codes de secours.
     */
    public function complete(Request $request, string $token): Response
    {
        return DB::transaction(function () use ($request, $token) {
            $tokenHash = hash('sha256', $token);

            /** @var AdminInvitation|null $invitation */
            $invitation = AdminInvitation::where('token', $tokenHash)
                ->orWhere('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isValid()) {
                return response()->json([
                    'code' => 'token_invalid',
                    'message' => 'Cette invitation est invalide, expirée ou a déjà été utilisée.',
                    'success' => false,
                ], 400);
            }

            $pending = Cache::get("admin_invitation_enrollment:{$tokenHash}");

            if (! $pending || empty($pending['totp_verified']) || empty($pending['password'])) {
                return response()->json([
                    'code' => 'validation',
                    'message' => 'L\'enrôlement 2FA n\'a pas été validé. Veuillez compléter les étapes précédentes.',
                    'success' => false,
                ], 400);
            }

            $role = Role::where('slug', $invitation->role)->first();
            if (! $role) {
                return response()->json([
                    'code' => 'unknown',
                    'message' => 'Le rôle assigné par l\'invitation est introuvable.',
                ], 500);
            }

            $user = User::where('email', $invitation->email)->first();
            if ($user && ($user->isBlocked() || $user->trashed() || ($user->status ?? null) === 'deleted')) {
                return response()->json([
                    'code' => 'forbidden',
                    'message' => 'Ce compte opérateur est bloqué ou désactivé.',
                ], 403);
            }

            $userData = [
                'password' => Hash::make((string) $pending['password']),
                'role_id' => $role->id,
                'email_verified_at' => $user?->email_verified_at ?? now(),
                'status' => 'active',
                'two_factor_method' => 'totp',
                'two_factor_secret' => Crypt::encryptString($pending['totp_secret']),
                'backup_codes' => $pending['backup_codes'],
            ];

            if (! $user) {
                $baseHandle = explode('@', $invitation->email)[0];
                $handle = Str::slug($baseHandle);
                if (User::where('handle', $handle)->exists()) {
                    $handle .= '-'.Str::random(4);
                }

                $user = User::create(array_merge($userData, [
                    'name' => $pending['name'] ?? ucfirst($baseHandle),
                    'email' => $invitation->email,
                    'handle' => $handle,
                ]));
            } else {
                $user->update($userData);
            }

            $invitation->update([
                'accepted_at' => now(),
            ]);

            Cache::forget("admin_invitation_enrollment:{$tokenHash}");

            AdminAuditLog::logEvent('auth.invitation.activated', $user, $request->ip(), $request->userAgent(), [
                'role' => $role->slug,
            ]);

            return response()->noContent(204);
        });
    }

    /**
     * POST /auth/invitations/{token}/accept
     * Active le compte collaborateur (mode atomique unifié).
     */
    public function accept(AdminInvitationAcceptRequest $request, string $token): JsonResponse
    {
        return DB::transaction(function () use ($request, $token) {
            /** @var AdminInvitation|null $invitation */
            $invitation = AdminInvitation::where('token', hash('sha256', $token))
                ->orWhere('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isValid()) {
                return response()->json([
                    'message' => 'Cette invitation est invalide, expirée ou a déjà été utilisée.',
                ], 400);
            }

            // Récupération du rôle assigné (aucun auto-surclassement possible)
            $role = Role::where('slug', $invitation->role)->first();

            if (! $role) {
                return response()->json([
                    'message' => 'Le rôle assigné par l\'invitation est introuvable.',
                ], 500);
            }

            // Création ou mise à jour de l'opérateur
            $user = User::where('email', $invitation->email)->first();

            if ($user && ($user->isBlocked() || $user->trashed() || ($user->status ?? null) === 'deleted')) {
                return response()->json([
                    'message' => 'Ce compte opérateur est bloqué ou désactivé.',
                ], 403);
            }

            $totpSecret = $this->totpService->generateSecret();
            $backupCodes = $this->backupCodeService->generateCodes(10);

            $data = [
                'password' => Hash::make((string) $request->input('password')),
                'role_id' => $role->id,
                'email_verified_at' => $user?->email_verified_at ?? now(),
                'status' => 'active',
                'two_factor_method' => 'totp',
                'two_factor_secret' => Crypt::encryptString($totpSecret),
                'backup_codes' => $backupCodes['hashed'],
            ];

            if (! $user) {
                $baseHandle = explode('@', $invitation->email)[0];
                $handle = Str::slug($baseHandle);
                if (User::where('handle', $handle)->exists()) {
                    $handle .= '-'.Str::random(4);
                }

                $user = User::create(array_merge($data, [
                    'name' => $request->input('name') ?? ucfirst($baseHandle),
                    'email' => $invitation->email,
                    'handle' => $handle,
                ]));
            } else {
                $user->update($data);
            }

            // Consommation atomique de l'invitation (usage unique)
            $invitation->update([
                'accepted_at' => now(),
            ]);

            AdminAuditLog::logEvent('auth.invitation.activated', $user, $request->ip(), $request->userAgent(), [
                'role' => $role->slug,
            ]);

            return response()->json([
                'message' => 'Votre compte opérateur a été activé avec succès. Vous pouvez désormais vous connecter.',
                'operator_id' => $user->id,
                'email' => $user->email,
                'role' => $role->slug,
            ], 200);
        });
    }

    /**
     * GET /admin/invitations
     * Côté direction : lister les invitations émises.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AdminInvitation::with('inviter:id,name,email');

        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        if ($request->input('status') === 'pending') {
            $query->whereNull('accepted_at')->where('expires_at', '>', now());
        } elseif ($request->input('status') === 'accepted') {
            $query->whereNotNull('accepted_at');
        } elseif ($request->input('status') === 'expired') {
            $query->whereNull('accepted_at')->where('expires_at', '<=', now());
        }

        $invitations = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));

        return response()->json($invitations);
    }

    /**
     * POST /admin/invitations
     * Côté direction : inviter un nouveau collaborateur.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', 'exists:roles,slug'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $rawToken = Str::random(40);
        $days = $request->integer('expires_in_days', 3);

        $invitation = AdminInvitation::create([
            'email' => strtolower(trim((string) $request->input('email'))),
            'role' => (string) $request->input('role'),
            'token' => $rawToken,
            'invited_by' => $request->user()?->id,
            'expires_at' => now()->addDays($days),
        ]);

        AdminAuditLog::logEvent('auth.invitation.created', $request->user(), $request->ip(), $request->userAgent(), [
            'email' => $invitation->email,
            'role' => $invitation->role,
        ]);

        return response()->json([
            'message' => 'Invitation créée avec succès.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'token' => $rawToken,
            ],
        ], 201);
    }
}
