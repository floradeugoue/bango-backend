<?php

namespace App\Services\Subscription;

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Page\PageCertificationStatus;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Boost\CreditTransaction;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionEvent;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\User;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PricingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function __construct(
        protected BadgeResolutionService $badgeService
    ) {}

    /**
     * Prepare a subscription intent before payment.
     * Validates eligibility without prematurely activating the subscription.
     *
     * @return array<string, mixed>
     */
    public function prepareSubscription(User $user, SubscriptionPrice $price, array $payload = []): array
    {
        $product = $price->product;

        if (! $price->isEffective() || ! $product->is_active) {
            throw ValidationException::withMessages([
                'price_id' => ['Cette offre ou ce tarif n\'est plus disponible actuellement.'],
            ]);
        }

        // Check if user already has an active subscription for this badge type
        if ($product->badge_type) {
            $existing = $user->subscriptions()
                ->where('badge_type', $product->badge_type)
                ->where(function ($q) {
                    $q->where('state', 'active')
                        ->where('current_period_end', '>', now());
                })
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'subscription' => ['Vous avez déjà un abonnement actif pour ce badge (Expire le '.$existing->current_period_end->format('d/m/Y').').'],
                ]);
            }
        }

        $idempotencyKey = $payload['idempotency_key'] ?? (string) Str::uuid();

        return [
            'product' => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'badge_type' => $product->badge_type,
            ],
            'price' => [
                'id' => $price->id,
                'cadence' => $price->cadence,
                'amount' => (float) $price->price_amount,
                'currency' => $price->currency,
                'duration_days' => $price->duration_days,
            ],
            'payment_intent' => [
                'purpose' => 'subscription_'.($product->badge_type ?? $product->code),
                'amount' => (float) $price->price_amount,
                'currency' => $price->currency,
                'idempotency_key' => $idempotencyKey,
            ],
            'kyc_status' => [
                'is_verified' => (bool) $user->is_verified,
                'verification_lapsed' => (bool) ($user->verification_lapsed ?? false),
                'warning' => ! $user->is_verified ? 'Note : Le badge ne sera pleinement actif qu\'après approbation de vos documents KYC.' : null,
            ],
        ];
    }

    /**
     * Activate or renew a subscription upon verified payment from PAY module.
     */
    public function activateSubscription(
        User $user,
        SubscriptionPrice $price,
        string $paymentReference,
        array $metadata = [],
        ?User $actor = null
    ): Subscription {
        return DB::transaction(function () use ($user, $price, $paymentReference, $metadata, $actor) {
            $product = $price->product;
            $now = now();
            $durationDays = $price->duration_days ?: 30;
            $periodEnd = isset($metadata['custom_period_end'])
                ? Carbon::parse($metadata['custom_period_end'])
                : (isset($metadata['period_end'])
                    ? Carbon::parse($metadata['period_end'])
                    : $now->copy()->addDays($durationDays));

            $pageId = isset($metadata['page_id']) ? (int) $metadata['page_id'] : null;

            // Find existing subscription by ID if provided, or by user + badge_type
            if (! empty($metadata['subscription_id'])) {
                $subscription = Subscription::where('id', $metadata['subscription_id'])->lockForUpdate()->first();
            } else {
                $query = Subscription::where('user_id', $user->id)
                    ->where('badge_type', $product->badge_type ?: $product->code);

                if ($pageId) {
                    $query->where('page_id', $pageId);
                } else {
                    $query->whereNull('page_id');
                }

                $subscription = $query->lockForUpdate()->first();
            }

            $oldState = $subscription?->state;

            if ($subscription) {
                // If currently active and no explicit period_end was provided in metadata, extend from current_period_end instead of now
                if ($subscription->isActive() && ! isset($metadata['custom_period_end']) && ! isset($metadata['period_end'])) {
                    $periodEnd = $subscription->current_period_end->copy()->addDays($durationDays);
                }

                $subscription->update([
                    'product_id' => $product->id,
                    'price_id' => $price->id,
                    'cadence' => $price->cadence,
                    'state' => 'active',
                    'amount' => $price->price_amount,
                    'currency' => $price->currency,
                    'current_period_start' => $now,
                    'current_period_end' => $periodEnd,
                    'grace_until' => null,
                    'failed_attempts' => 0,
                    'auto_renew' => true,
                    'next_renewal' => $periodEnd,
                    'canceled_at' => null,
                    'cancel_reason' => null,
                    'cancel_feedback' => null,
                    'payment_reference' => $paymentReference,
                    'metadata' => array_merge($subscription->metadata ?? [], $metadata),
                ]);
            } else {
                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'page_id' => $pageId,
                    'product_id' => $product->id,
                    'price_id' => $price->id,
                    'badge_type' => $product->badge_type ?: $product->code,
                    'cadence' => $price->cadence,
                    'state' => 'active',
                    'amount' => $price->price_amount,
                    'currency' => $price->currency,
                    'current_period_start' => $now,
                    'current_period_end' => $periodEnd,
                    'grace_until' => null,
                    'failed_attempts' => 0,
                    'auto_renew' => true,
                    'next_renewal' => $periodEnd,
                    'payment_reference' => $paymentReference,
                    'metadata' => $metadata,
                ]);
            }

            // Log event
            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'event_type' => $oldState ? 'renewed' : 'activated',
                'old_state' => $oldState,
                'new_state' => 'active',
                'actor_id' => $actor?->id,
                'actor_role' => $actor ? 'admin' : 'pay',
                'reason' => 'Paiement confirmé avec référence '.$paymentReference,
                'payload' => [
                    'price_id' => $price->id,
                    'amount' => $price->price_amount,
                    'cadence' => $price->cadence,
                    'payment_reference' => $paymentReference,
                ],
            ]);

            // Sync badge on User model
            $this->badgeService->syncUserBadge($user, 'subscription', $actor, 'Activation de l\'abonnement '.$product->name);

            return $subscription;
        });
    }

    /**
     * Calculate plan change prorata and prepare request.
     *
     * @return array<string, mixed>
     */
    public function calculatePlanChange(Subscription $subscription, SubscriptionPrice $newPrice): array
    {
        $now = now();
        $currentEnd = $subscription->current_period_end;
        $currentStart = $subscription->current_period_start;

        $totalDays = max(1, $currentStart->diffInDays($currentEnd));
        $remainingDays = max(0, $now->diffInDays($currentEnd, false));

        // Unused fraction of the current subscription
        $unusedRatio = min(1.0, max(0.0, $remainingDays / $totalDays));
        $prorataCredit = round((float) $subscription->amount * $unusedRatio, 2);

        $newAmount = (float) $newPrice->price_amount;
        $amountToPay = max(0.0, $newAmount - $prorataCredit);
        $durationDays = $newPrice->duration_days ?: ($newPrice->cadence === 'yearly' ? 365 : 30);
        $newPeriodEnd = $now->copy()->addDays($durationDays);

        return [
            'current_subscription_id' => $subscription->id,
            'current_cadence' => $subscription->cadence,
            'new_cadence' => $newPrice->cadence,
            'new_price_id' => $newPrice->id,
            'current_amount' => (float) $subscription->amount,
            'new_amount' => $newAmount,
            'remaining_days' => (int) round($remainingDays),
            'prorata_credit_xaf' => $prorataCredit,
            'amount_to_pay_xaf' => $amountToPay,
            'currency' => $newPrice->currency,
            'new_period_end' => $newPeriodEnd->toIso8601String(),
        ];
    }

    /**
     * Encaisse le prorata (via credit_wallet) et bascule l'abonnement sur la nouvelle formule et la nouvelle échéance.
     *
     * @return array{subscription: Subscription, transaction: PaymentTransaction, calculation: array<string, mixed>, replayed: bool}
     */
    public function confirmPlanChange(
        User $user,
        Subscription $subscription,
        SubscriptionPrice $newPrice,
        string $idempotencyKey
    ): array {
        return DB::transaction(function () use ($user, $subscription, $newPrice, $idempotencyKey) {
            /** @var Subscription $lockedSub */
            $lockedSub = Subscription::where('id', $subscription->id)
                ->with(['product', 'price'])
                ->lockForUpdate()
                ->firstOrFail();

            // Rejeu idempotent si une transaction confirmée existe déjà avec cette clé
            $existingTx = PaymentTransaction::where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('status', PaymentStatus::Confirmed)
                ->first();

            if ($existingTx) {
                return [
                    'subscription' => $lockedSub->fresh(['product', 'price']),
                    'transaction' => $existingTx,
                    'calculation' => $this->calculatePlanChange($lockedSub, $newPrice),
                    'replayed' => true,
                ];
            }

            $calculation = $this->calculatePlanChange($lockedSub, $newPrice);
            $requiredAmount = (int) round((float) $calculation['amount_to_pay_xaf']);

            $wallet = $user->creditWallet()->lockForUpdate()->first();
            if ($requiredAmount > 0 && (! $wallet || ! $wallet->canAfford($requiredAmount))) {
                throw ValidationException::withMessages([
                    'credit_wallet' => ['Solde de crédits BANGO insuffisant pour régler le prorata du changement de formule.'],
                ]);
            }

            $pricingService = app(PricingService::class);
            $paymentEngine = app(PaymentEngineService::class);

            $now = now();
            $durationDays = $newPrice->duration_days ?: ($newPrice->cadence === 'yearly' ? 365 : 30);
            $newPeriodEnd = $now->copy()->addDays($durationDays);

            $quote = $pricingService->calculateQuote(
                serviceType: PaymentServiceType::Subscription,
                priceId: $newPrice->id,
                customAmount: (float) $requiredAmount,
                gateway: 'wallet'
            );

            $transaction = $paymentEngine->createOrRetrieveTransaction(
                user: $user,
                quote: $quote,
                idempotencyKey: $idempotencyKey,
                context: [
                    'subscription_id' => $lockedSub->id,
                    'channel' => PaymentChannel::CreditWallet->value,
                    'context' => 'plan_change',
                    'old_cadence' => $lockedSub->cadence,
                    'new_cadence' => $newPrice->cadence,
                    'prorata_credit_xaf' => $calculation['prorata_credit_xaf'],
                    'custom_period_end' => $newPeriodEnd->toISOString(),
                ]
            );

            if ($requiredAmount > 0 && $wallet) {
                $balanceBefore = (int) $wallet->balance;
                $wallet->decrement('balance', $requiredAmount);
                $balanceAfter = (int) $wallet->balance;

                CreditTransaction::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'type' => CreditTransactionType::Debit,
                    'amount' => -$requiredAmount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => "Changement de formule d'abonnement ({$lockedSub->cadence} -> {$newPrice->cadence}) ({$transaction->reference})",
                    'reference' => $transaction->reference,
                    'created_at' => $now,
                ]);
            }

            $attempt = $transaction->createAttempt(
                provider: PaymentProvider::Wallet,
                channel: PaymentChannel::CreditWallet,
                externalReference: 'WALLET-'.Str::upper(Str::random(10)),
                metadata: [
                    'debited_amount' => $requiredAmount,
                    'wallet_id' => $wallet?->id,
                    'prorata_credit_xaf' => $calculation['prorata_credit_xaf'],
                ]
            );
            $attempt->markSuccessful($attempt->external_reference, ['debited' => $requiredAmount]);

            $transaction->provider = PaymentProvider::Wallet;
            $transaction->channel = PaymentChannel::CreditWallet;
            $transaction->fee_amount = '0.00';
            $transaction->total_amount = (string) $requiredAmount;
            $transaction->amount_charged = (string) $requiredAmount;
            $transaction->credit_used = $requiredAmount;
            $transaction->external_reference = $attempt->external_reference;
            $transaction->transitionTo(
                targetStatus: PaymentStatus::Processing,
                source: PaymentEventSource::System,
                reason: 'Débit du portefeuille de crédits BANGO (changement de formule)',
                payload: ['debited' => $requiredAmount]
            );

            $confirmedTx = $paymentEngine->confirmAndFulfill(
                transaction: $transaction,
                externalReference: $attempt->external_reference,
                source: PaymentEventSource::System,
                fulfillmentDetails: [
                    'debited' => $requiredAmount,
                    'plan_change' => true,
                    'old_cadence' => $calculation['current_cadence'],
                    'new_cadence' => $newPrice->cadence,
                ]
            );

            return [
                'subscription' => $lockedSub->fresh(['product', 'price']),
                'transaction' => $confirmedTx,
                'calculation' => $calculation,
                'replayed' => false,
            ];
        });
    }

    /**
     * Cancel auto-renewal for a subscription.
     */
    public function cancelSubscription(Subscription $subscription, ?string $reason = null, ?string $feedback = null, ?User $actor = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $reason, $feedback, $actor) {
            $subscription->update([
                'auto_renew' => false,
                'canceled_at' => now(),
                'cancel_reason' => $reason,
                'cancel_feedback' => $feedback,
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'event_type' => 'canceled',
                'old_state' => $subscription->state,
                'new_state' => $subscription->state,
                'actor_id' => $actor?->id ?? $subscription->user_id,
                'actor_role' => $actor && $actor->isAdmin() ? 'admin' : 'user',
                'reason' => $reason,
                'payload' => [
                    'feedback' => $feedback,
                    'expires_at' => $subscription->current_period_end->toIso8601String(),
                ],
            ]);

            return $subscription;
        });
    }

    /**
     * Resume auto-renewal for a canceled subscription before expiration.
     */
    public function resumeSubscription(Subscription $subscription, ?User $actor = null): Subscription
    {
        if (! $subscription->current_period_end || $subscription->current_period_end->isPast()) {
            throw new \DomainException('Impossible de réactiver la reconduction automatique : cet abonnement est expiré.');
        }

        return DB::transaction(function () use ($subscription, $actor) {
            $subscription->update([
                'auto_renew' => true,
                'next_renewal' => $subscription->current_period_end,
                'canceled_at' => null,
                'cancel_reason' => null,
                'cancel_feedback' => null,
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'event_type' => 'resumed',
                'old_state' => $subscription->state,
                'new_state' => $subscription->state,
                'actor_id' => $actor?->id ?? $subscription->user_id,
                'actor_role' => $actor && $actor->isAdmin() ? 'admin' : 'user',
                'reason' => 'Reprise de la reconduction automatique',
                'payload' => [
                    'next_renewal' => $subscription->current_period_end->toIso8601String(),
                    'expires_at' => $subscription->current_period_end->toIso8601String(),
                ],
            ]);

            return $subscription;
        });
    }

    /**
     * Transition a subscription to grace period (7 days) after a renewal failure or expiration.
     */
    public function enterGracePeriod(
        Subscription $subscription,
        ?string $failureReason = null,
        ?bool $incrementFailedAttempts = null
    ): Subscription {
        return DB::transaction(function () use ($subscription, $failureReason, $incrementFailedAttempts) {
            $graceDays = (int) config('subscription.grace_period_days', 7);
            $graceUntil = now()->addDays($graceDays);
            $oldState = $subscription->state;

            // Ne compter un échec (failed_attempts + 1) QUE si un prélèvement a été explicitement tenté et a échoué
            $shouldIncrement = $incrementFailedAttempts ?? (
                $failureReason !== null
                && $failureReason !== 'Échéance passée sans renouvellement'
                && ! str_starts_with($failureReason, 'Échéance dépassée')
                && (str_contains(mb_strtolower($failureReason), 'échec')
                    || str_contains(mb_strtolower($failureReason), 'echec')
                    || str_contains(mb_strtolower($failureReason), 'fail')
                    || str_contains(mb_strtolower($failureReason), 'refus')
                    || str_contains(mb_strtolower($failureReason), 'insuffisant'))
            );

            $failedAttempts = $shouldIncrement
                ? ($subscription->failed_attempts + 1)
                : $subscription->failed_attempts;

            $reason = $failureReason ?? ($shouldIncrement ? 'Échec du prélèvement à l\'échéance' : 'Échéance passée sans renouvellement');

            $subscription->update([
                'state' => 'grace',
                'grace_until' => $graceUntil,
                'failed_attempts' => $failedAttempts,
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'event_type' => 'grace_entered',
                'old_state' => $oldState,
                'new_state' => 'grace',
                'actor_role' => 'system',
                'reason' => $reason,
                'payload' => [
                    'grace_until' => $graceUntil->toIso8601String(),
                    'attempt' => $failedAttempts,
                ],
            ]);

            return $subscription;
        });
    }

    /**
     * Expire a subscription after grace period ended.
     */
    public function expireSubscription(Subscription $subscription, string $reason = 'Échéance dépassée sans régularisation'): Subscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $oldState = $subscription->state;

            $subscription->update([
                'state' => 'expired',
                'grace_until' => null,
                'auto_renew' => false,
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'event_type' => 'expired',
                'old_state' => $oldState,
                'new_state' => 'expired',
                'actor_role' => 'system',
                'reason' => $reason,
            ]);

            // Re-evaluate badge status
            $this->badgeService->syncUserBadge($subscription->user, 'system', null, $reason);

            // Si c'est un abonnement rattaché à une Page (ex: page_pro / certification)
            if ($subscription->page_id && $subscription->page) {
                $subscription->page->update([
                    'certification_status' => PageCertificationStatus::Lapsed,
                ]);
            }

            return $subscription;
        });
    }

    /**
     * Administrative actions.
     */
    public function adminSuspend(Subscription $subscription, User $admin, string $reason): Subscription
    {
        return DB::transaction(function () use ($subscription, $admin, $reason) {
            $oldState = $subscription->state;

            $subscription->update([
                'state' => 'suspended',
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'event_type' => 'suspended',
                'old_state' => $oldState,
                'new_state' => 'suspended',
                'actor_id' => $admin->id,
                'actor_role' => 'admin',
                'reason' => $reason,
            ]);

            $this->badgeService->syncUserBadge($subscription->user, 'admin', $admin, "Suspension administrative : {$reason}");

            return $subscription;
        });
    }

    public function adminReactivate(Subscription $subscription, User $admin, string $reason): Subscription
    {
        return DB::transaction(function () use ($subscription, $admin, $reason) {
            $oldState = $subscription->state;

            $subscription->update([
                'state' => 'active',
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'event_type' => 'reactivated',
                'old_state' => $oldState,
                'new_state' => 'active',
                'actor_id' => $admin->id,
                'actor_role' => 'admin',
                'reason' => $reason,
            ]);

            $this->badgeService->syncUserBadge($subscription->user, 'admin', $admin, "Réactivation administrative : {$reason}");

            return $subscription;
        });
    }

    public function adminExtend(Subscription $subscription, int $days, User $admin, string $reason): Subscription
    {
        return DB::transaction(function () use ($subscription, $days, $admin, $reason) {
            $newEnd = $subscription->current_period_end->copy()->addDays($days);

            $subscription->update([
                'current_period_end' => $newEnd,
                'next_renewal' => $newEnd,
                'state' => 'active',
                'grace_until' => null,
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'event_type' => 'extended',
                'old_state' => $subscription->state,
                'new_state' => 'active',
                'actor_id' => $admin->id,
                'actor_role' => 'admin',
                'reason' => $reason,
                'payload' => [
                    'added_days' => $days,
                    'new_end' => $newEnd->toIso8601String(),
                ],
            ]);

            $this->badgeService->syncUserBadge($subscription->user, 'admin', $admin, "Prolongation de {$days} jours : {$reason}");

            return $subscription;
        });
    }
}
