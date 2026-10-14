<?php

namespace App\Notifications\Subscription;

use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentFailedReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
        public PaymentTransaction $failedTransaction,
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
        $productName = $this->subscription->product->name ?? 'votre abonnement';
        $amount = number_format((float) $this->failedTransaction->total_amount, 0, ',', ' ').' '.$this->failedTransaction->currency;

        return (new MailMessage)
            ->subject("Échec de paiement : Relance pour votre abonnement {$productName}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Une tentative de paiement de {$amount} pour {$productName} n'a pas pu aboutir.")
            ->line('Pour éviter la suspension de votre badge et de vos avantages, nous vous invitons à réitérer votre paiement via le parcours de renouvellement manuel.')
            ->action('Régulariser mon abonnement', url("/subscriptions/{$this->subscription->id}/renewal"))
            ->line("Aucun montant n'a été prélevé sur votre compte.");
    }

    /**
     * Get the array representation of the notification for database storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $productName = $this->subscription->product->name ?? 'Abonnement BANGO';

        return [
            'type' => 'payment_failed_reminder',
            'reminder_type' => $this->reminderType,
            'subscription_id' => $this->subscription->id,
            'transaction_id' => $this->failedTransaction->id,
            'transaction_reference' => $this->failedTransaction->reference,
            'product_name' => $productName,
            'amount' => (float) $this->failedTransaction->total_amount,
            'currency' => $this->failedTransaction->currency,
            'action_url' => "/subscriptions/{$this->subscription->id}/renewal",
            'title' => "Échec de paiement — {$productName}",
            'message' => "Le paiement pour {$productName} a échoué. Régularisez votre situation pour conserver votre badge actif.",
            'requires_manual_confirmation' => true,
        ];
    }
}
