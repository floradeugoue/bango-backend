<?php

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];
        $category = $this->resolveCategory($this->type, $data);

        $title = $data['title'] ?? match (true) {
            isset($data['type']) && str_starts_with($data['type'], 'visit_') => 'Visite immobilière',
            isset($data['type']) && str_starts_with($data['type'], 'campaign_') => 'Campagne Boost',
            isset($data['type']) && str_starts_with($data['type'], 'agent_') => 'Délégation immobilière',
            isset($data['type']) && str_starts_with($data['type'], 'kyc_') => 'Vérification d\'identité',
            default => 'Notification',
        };

        $content = $data['preview'] ?? $data['message'] ?? $data['body'] ?? $data['content'] ?? ($data['reason'] ?? '');

        return [
            'id' => (string) $this->id,
            'category' => $category,
            'type' => $data['type'] ?? class_basename($this->type),
            'title' => (string) $title,
            'content' => (string) $content,
            'data' => $data,
            'read_at' => $this->read_at?->toIso8601String(),
            'is_read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Détermine la catégorie métier de la notification.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveCategory(string $type, array $data): string
    {
        if (isset($data['category']) && in_array($data['category'], ['immo', 'social', 'account'], true)) {
            return $data['category'];
        }

        if (str_contains($type, 'Notifications\\Visit') || str_contains($type, 'Notifications\\Delegation')) {
            return 'immo';
        }

        if (str_contains($type, 'Notifications\\Message')) {
            return 'social';
        }

        if (
            str_contains($type, 'Notifications\\Subscription') ||
            str_contains($type, 'Notifications\\Boost') ||
            str_contains($type, 'Notifications\\Kyc')
        ) {
            return 'account';
        }

        if (str_contains($type, 'Notifications\\Page')) {
            if (str_contains($type, 'PageMember')) {
                return 'social';
            }

            return 'account';
        }

        $dataType = $data['type'] ?? '';
        if (str_starts_with($dataType, 'visit_') || str_starts_with($dataType, 'delegation_') || str_starts_with($dataType, 'listing_')) {
            return 'immo';
        }

        if (str_starts_with($dataType, 'new_message') || str_starts_with($dataType, 'post_') || str_starts_with($dataType, 'reel_') || str_starts_with($dataType, 'story_')) {
            return 'social';
        }

        return 'account';
    }
}
