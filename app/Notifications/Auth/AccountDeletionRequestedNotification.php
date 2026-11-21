<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class AccountDeletionRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Carbon $scheduledDeletionDate,
        string $locale = 'fr'
    ) {
        $this->locale = $locale;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dateFormatted = $this->scheduledDeletionDate->locale($this->locale)->isoFormat('LL');

        if ($this->locale === 'en') {
            return (new MailMessage)
                ->subject('Scheduled deletion of your BANGO account')
                ->greeting("Hello {$notifiable->name},")
                ->line('We have received your request to delete your BANGO account and data.')
                ->line("Your account has been deactivated and is scheduled for permanent deletion on **{$dateFormatted}** (30-day grace period).")
                ->line('**Data that will be permanently erased:** your public profile, posts, reels, stories, private messages, voice notes, media files, and compared contact hashes.')
                ->line('**Data retained per mandatory statutory laws:** transaction records and accounting invoices (10 years under commercial/tax regulations), verified identity/KYC records (5 years for anti-fraud compliance).')
                ->line("**Changed your mind?** Simply sign back in to the BANGO mobile app before {$dateFormatted} to automatically cancel this request and restore your account and data.")
                ->salutation('The BANGO Team');
        }

        return (new MailMessage)
            ->subject('Suppression programmée de votre compte BANGO')
            ->greeting("Bonjour {$notifiable->name},")
            ->line('Nous avons bien reçu votre demande de suppression de votre compte BANGO et de vos données.')
            ->line("Votre compte a été désactivé et est programmé pour une suppression définitive le **{$dateFormatted}** (délai de grâce de 30 jours).")
            ->line('**Données qui seront définitivement effacées :** votre profil public, publications, reels, stories, messages privés, notes vocales, médias stockés et empreintes de contacts comparés.')
            ->line('**Données conservées selon les obligations légales :** factures et justificatifs comptables (10 ans selon la loi fiscale et commerciale), pièces d\'identité et dossiers KYC validés (5 ans au titre de la lutte contre la fraude).')
            ->line("**Vous avez changé d'avis ?** Il vous suffit de vous reconnecter à l'application mobile BANGO avant le {$dateFormatted} avec vos identifiants pour annuler automatiquement cette suppression et retrouver votre compte intact.")
            ->salutation('L\'équipe BANGO');
    }
}
