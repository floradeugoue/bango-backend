<?php

namespace App\Notifications\Delegation;

use App\Models\Delegation\Delegation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgentDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Delegation $delegation,
        public int $propertiesCount = 1
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $agent = $this->delegation->relationLoaded('agent') ? $this->delegation->agent : $this->delegation->agent()->first();
        $agentName = $agent?->display_name ?: ($agent?->name ?? 'L\'agent');
        $count = max(1, $this->propertiesCount);

        if ($count > 1) {
            return (new MailMessage)
                ->subject("Mandats déclinés ({$count} biens)")
                ->greeting("Bonjour {$notifiable->name},")
                ->line("{$agentName} a décliné votre invitation de mandat pour {$count} de vos biens.")
                ->action('Voir mes délégations', url('/landlord/delegations'));
        }

        $listingTitle = $this->delegation->listing?->title ?? 'votre bien';

        return (new MailMessage)
            ->subject("Mandat décliné : {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("{$agentName} a décliné votre invitation de mandat pour « {$listingTitle} ».")
            ->action('Voir mes délégations', url('/landlord/delegations'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $agent = $this->delegation->relationLoaded('agent') ? $this->delegation->agent : $this->delegation->agent()->first();
        $agentName = $agent?->display_name ?: ($agent?->name ?? 'Agent');
        $count = max(1, $this->propertiesCount);

        return [
            'type' => 'agent_declined',
            'delegation_id' => $this->delegation->id,
            'listing_id' => $this->delegation->listing_id,
            'agent_id' => $this->delegation->agent_id,
            'agent_name' => $agentName,
            'properties_count' => $count,
            'listing_title' => $count > 1
                ? "{$count} biens"
                : ($this->delegation->listing?->title ?? 'Bien'),
            'title' => trans('notifications.agent_declined.title', [], $locale),
            'message' => $count > 1
                ? "{$agentName} a décliné la gestion de {$count} de vos biens."
                : trans('notifications.agent_declined.body', [], $locale),
        ];
    }
}
