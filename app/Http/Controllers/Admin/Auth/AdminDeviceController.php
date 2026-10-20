<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminDeviceReportRequest;
use App\Http\Requests\Admin\Auth\AdminDeviceResendRequest;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminChallenge;
use App\Models\Admin\AdminDevice;
use App\Models\Admin\AdminSession;
use App\Notifications\Auth\DeviceVerificationCodeNotification;
use App\Notifications\Auth\UnknownDeviceReportedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminDeviceController extends Controller
{
    /**
     * POST /auth/device/resend
     * Renvoyer un code de vérification du dispositif.
     */
    public function resend(AdminDeviceResendRequest $request): JsonResponse
    {
        $challengeToken = (string) ($request->input('challengeId') ?? $request->input('challenge_token'));
        /** @var AdminChallenge|null $challenge */
        $challenge = AdminChallenge::with('user')->where('token', $challengeToken)->first();

        if (! $challenge || ! $challenge->isValid()) {
            return response()->json([
                'code' => 'otp_expired',
                'message' => 'Le challenge associé est invalide ou expiré.',
                'fields' => [],
                'meta' => [],
            ], 400);
        }

        $user = $challenge->user;
        if (! $user) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Utilisateur introuvable pour ce challenge.',
                'fields' => [],
                'meta' => [],
            ], 404);
        }

        // Point 22 : Génération et transmission effective du code de vérification
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("admin_device_verification_code:{$challenge->token}", $code, now()->addMinutes(10));

        // Envoi de la notification réelle avec le code
        $user->notify(new DeviceVerificationCodeNotification($code, 600));

        // Point 21 : Journalisation d'audit chaînée de la réémission
        AdminAuditLog::logChainedEvent(
            event: 'auth.device.code_resent',
            operator: $user,
            targetType: 'AdminChallenge',
            targetId: (string) $challenge->id,
            targetLabel: "Renvoi du code de confirmation d'appareil pour {$user->email}",
            context: [
                'challenge_token' => substr($challenge->token, 0, 16).'...',
                'expires_in' => 600,
            ],
            ip: $request->ip(),
            userAgent: $request->userAgent()
        );

        return response()->json([
            'linkSentAt' => now()->toIso8601String(),
            'message' => 'Code de vérification du terminal renvoyé avec succès.',
            'expires_in' => 600,
        ], 200);
    }

    /**
     * POST /auth/device/report
     * Enregistrer un signalement lié à un appareil inconnu ou suspect et verrouiller le compte.
     */
    public function report(AdminDeviceReportRequest $request): JsonResponse
    {
        $reason = (string) ($request->input('reason') ?? 'Appareil inconnu signalé');
        $challengeToken = $request->input('challengeId') ?? $request->input('challenge_token');
        $deviceHash = $request->input('device_hash') ?? hash('sha256', ($request->ip() ?? '').($request->userAgent() ?? ''));

        $user = $request->user();

        if (! $user && $challengeToken) {
            $challenge = AdminChallenge::with('user')->where('token', $challengeToken)->first();
            $user = $challenge?->user;

            // Invalidation immédiate du challenge en cas de signalement
            $challenge?->update([
                'used_at' => now(),
                'expires_at' => now(),
            ]);
        }

        if ($user) {
            // Point 22 : Le signalement d'un appareil inconnu déclenche le verrouillage du compte
            $lockedUntil = now()->addHours(24);
            $user->update([
                'locked_until' => $lockedUntil,
            ]);

            // Invalidation de tous les challenges en cours
            AdminChallenge::where('user_id', $user->id)->update([
                'expires_at' => now(),
                'used_at' => now(),
            ]);

            // Révocation de toutes les sessions administratives actives
            AdminSession::where('user_id', $user->id)->whereNull('revoked_at')->update([
                'revoked_at' => now(),
            ]);

            // Révocation de tous les tokens d'API
            $user->tokens()->delete();

            // Enregistrement de l'appareil suspect
            AdminDevice::updateOrCreate(
                ['user_id' => $user->id, 'device_hash' => $deviceHash],
                [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'is_trusted' => false,
                    'reported_suspicious_at' => now(),
                    'suspicion_reason' => $reason,
                    'last_seen_at' => now(),
                ]
            );

            // Notification d'alerte sécurité
            $user->notify(new UnknownDeviceReportedNotification($reason, $deviceHash));

            // Point 21 : Journalisation d'audit chaînée du signalement et verrouillage
            AdminAuditLog::logChainedEvent(
                event: 'auth.device.account_locked_suspicious',
                operator: $user,
                targetType: 'User',
                targetId: (string) $user->id,
                targetLabel: "Verrouillage de sécurité du compte utilisateur #{$user->id} suite au signalement d'appareil suspect",
                context: [
                    'reason' => $reason,
                    'device_hash' => substr($deviceHash, 0, 16),
                    'locked_until' => $lockedUntil->toIso8601String(),
                ],
                ip: $request->ip(),
                userAgent: $request->userAgent()
            );
        }

        $incidentRef = 'INC-'.strtoupper(bin2hex(random_bytes(3)));
        $email = $user?->email ?? 'operator@bango.app';
        $parts = explode('@', $email);
        $notifiedTo = Str::mask($parts[0], '*', 2, -1).'@'.($parts[1] ?? 'bango.app');

        return response()->json([
            'email' => $email,
            'device' => $request->userAgent() ?? 'Appareil non reconnu',
            'incidentRef' => $incidentRef,
            'lockedAt' => now()->toIso8601String(),
            'failedAttempts' => 5,
            'maxAttempts' => 5,
            'notifiedTo' => $notifiedTo,
            'message' => 'Signalement de sécurité enregistré. Le compte a été verrouillé par précaution.',
            'account_locked' => true,
            'locked_until' => $user?->locked_until?->toIso8601String(),
        ], 200);
    }
}
