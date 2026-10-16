<?php

namespace App\Notifications\Delegation;

use App\Models\Delegation\Delegation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DelegationRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Delegation $delegation,
        public ?string $reason = null
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
        $landlordName = $this->delegation->bailleur->name ?? 'Le bailleur';
        $listingTitle = $this->delegation->listing->title ?? 'un bien';

        $mail = (new MailMessage)
            ->subject("Mandat révoqué : {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("{$landlordName} a mis fin à votre mandat de gestion sur le bien « {$listingTitle} ».");

        if ($this->reason) {
            $mail->line("Motif : {$this->reason}");
        }

        return $mail->action('Voir mon portefeuille', url('/agent/properties'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'delegation_revoked',
            'delegation_id' => $this->delegation->id,
            'listing_id' => $this->delegation->listing_id,
            'bailleur_id' => $this->delegation->bailleur_id,
            'bailleur_name' => $this->delegation->bailleur->name ?? 'Bailleur',
            'listing_title' => $this->delegation->listing->title ?? 'Bien',
            'reason' => $this->reason,
            'message' => 'Le mandat a été révoqué',
        ];
    }
}
