<?php

namespace App\Listeners\Notification;

use App\Jobs\Notification\SendPushNotificationJob;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Notifications\Events\NotificationSent;

class SendPushOnNotificationSent
{
    /**
     * Traite l'événement NotificationSent.
     * Dès qu'une notification est persistée dans la base de données, déclenche l'envoi push vers Expo.
     */
    public function handle(NotificationSent $event): void
    {
        // On n'intercepte que le canal 'database'
        if ($event->channel !== 'database') {
            return;
        }

        $notifiable = $event->notifiable;
        if (! ($notifiable instanceof User)) {
            return;
        }

        $notification = $event->notification;

        // Si la notification est une SocialNotification, elle dispatchait déjà un job manuellement dans toArray.
        // Pour éviter un double envoi tout en gardant une source de vérité propre,
        // nous extrayons ici les données standards pour toutes les notifications.
        $data = [];
        if (method_exists($notification, 'toArray')) {
            $data = $notification->toArray($notifiable);
        } elseif (isset($event->response) && is_array($event->response)) {
            $data = $event->response;
        }

        if (empty($data['type'])) {
            $data['type'] = isset($notification->type) ? (string) $notification->type : class_basename($notification);
        }

        // Vérification des préférences de notification de l'utilisateur avant dispatch
        if (! app(NotificationPreferenceService::class)->isPushAllowed($notifiable, $notification)) {
            return;
        }

        $title = $this->resolveTitle($notification, $data, $notifiable);
        $body = $this->resolveBody($notification, $data);

        SendPushNotificationJob::dispatch($notifiable, $title, $body, $data);
    }

    /**
     * Détermine le titre de la notification push.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveTitle(object $notification, array $data, ?User $notifiable = null): string
    {
        if (! empty($data['title'])) {
            return (string) $data['title'];
        }

        $type = $data['type'] ?? class_basename($notification);
        $locale = $notifiable?->locale ?? 'fr';

        $category = match (true) {
            str_starts_with($type, 'visit_'), str_starts_with($type, 'listing_visit') => 'visit',
            str_starts_with($type, 'new_message') => 'new_message',
            str_starts_with($type, 'new_story_reply') => 'new_story_reply',
            str_starts_with($type, 'added_to_group') => 'added_to_group',
            str_starts_with($type, 'kyc_') => 'kyc',
            str_starts_with($type, 'listing_'), str_starts_with($type, 'new_matching_listing') => 'listing',
            str_starts_with($type, 'campaign_') => 'campaign',
            str_starts_with($type, 'subscription_'), str_starts_with($type, 'renewal_'), str_starts_with($type, 'payment_') => 'subscription',
            str_starts_with($type, 'page_'), str_contains($type, 'PageMember') => 'page',
            str_starts_with($type, 'agent_'), str_starts_with($type, 'delegation_') => 'delegation',
            default => 'default',
        };

        return trans("notifications.categories.{$category}", [], $locale);
    }

    /**
     * Détermine le corps du message push.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveBody(object $notification, array $data): string
    {
        return (string) ($data['message'] ?? $data['body'] ?? $data['preview'] ?? $data['content'] ?? 'Vous avez reçu une nouvelle notification sur BANGO.');
    }
}
