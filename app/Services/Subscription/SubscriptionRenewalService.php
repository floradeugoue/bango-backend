<?php

namespace App\Services\Subscription;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Boost\CreditWallet;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionEvent;
use App\Models\Subscription\SubscriptionReminder;
use App\Notifications\Subscription\PaymentFailedReminderNotification;
use App\Notifications\Subscription\RenewalUpcomingReminderNotification;
use App\Services\Boost\CreditWalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionRenewalService
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected ?CreditWalletService $creditWalletService = null
    ) {
        $this->creditWalletService ??= app(CreditWalletService::class);
    }

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
     * 1. Renouvelle automatiquement à l'échéance par crédit BANGO si auto_renew est actif et que le solde suffit.
     * 2. Bascule en période de grâce (7 jours) les abonnements actifs échus sans prélèvement (failed_attempts = 0).
     * 3. Expire les abonnements dont la période de grâce est dépassée.
     *
     * @return array{renewed_auto: int, entered_grace: int, expired: int}
     */
    public function processLifecycle(?Carbon $referenceDate = null): array
    {
        $now = $referenceDate ?: now();
        $graceDays = config('subscription.grace_period_days', 7);

        $results = ['renewed_auto' => 0, 'entered_grace' => 0, 'expired' => 0];

        // 1. Passage en Grâce ou renouvellement par crédit : abonnements actifs échus
        $dueSubscriptions = Subscription::with(['user.creditWallet', 'product.prices', 'price'])
            ->where('state', 'active')
            ->where('current_period_end', '<=', $now)
            ->get();

        foreach ($dueSubscriptions as $subscription) {
            // Tenter le prélèvement automatique à l'échéance si auto_renew est activé et si le solde de crédit BANGO suffit
            if ($subscription->auto_renew && $this->canRenewWithCredit($subscription)) {
                try {
                    $renewed = $this->renewWithCredit($subscription);
                    if ($renewed) {
                        $results['renewed_auto']++;

                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::error("Échec du prélèvement du crédit BANGO à l'échéance pour l'abonnement #{$subscription->id}: ".$e->getMessage());

                    // Échec avéré de prélèvement : incrémenter failed_attempts
                    $this->subscriptionService->enterGracePeriod(
                        subscription: $subscription,
                        failureReason: "Échec du prélèvement à l'échéance",
                        incrementFailedAttempts: true
                    );
                    $results['entered_grace']++;

                    continue;
                }
            }

            // Aucun prélèvement tenté (pas d'auto_renew ou solde de crédit insuffisant) :
            // Laisser failed_attempts à 0 et consigner 'Échéance passée sans renouvellement'
            $this->subscriptionService->enterGracePeriod(
                subscription: $subscription,
                failureReason: 'Échéance passée sans renouvellement',
                incrementFailedAttempts: false
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

    /**
     * Vérifie si un abonnement échu peut être renouvelé automatiquement avec le solde de crédits BANGO.
     */
    public function canRenewWithCredit(Subscription $subscription): bool
    {
        $user = $subscription->user;
        if (! $user) {
            return false;
        }

        $price = $subscription->price ?? $subscription->product?->activePrices()->where('cadence', $subscription->cadence)->first();
        if (! $price) {
            return false;
        }

        $amount = (float) ($price->price_amount ?? $subscription->amount);
        $requiredCredits = (int) round($amount);

        if ($requiredCredits <= 0) {
            return false;
        }

        $wallet = $this->creditWalletService->getOrCreateWallet($user);

        return $wallet->canAfford($requiredCredits);
    }

    /**
     * Renouvelle automatiquement l'abonnement à l'échéance en débitant le portefeuille de crédits BANGO.
     */
    public function renewWithCredit(Subscription $subscription): bool
    {
        $user = $subscription->user;
        if (! $user) {
            return false;
        }

        $price = $subscription->price ?? $subscription->product?->activePrices()->where('cadence', $subscription->cadence)->first();
        if (! $price) {
            return false;
        }

        $amount = (float) ($price->price_amount ?? $subscription->amount);
        $requiredCredits = (int) round($amount);

        if ($requiredCredits <= 0) {
            return false;
        }

        $wallet = $this->creditWalletService->getOrCreateWallet($user);

        return DB::transaction(function () use ($subscription, $user, $price, $wallet, $requiredCredits, $amount) {
            /** @var CreditWallet $lockedWallet */
            $lockedWallet = CreditWallet::where('id', $wallet->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedWallet || ! $lockedWallet->canAfford($requiredCredits)) {
                return false;
            }

            $periodKey = $subscription->current_period_end?->format('YmdHis') ?: now()->format('YmdHis');
            $idempotencyKey = 'auto_renew_sub_'.$subscription->id.'_'.$periodKey;

            // 1. Débit atomique du portefeuille de crédits BANGO
            $creditTx = $this->creditWalletService->debit(
                account: $lockedWallet,
                amount: $requiredCredits,
                source: 'subscription',
                reference: $idempotencyKey,
                description: sprintf('Renouvellement automatique abonnement %s (#%d)', $subscription->product?->name ?? 'BANGO', $subscription->id),
                idempotencyKey: $idempotencyKey
            );

            // 2. Création de la transaction officielle (canal Portefeuille de crédits)
            $paymentTx = PaymentTransaction::create([
                'user_id' => $user->id,
                'service_type' => PaymentServiceType::Subscription,
                'product_id' => $price->product_id,
                'price_id' => $price->id,
                'subscription_id' => $subscription->id,
                'provider' => PaymentProvider::Wallet,
                'channel' => PaymentChannel::CreditWallet,
                'currency' => $price->currency ?: 'XAF',
                'amount' => (string) $amount,
                'fee_amount' => '0.00',
                'tax_amount' => '0.00',
                'total_amount' => (string) $amount,
                'credit_used' => (string) $requiredCredits,
                'amount_charged' => (string) $amount,
                'status' => PaymentStatus::Confirmed,
                'confirmed_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'external_reference' => $creditTx->reference ?? $idempotencyKey,
                'metadata' => [
                    'context' => 'auto_renewal_credit',
                    'previous_period_end' => $subscription->current_period_end?->toIso8601String(),
                    'wallet_transaction_id' => $creditTx->id,
                ],
            ]);

            // 3. Activation du renouvellement sur l'abonnement
            $this->subscriptionService->activateSubscription(
                user: $user,
                price: $price,
                paymentReference: $paymentTx->reference,
                metadata: [
                    'subscription_id' => $subscription->id,
                    'page_id' => $subscription->page_id,
                    'payment_transaction_id' => $paymentTx->id,
                    'provider' => 'wallet',
                    'amount_paid' => $amount,
                    'context' => 'auto_renewal_credit',
                ]
            );

            return true;
        });
    }
}
