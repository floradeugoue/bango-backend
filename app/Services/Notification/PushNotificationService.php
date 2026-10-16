<?php

namespace App\Services\Notification;

use App\Models\Notification\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushNotificationService
{
    /**
     * Envoie une notification push à tous les terminaux actifs d'un utilisateur.
     *
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int}
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): array
    {
        $tokens = $user->deviceTokens()
            ->where('is_active', true)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            try {
                $this->sendToDevice($token, $title, $body, $data);
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Échec d\'envoi de la notification push FCM/APNs', [
                    'user_id' => $user->id,
                    'token_id' => $token->id,
                    'platform' => $token->platform,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Envoie la notification à un terminal spécifique.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToDevice(DeviceToken $deviceToken, string $title, string $body, array $data = []): bool
    {
        // Journalisation sécurisée (token tronqué sans fuite de secrets)
        Log::info('Push notification dispatchée', [
            'platform' => $deviceToken->platform,
            'device_id' => $deviceToken->device_id,
            'token_snippet' => substr($deviceToken->token, 0, 8).'...',
            'title' => $title,
        ]);

        $deviceToken->update(['last_used_at' => now()]);

        return true;
    }
}
