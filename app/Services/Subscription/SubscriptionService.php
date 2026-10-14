<?php

namespace App\Services\Subscription;

use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionEvent;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\User;
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
            $periodEnd = $now->copy()->addDays($durationDays);

            // Find existing subscription for this badge type or create new
            $subscription = Subscription::where('user_id', $user->id)
                ->where('badge_type', $product->badge_type ?: $product->code)
                ->lockForUpdate()
                ->first();

            $oldState = $subscription?->state;

            if ($subscription) {
                // If currently active, extend from current_period_end instead of now
                if ($subscription->isActive()) {
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

        return [
            'current_subscription_id' => $subscription->id,
            'current_cadence' => $subscription->cadence,
            'new_cadence' => $newPrice->cadence,
            'current_amount' => (float) $subscription->amount,
            'new_amount' => $newAmount,
            'remaining_days' => $remainingDays,
            'prorata_credit_xaf' => $prorataCredit,
            'amount_to_pay_xaf' => $amountToPay,
            'currency' => $newPrice->currency,
        ];
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
     * Transition a subscription to grace period (7 days) after a renewal failure.
     */
    public function enterGracePeriod(Subscription $subscription, ?string $failureReason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $failureReason) {
            $graceUntil = now()->addDays(7);
            $oldState = $subscription->state;

            $subscription->update([
                'state' => 'grace',
                'grace_until' => $graceUntil,
                'failed_attempts' => $subscription->failed_attempts + 1,
            ]);

            SubscriptionEvent::create([
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'event_type' => 'grace_entered',
                'old_state' => $oldState,
                'new_state' => 'grace',
                'actor_role' => 'system',
                'reason' => $failureReason ?? 'Échec du prélèvement à l\'échéance',
                'payload' => [
                    'grace_until' => $graceUntil->toIso8601String(),
                    'attempt' => $subscription->failed_attempts,
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
