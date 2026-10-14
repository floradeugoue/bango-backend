<?php

namespace App\Services\Subscription;

use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionEvent;
use App\Models\Subscription\SubscriptionReminder;
use App\Notifications\Subscription\PaymentFailedReminderNotification;
use App\Notifications\Subscription\RenewalUpcomingReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionRenewalService
{
    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * Envoie les rappels planifiés avant échéance (J-7, J-3, J-1).
     *
     * @return array{checked: int, sent: int, skipped: int}
     */
    public function sendUpcomingReminders(?Carbon $referenceDate = null): array
    {
        $now = $referenceDate ?: now();
        $daysConfig = config('subscription.reminders_days_before', [7, 3, 1]);

        $stats = ['checked' => 0, 'sent' => 0, 'skipped' => 0];

        foreach ($daysConfig as $days) {
            $targetDate = $now->copy()->addDays((int) $days)->startOfDay();
            $targetDateEnd = $targetDate->copy()->endOfDay();
            $reminderType = "upcoming_{$days}d";

            // Abonnements actifs dont l'échéance tombe ce jour cible
            $subscriptions = Subscription::with(['user', 'product', 'price'])
                ->where('state', 'active')
                ->whereBetween('current_period_end', [$targetDate, $targetDateEnd])
                ->get();

            foreach ($subscriptions as $subscription) {
                $stats['checked']++;
                $periodEndDate = $subscription->current_period_end->format('Y-m-d');
                $idempotencyKey = SubscriptionReminder::makeUpcomingKey($subscription->id, $reminderType, $periodEndDate);

                // Vérification stricte d'idempotence
                if (SubscriptionReminder::where('idempotency_key', $idempotencyKey)->exists()) {
                    $stats['skipped']++;

                    continue;
                }

                DB::transaction(function () use ($subscription, $days, $reminderType, $idempotencyKey, &$stats) {
                    // Enregistrement du rappel avec contrainte unique
                    SubscriptionReminder::create([
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'reminder_type' => $reminderType,
                        'target_date' => $subscription->current_period_end,
                        'channel' => 'database',
                        'status' => 'sent',
                        'idempotency_key' => $idempotencyKey,
                        'payload' => [
                            'days_remaining' => $days,
                            'expires_at' => $subscription->current_period_end->toIso8601String(),
                            'amount' => (float) $subscription->amount,
                            'currency' => $subscription->currency,
                        ],
                        'sent_at' => now(),
                    ]);

                    // Émission de la notification Laravel
                    $subscription->user->notify(
                        new RenewalUpcomingReminderNotification($subscription, $days, $reminderType)
                    );

                    // Journalisation dans l'historique d'audit SUB
                    SubscriptionEvent::create([
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'event_type' => 'reminder_sent',
                        'old_state' => $subscription->state,
                        'new_state' => $subscription->state,
                        'actor_role' => 'system',
                        'reason' => "Rappel d'échéance à J-{$days} envoyé à l'utilisateur",
                        'payload' => [
                            'reminder_type' => $reminderType,
                            'days' => $days,
                            'idempotency_key' => $idempotencyKey,
                        ],
                    ]);

                    $stats['sent']++;
                });
            }
        }

        return $stats;
    }

    /**
     * Envoie les relances après un échec définitif de paiement (ex: 24h et 72h après l'échec).
     *
     * @return array{checked: int, sent: int, skipped: int}
     */
    public function sendFailedPaymentRetries(?Carbon $referenceDate = null): array
    {
        $now = $referenceDate ?: now();
        $retryDelaysHours = config('subscription.retry_delays_hours', [24, 72]);

        $stats = ['checked' => 0, 'sent' => 0, 'skipped' => 0];

        foreach ($retryDelaysHours as $hours) {
            $windowStart = $now->copy()->subHours((int) $hours + 6);
            $windowEnd = $now->copy()->subHours((int) $hours);
            $reminderType = "failed_payment_{$hours}h";

            // Transactions de souscription définitivement échouées dans cette fenêtre
            $failedTransactions = PaymentTransaction::with(['user', 'subscription.product'])
                ->where('service_type', PaymentServiceType::Subscription)
                ->where('status', PaymentStatus::Failed)
                ->whereNotNull('subscription_id')
                ->whereBetween('failed_at', [$windowStart, $windowEnd])
                ->get();

            foreach ($failedTransactions as $transaction) {
                $stats['checked']++;
                $subscription = $transaction->subscription;

                if (! $subscription) {
                    $stats['skipped']++;

                    continue;
                }

                // Ne pas relancer si l'utilisateur a depuis déjà renouvelé ou si l'abonnement est actif dans le futur lointain
                if ($subscription->isActive() && $subscription->current_period_end->isAfter($now->copy()->addDays(15))) {
                    $stats['skipped']++;

                    continue;
                }

                // Vérifier qu'il n'y a pas un paiement EN COURS (processing, pending) pour ce même abonnement
                $hasPendingPayment = PaymentTransaction::where('subscription_id', $subscription->id)
                    ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Processing])
                    ->exists();

                if ($hasPendingPayment) {
                    $stats['skipped']++;

                    continue;
                }

                $idempotencyKey = SubscriptionReminder::makeFailedPaymentKey($subscription->id, $transaction->id, $reminderType);

                if (SubscriptionReminder::where('idempotency_key', $idempotencyKey)->exists()) {
                    $stats['skipped']++;

                    continue;
                }

                DB::transaction(function () use ($subscription, $transaction, $reminderType, $idempotencyKey, &$stats) {
                    SubscriptionReminder::create([
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'reminder_type' => $reminderType,
                        'target_date' => $transaction->failed_at,
                        'channel' => 'database',
                        'status' => 'sent',
                        'idempotency_key' => $idempotencyKey,
                        'payload' => [
                            'failed_transaction_id' => $transaction->id,
                            'failed_at' => $transaction->failed_at?->toIso8601String(),
                            'amount' => (float) $transaction->total_amount,
                        ],
                        'sent_at' => now(),
                    ]);

                    $transaction->user->notify(
                        new PaymentFailedReminderNotification($subscription, $transaction, $reminderType)
                    );

                    SubscriptionEvent::create([
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->user_id,
                        'event_type' => 'failed_payment_reminder_sent',
                        'old_state' => $subscription->state,
                        'new_state' => $subscription->state,
                        'actor_role' => 'system',
                        'reason' => "Relance après échec de paiement (transaction #{$transaction->id})",
                        'payload' => [
                            'transaction_id' => $transaction->id,
                            'reminder_type' => $reminderType,
                            'idempotency_key' => $idempotencyKey,
                        ],
                    ]);

                    $stats['sent']++;
                });
            }
        }

        return $stats;
    }

    /**
     * Traite le cycle de vie des abonnements de manière idempotente :
     * 1. Bascule en période de grâce (7 jours) les abonnements actifs échus.
     * 2. Expire les abonnements dont la période de grâce est dépassée.
     *
     * @return array{entered_grace: int, expired: int}
     */
    public function processLifecycle(?Carbon $referenceDate = null): array
    {
        $now = $referenceDate ?: now();
        $graceDays = config('subscription.grace_period_days', 7);

        $results = ['entered_grace' => 0, 'expired' => 0];

        // 1. Passage en Grâce : abonnements dont current_period_end est passé
        $dueSubscriptions = Subscription::where('state', 'active')
            ->where('current_period_end', '<=', $now)
            ->get();

        foreach ($dueSubscriptions as $subscription) {
            // Ne pas passer en grâce si une transaction est déjà confirmée en attente de traitement
            $this->subscriptionService->enterGracePeriod(
                subscription: $subscription,
                failureReason: 'Échéance dépassée — Début de la période de grâce de '.$graceDays.' jours'
            );
            $results['entered_grace']++;
        }

        // 2. Expiration : abonnements en grâce dont grace_until est dépassé
        $expiredSubscriptions = Subscription::where('state', 'grace')
            ->whereNotNull('grace_until')
            ->where('grace_until', '<=', $now)
            ->get();

        foreach ($expiredSubscriptions as $subscription) {
            $this->subscriptionService->expireSubscription(
                subscription: $subscription,
                reason: 'Période de grâce de '.$graceDays.' jours échue sans renouvellement manuel'
            );
            $results['expired']++;
        }

        return $results;
    }
}
