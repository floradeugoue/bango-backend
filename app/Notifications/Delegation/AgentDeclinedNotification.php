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
        public Delegation $delegation
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
        $agentName = $this->delegation->agent->name ?? 'L\'agent';
        $listingTitle = $this->delegation->listing->title ?? 'votre bien';

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
        return [
            'type' => 'agent_declined',
            'delegation_id' => $this->delegation->id,
            'listing_id' => $this->delegation->listing_id,
            'agent_id' => $this->delegation->agent_id,
            'agent_name' => $this->delegation->agent->name ?? 'Agent',
            'listing_title' => $this->delegation->listing->title ?? 'Bien',
            'message' => 'L\'agent a décliné votre invitation de mandat',
        ];
    }
}
