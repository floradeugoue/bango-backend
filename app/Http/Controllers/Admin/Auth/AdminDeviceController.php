<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AdminDeviceReportRequest;
use App\Http\Requests\Admin\Auth\AdminDeviceResendRequest;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminChallenge;
use App\Models\Admin\AdminDevice;
use Illuminate\Http\JsonResponse;

class AdminDeviceController extends Controller
{
    /**
     * POST /auth/device/resend
     * Renvoyer un code de vérification du dispositif.
     */
    public function resend(AdminDeviceResendRequest $request): JsonResponse
    {
        $challengeToken = (string) $request->input('challenge_token');
        /** @var AdminChallenge|null $challenge */
        $challenge = AdminChallenge::with('user')->where('token', $challengeToken)->first();

        if (! $challenge || ! $challenge->isValid()) {
            return response()->json([
                'message' => 'Le challenge associé est invalide ou expiré.',
            ], 400);
        }

        // Journalisation de la demande de réémission
        AdminAuditLog::logEvent('auth.device.resend', $challenge->user, $request->ip(), $request->userAgent());

        return response()->json([
            'message' => 'Code de vérification du terminal renvoyé avec succès.',
            'expires_in' => 600,
        ], 200);
    }

    /**
     * POST /auth/device/report
     * Enregistrer un signalement lié à un appareil inconnu ou suspect.
     */
    public function report(AdminDeviceReportRequest $request): JsonResponse
    {
        $reason = (string) $request->input('reason');
        $challengeToken = $request->input('challenge_token');
        $deviceHash = $request->input('device_hash') ?? hash('sha256', ($request->ip() ?? '').($request->userAgent() ?? ''));

        $user = $request->user();

        if (! $user && $challengeToken) {
            $challenge = AdminChallenge::with('user')->where('token', $challengeToken)->first();
            $user = $challenge?->user;

            // Invalidation immédiate du challenge en cas de signalement
            $challenge?->update(['used_at' => now()]);
        }

        if ($user) {
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
        }

        AdminAuditLog::logEvent('auth.device.reported_suspicious', $user, $request->ip(), $request->userAgent(), [
            'reason' => $reason,
            'device_hash' => substr($deviceHash, 0, 16),
        ]);

        return response()->json([
            'message' => 'Signalement de sécurité enregistré. Des mesures de protection ont été appliquées.',
        ], 200);
    }
}
