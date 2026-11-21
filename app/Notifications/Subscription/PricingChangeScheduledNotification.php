<?php

namespace App\Notifications\Subscription;

use App\Models\Subscription\PricingChangeProposal;
use App\Models\Subscription\Subscription;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PricingChangeScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public PricingChangeProposal $proposal,
        public ?Subscription $subscription = null
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $productName = $this->proposal->price?->product?->name ?? 'ta formule BANGO';
        $currency = $this->proposal->price?->currency ?? 'XAF';
        $newAmount = number_format((float) $this->proposal->new_price_amount, 0, ',', ' ').' '.$currency;
        $timezone = method_exists($notifiable, 'resolveTimezone')
            ? $notifiable->resolveTimezone()
            : ($notifiable->timezone ?? 'Africa/Douala');
        $effectiveDate = $this->proposal->effective_date?->copy()->setTimezone($timezone)->format('d/m/Y') ?? 'prochainement';

        $currentAmount = $this->subscription?->amount ?? $this->proposal->price?->price_amount;
        $currentAmountFormatted = number_format((float) $currentAmount, 0, ',', ' ').' '.$currency;

        $mail = (new MailMessage)
            ->subject("Information importante : Évolution tarifaire de ton abonnement {$productName}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Nous t'informons d'une programmation d'évolution tarifaire concernant ta formule {$productName}.")
            ->line("À compter du {$effectiveDate}, le nouveau montant de l'abonnement sera de {$newAmount}.")
            ->line("Conformément à nos engagements, tu conserves ton tarif actuel ({$currentAmountFormatted}) sans surcoût jusqu'à la fin de ta période en cours.")
            ->line("Le nouveau tarif s'appliquera uniquement lors de ton prochain renouvellement.")
            ->action('Consulter mon abonnement', url('/subscriptions'));

        if (! empty($this->proposal->terms_version)) {
            $mail->line("Cette évolution s'accompagne d'une mise à jour de nos Conditions Générales d'Utilisation (version {$this->proposal->terms_version}) qui devra être acceptée préalablement au renouvellement.");
        }

        return $mail;
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $productName = $this->proposal->price?->product?->name ?? 'Abonnement BANGO';
        $currency = $this->proposal->price?->currency ?? 'XAF';
        $newAmount = (float) $this->proposal->new_price_amount;
        $currentAmount = (float) ($this->subscription?->amount ?? $this->proposal->price?->price_amount ?? 0);
        $timezone = method_exists($notifiable, 'resolveTimezone')
            ? $notifiable->resolveTimezone()
            : ($notifiable->timezone ?? 'Africa/Douala');
        $dateFormatted = $this->proposal->effective_date?->copy()->setTimezone($timezone)->format('d/m/Y') ?? '';

        return [
            'type' => 'pricing_change_scheduled',
            'proposal_id' => $this->proposal->id,
            'price_id' => $this->proposal->price_id,
            'product_name' => $productName,
            'current_price' => $currentAmount,
            'new_price' => $newAmount,
            'currency' => $currency,
            'effective_date' => $this->proposal->effective_date?->toIso8601String(),
            'terms_version' => $this->proposal->terms_version,
            'title' => "Évolution tarifaire : {$productName}",
            'message' => "Le tarif de ton abonnement {$productName} passera à ".number_format($newAmount, 0, ',', ' ')." {$currency} à compter du {$dateFormatted}. Tu conserves ton tarif actuel jusqu'à ton prochain renouvellement.",
            'action_url' => '/subscriptions',
        ];
    }
}
