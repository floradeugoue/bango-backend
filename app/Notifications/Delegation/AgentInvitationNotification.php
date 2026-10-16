<?php

namespace App\Notifications\Delegation;

use App\Models\Delegation\Delegation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgentInvitationNotification extends Notification implements ShouldQueue
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
        $landlordName = $this->delegation->bailleur->name ?? 'Un bailleur';
        $listingTitle = $this->delegation->listing->title ?? 'un bien';

        return (new MailMessage)
            ->subject("Nouvelle invitation de mandat : {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("{$landlordName} vous a invité à gérer le bien « {$listingTitle} ».")
            ->action('Voir l\'invitation', url("/agent/invitations/{$this->delegation->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'agent_invitation',
            'delegation_id' => $this->delegation->id,
            'listing_id' => $this->delegation->listing_id,
            'bailleur_id' => $this->delegation->bailleur_id,
            'bailleur_name' => $this->delegation->bailleur->name ?? 'Bailleur',
            'listing_title' => $this->delegation->listing->title ?? 'Bien',
            'capabilities' => $this->delegation->capabilities,
            'expires_at' => $this->delegation->expires_at?->toIso8601String(),
            'message' => 'Nouvelle invitation de mandat reçue',
        ];
    }
}
