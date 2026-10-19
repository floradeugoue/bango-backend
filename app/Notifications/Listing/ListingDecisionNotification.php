<?php

namespace App\Notifications\Listing;

use App\Models\Listing\Listing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ListingDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Listing $listing,
        public string $decision,
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
        $subject = match ($this->decision) {
            'reject' => 'Votre annonce immobilière a été refusée — BANGO',
            'request_changes' => 'Modifications demandées sur votre annonce — BANGO',
            'unpublish' => 'Votre annonce a été dépubliée — BANGO',
            'merge' => 'Votre annonce a été fusionnée — BANGO',
            default => 'Mise à jour sur votre annonce immobilière — BANGO',
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Concernant votre annonce : \"{$this->listing->title}\".");

        if ($this->decision === 'reject') {
            $mail->line('Votre annonce n\'a pas pu être validée par notre équipe de modération.')
                ->line('Motif : '.($this->reason ?? 'Non conforme aux règles de publication.'));
        } elseif ($this->decision === 'request_changes') {
            $mail->line('Des modifications sont requises avant publication.')
                ->line('Précisions : '.($this->reason ?? 'Veuillez vérifier les informations et médias.'));
        } elseif ($this->decision === 'unpublish') {
            $mail->line('Votre annonce a été dépubliée par l\'administration.')
                ->line('Motif : '.($this->reason ?? 'Annonce suspendue ou expirée.'));
        } elseif ($this->decision === 'merge') {
            $mail->line('Votre annonce a été identifiée comme doublon et fusionnée avec l\'annonce principale.')
                ->line('Motif : '.($this->reason ?? 'Fusion de doublon.'));
        }

        return $mail->action('Voir mon annonce', url('/landlord/properties/'.$this->listing->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = match ($this->decision) {
            'reject' => 'Votre annonce "'.$this->listing->title.'" a été refusée. Motif : '.($this->reason ?? 'Non conforme.'),
            'request_changes' => 'Des modifications sont demandées sur votre annonce "'.$this->listing->title.'". Motif : '.($this->reason ?? 'Précisions nécessaires.'),
            'unpublish' => 'Votre annonce "'.$this->listing->title.'" a été dépubliée. Motif : '.($this->reason ?? 'Suspendue.'),
            'merge' => 'Votre annonce "'.$this->listing->title.'" a été fusionnée avec une annonce similaire. Motif : '.($this->reason ?? 'Doublon.'),
            default => 'Mise à jour sur votre annonce "'.$this->listing->title.'".',
        };

        return [
            'type' => 'listing_decision',
            'listing_id' => $this->listing->id,
            'decision' => $this->decision,
            'status' => $this->listing->status?->value ?? (string) $this->listing->status,
            'reason' => $this->reason,
            'message' => $message,
        ];
    }
}
