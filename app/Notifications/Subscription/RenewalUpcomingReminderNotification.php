<?php

namespace App\Notifications\Subscription;

use App\Models\Subscription\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RenewalUpcomingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
        public int $daysRemaining,
        public string $reminderType
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $productName = $this->subscription->product->name ?? 'votre formule BANGO';
        $expiryDate = $this->subscription->current_period_end?->format('d/m/Y') ?? 'prochainement';
        $amount = number_format((float) $this->subscription->amount, 0, ',', ' ').' '.$this->subscription->currency;

        return (new MailMessage)
            ->subject("Rappel : Votre abonnement {$productName} expire dans {$this->daysRemaining} jour(s)")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Votre abonnement {$productName} arrive à échéance le {$expiryDate}.")
            ->line("Tarif de renouvellement : {$amount}.")
            ->line("Pour conserver vos avantages et votre badge sans interruption, vous pouvez initier votre renouvellement directement depuis l'application.")
            ->action('Renouveler mon abonnement', url("/subscriptions/{$this->subscription->id}/renewal"))
            ->line("Attention : Aucun prélèvement automatique n'est effectué. Le renouvellement requiert votre confirmation explicite.");
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $productName = $this->subscription->product->name ?? 'Abonnement BANGO';
        $expiryDate = $this->subscription->current_period_end?->toIso8601String();
        $dateFormatted = $this->subscription->current_period_end?->format('d/m/Y') ?? '';
        $amount = (float) $this->subscription->amount;

        return [
            'type' => 'renewal_reminder',
            'reminder_type' => $this->reminderType,
            'subscription_id' => $this->subscription->id,
            'badge_type' => $this->subscription->badge_type,
            'product_name' => $productName,
            'days_remaining' => $this->daysRemaining,
            'expires_at' => $expiryDate,
            'amount' => $amount,
            'currency' => $this->subscription->currency,
            'action_url' => "/subscriptions/{$this->subscription->id}/renewal",
            'title' => trans('notifications.renewal_reminder.title', ['days' => $this->daysRemaining, 'product' => $productName], $locale),
            'message' => trans('notifications.renewal_reminder.body', ['product' => $productName, 'date' => $dateFormatted, 'amount' => $amount, 'currency' => $this->subscription->currency], $locale),
            'requires_manual_confirmation' => true,
        ];
    }
}
