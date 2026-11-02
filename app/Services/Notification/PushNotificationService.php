<?php

namespace App\Services\Notification;

use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Models\Notification\DeviceToken;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushNotificationService
{
    /**
     * URL officielle de l'API Expo Push Notifications.
     */
    public const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * Taille maximale d'un lot supportée par l'API Expo Push.
     */
    public const CHUNK_SIZE = 100;

    /**
     * Envoie une notification push à tous les terminaux actifs d'un utilisateur,
     * en respectant ses préférences /notifications/settings.
     *
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int}
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): array
    {
        // 1. Vérification des préférences de notification de l'utilisateur
        if (! $this->isPushAllowedForUser($user, $data['type'] ?? null)) {
            Log::info('Push notification ignorée : désactivée par les préférences utilisateur', [
                'user_id' => $user->id,
                'type' => $data['type'] ?? 'unknown',
            ]);

            return ['sent' => 0, 'failed' => 0];
        }

        $tokens = $user->deviceTokens()
            ->where('is_active', true)
            ->get();

        if ($tokens->isEmpty()) {
            return ['sent' => 0, 'failed' => 0];
        }

        // Calcul du badge : nombre de notifications non lues
        $badge = $user->unreadNotifications()->count();

        return $this->sendToDeviceTokens($tokens, $title, $body, $data, $badge);
    }

    /**
     * Envoie la notification à un terminal spécifique.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToDevice(DeviceToken $deviceToken, string $title, string $body, array $data = [], ?int $badge = null): bool
    {
        $result = $this->sendToDeviceTokens(collect([$deviceToken]), $title, $body, $data, $badge);

        return $result['sent'] > 0;
    }

    /**
     * Envoie un message push à une collection de DeviceToken par lots de 100 via Expo.
     *
     * @param  Collection<int, DeviceToken>  $tokens
     * @param  array<string, mixed>  $data
     * @return array{sent: int, failed: int}
     */
    public function sendToDeviceTokens(Collection $tokens, string $title, string $body, array $data = [], ?int $badge = null): array
    {
        $sent = 0;
        $failed = 0;

        // Préparation des données nettoyées pour l'app Expo
        $pushData = $this->formatPushData($data);

        // Découpage par lots de 100 conformément aux recommandations d'Expo
        $chunks = $tokens->chunk(self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $messages = [];
            $tokenMap = [];

            foreach ($chunk->values() as $index => $deviceToken) {
                $payload = [
                    'to' => $deviceToken->token,
                    'title' => $title,
                    'body' => $body,
                    'sound' => 'default',
                    'data' => $pushData,
                ];

                if ($badge !== null) {
                    $payload['badge'] = $badge;
                }

                $messages[] = $payload;
                $tokenMap[$index] = $deviceToken;
            }

            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Accept-Encoding' => 'gzip, deflate',
                        'Content-Type' => 'application/json',
                    ])
                    ->post(self::EXPO_PUSH_URL, $messages);

                if ($response->successful()) {
                    $responseData = $response->json('data') ?? [];

                    foreach ($responseData as $index => $ticket) {
                        $deviceToken = $tokenMap[$index] ?? null;
                        if (! $deviceToken) {
                            continue;
                        }

                        $status = $ticket['status'] ?? 'error';

                        if ($status === 'ok') {
                            $deviceToken->update(['last_used_at' => now()]);
                            $sent++;
                        } else {
                            $failed++;
                            $errorType = $ticket['details']['error'] ?? ($ticket['message'] ?? 'UnknownError');

                            // Si le terminal n'est plus enregistré, désactivation immédiate
                            if ($errorType === 'DeviceNotRegistered') {
                                $deviceToken->update(['is_active' => false]);
                                Log::info('Token Expo invalidé (DeviceNotRegistered) : terminal désactivé', [
                                    'token_id' => $deviceToken->id,
                                    'user_id' => $deviceToken->user_id,
                                ]);
                            } else {
                                Log::warning('Erreur ticket Expo push', [
                                    'token_id' => $deviceToken->id,
                                    'error' => $errorType,
                                    'message' => $ticket['message'] ?? null,
                                ]);
                            }
                        }
                    }
                } else {
                    $failed += count($messages);
                    Log::error('Échec requête HTTP vers Expo Push Service', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (Throwable $e) {
                $failed += count($messages);
                Log::error('Exception lors de l\'envoi push Expo : '.$e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Vérifie si le push est autorisé selon les préférences utilisateur (ST-17).
     */
    public function isPushAllowedForUser(User $user, ?string $type = null): bool
    {
        $saved = $user->privacy_settings['notifications'] ?? [];
        $settings = array_merge(NotificationSettingsController::DEFAULT_SETTINGS, $saved);

        // Si le push global est désactivé
        if (isset($settings['push_enabled']) && ! $settings['push_enabled']) {
            return false;
        }

        if (empty($type)) {
            return true;
        }

        // Vérification par sous-catégorie
        return match ($type) {
            'like' => (bool) ($settings['likes'] ?? true),
            'comment', 'reply' => (bool) ($settings['comments'] ?? true),
            'follow', 'follow_request' => (bool) ($settings['new_followers'] ?? true),
            'new_message', 'new_story_reply', 'added_to_group' => (bool) ($settings['direct_messages'] ?? true),
            'mention' => (bool) ($settings['mentions'] ?? true),
            'story_question_answer', 'countdown_remind' => (bool) ($settings['stories'] ?? true),
            'new_matching_listing', 'listing_decision' => (bool) ($settings['listings'] ?? true),
            'visit_requested', 'visit_confirmed', 'visit_cancelled', 'visit_declined', 'visit_proposed' => (bool) ($settings['visits'] ?? true),
            // Notifications transactionnelles / compte (KYC, Paiements, Boost, etc.) : autorisées dès lors que push_enabled est true
            default => true,
        };
    }

    /**
     * Formate les métadonnées de redirection pour l'application mobile Expo.
     * Conserve les identifiants nécessaires à l'ouverture directe dans l'app :
     * type, conversation_id, listing_id, post_id, reel_id, etc.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function formatPushData(array $data): array
    {
        $formatted = [];

        // Type d'événement
        $formatted['type'] = $data['type'] ?? 'general';

        // Identifiants de navigation
        if (isset($data['conversation_id'])) {
            $formatted['conversation_id'] = (string) $data['conversation_id'];
        }

        if (isset($data['message_id'])) {
            $formatted['message_id'] = (string) $data['message_id'];
        }

        if (isset($data['listing_id'])) {
            $formatted['listing_id'] = (string) $data['listing_id'];
        }

        if (isset($data['post_id'])) {
            $formatted['post_id'] = (string) $data['post_id'];
        }

        if (isset($data['reel_id'])) {
            $formatted['reel_id'] = (string) $data['reel_id'];
        }

        if (isset($data['story_id'])) {
            $formatted['story_id'] = (string) $data['story_id'];
        }

        if (isset($data['reservation_id'])) {
            $formatted['reservation_id'] = (string) $data['reservation_id'];
        }

        if (isset($data['submission_id'])) {
            $formatted['submission_id'] = (string) $data['submission_id'];
        }

        if (isset($data['campaign_id'])) {
            $formatted['campaign_id'] = (string) $data['campaign_id'];
        }

        if (isset($data['page_id'])) {
            $formatted['page_id'] = (string) $data['page_id'];
        }

        // Intégration des sous-métadonnées si présentes (ex: metadata venant de SocialNotification)
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            foreach (['post_id', 'reel_id', 'story_id', 'listing_id', 'conversation_id', 'page_id'] as $key) {
                if (isset($data['metadata'][$key]) && ! isset($formatted[$key])) {
                    $formatted[$key] = (string) $data['metadata'][$key];
                }
            }
        }

        return $formatted;
    }
}
