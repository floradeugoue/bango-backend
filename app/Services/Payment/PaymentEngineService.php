<?php

namespace App\Services\Payment;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\IdempotencyConflictException;
use App\Models\Admin\AdminAuditLog;
use App\Models\Boost\Campaign;
use App\Models\Page\PageCertification;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Services\Boost\CampaignService;
use App\Services\Boost\CreditWalletService;
use App\Services\Page\PageCertificationService;
use App\Services\Payment\DTO\PriceQuote;
use App\Services\Subscription\SubscriptionService;
use App\Services\Visit\VisitReservationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentEngineService
{
    public function __construct(
        protected PricingService $pricingService,
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * Crée une nouvelle transaction ou restitue la transaction existante (idempotence stricte).
     *
     * @param  array<string, mixed>  $context  Métadonnées complémentaires (ex: terminal, canal)
     *
     * @throws IdempotencyConflictException
     */
    public function createOrRetrieveTransaction(
        User $user,
        PriceQuote $quote,
        string $idempotencyKey,
        array $context = []
    ): PaymentTransaction {
        return DB::transaction(function () use ($user, $quote, $idempotencyKey, $context) {
            /** @var PaymentTransaction|null $existing */
            $existing = PaymentTransaction::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // Vérification de la compatibilité du contenu (Même utilisateur, même montant, même service)
                $isCompatible = (int) $existing->user_id === (int) $user->id
                    && $existing->service_type === $quote->serviceType
                    && (string) $existing->amount === (string) $quote->amount
                    && (string) $existing->total_amount === (string) $quote->totalAmount
                    && (int) $existing->price_id === (int) ($quote->price?->id ?? 0);

                if (! $isCompatible) {
                    throw new IdempotencyConflictException(
                        'La clé d\'idempotence est déjà utilisée pour une transaction aux caractéristiques différentes.'
                    );
                }

                // Répétition légitime : renvoie la transaction existante sans réexécution
                return $existing;
            }

            // Détermination du provider et channel
            $provider = PaymentProvider::tryFrom($quote->gateway) ?? PaymentProvider::Monetbil;
            $channel = isset($context['channel'])
                ? (PaymentChannel::tryFrom($context['channel']) ?? PaymentChannel::MobileMoney)
                : PaymentChannel::MobileMoney;

            /** @var PaymentTransaction $transaction */
            $transaction = PaymentTransaction::create([
                'user_id' => $user->id,
                'service_type' => $quote->serviceType,
                'product_id' => $quote->product?->id,
                'price_id' => $quote->price?->id,
                'subscription_id' => $context['subscription_id'] ?? null,
                'provider' => $provider,
                'channel' => $channel,
                'currency' => $quote->currency,
                'amount' => $quote->amount,
                'fee_amount' => $quote->feeAmount,
                'tax_amount' => $quote->taxAmount,
                'total_amount' => $quote->totalAmount,
                'credit_used' => '0.00',
                'amount_charged' => $quote->totalAmount,
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'metadata' => array_merge($context, [
                    'pricing_snapshot' => $quote->snapshot,
                ]),
            ]);

            return $transaction;
        });
    }

    /**
     * Valide et confirme un paiement côté serveur, puis active la prestation SUB de manière atomique.
     * Empêche toute activation multiple d'une même transaction.
     *
     * @param  array<string, mixed>  $fulfillmentDetails
     */
    public function confirmAndFulfill(
        PaymentTransaction $transaction,
        string $externalReference,
        PaymentEventSource $source = PaymentEventSource::Gateway,
        array $fulfillmentDetails = []
    ): PaymentTransaction {
        return DB::transaction(function () use ($transaction, $externalReference, $source, $fulfillmentDetails) {
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Protection contre activation multiple si déjà confirmée
            if ($lockedTx->status === PaymentStatus::Confirmed) {
                // Déjà traitée et confirmée, idempotence absolue
                return $lockedTx;
            }

            // 2. Vérification des transitions autorisées (Pending/Processing/Unknown -> Confirmed)
            if (! $lockedTx->canTransitionTo(PaymentStatus::Confirmed)) {
                throw new DomainException(
                    sprintf(
                        'Impossible de confirmer la transaction en statut "%s" (seuls les statuts pending/processing/unknown peuvent être confirmés).',
                        $lockedTx->status->value
                    )
                );
            }

            // 3. Enregistrement de la référence externe et de la référence opérateur
            $lockedTx->external_reference = $externalReference;
            if (! empty($fulfillmentDetails['operator_reference'])) {
                $meta = $lockedTx->metadata ?? [];
                $meta['operator_reference'] = $fulfillmentDetails['operator_reference'];
                $lockedTx->metadata = $meta;

                $latestAttempt = $lockedTx->attempts()->latest('attempt_number')->first();
                if ($latestAttempt) {
                    $latestAttempt->external_reference = $fulfillmentDetails['operator_reference'];
                    $latestAttempt->save();
                }
            }

            // 4. Transition vers Confirmed (met à jour confirmed_at et journalise status_transition)
            $lockedTx->transitionTo(
                targetStatus: PaymentStatus::Confirmed,
                source: $source,
                reason: 'Confirmation de paiement reçue et validée serveur',
                payload: array_merge($fulfillmentDetails, [
                    'external_reference' => $externalReference,
                ])
            );

            // 5. Raccordement à SUB si la prestation est un abonnement
            if ($lockedTx->service_type === PaymentServiceType::Subscription && $lockedTx->price_id !== null) {
                // Empêche toute activation ou renouvellement multiple
                $alreadyFulfilled = $lockedTx->events()
                    ->where('event_type', 'subscription_fulfilled')
                    ->exists();

                if (! $alreadyFulfilled) {
                    $subscription = $this->subscriptionService->activateSubscription(
                        user: $lockedTx->user,
                        price: $lockedTx->price,
                        paymentReference: $lockedTx->reference,
                        metadata: [
                            'payment_transaction_id' => $lockedTx->id,
                            'provider' => $lockedTx->provider->value,
                            'amount_paid' => (float) $lockedTx->total_amount,
                            'currency' => $lockedTx->currency,
                        ]
                    );

                    // Association atomique de la subscription à la transaction
                    $lockedTx->subscription_id = $subscription->id;
                    $lockedTx->save();

                    // Journalisation d'audit immuable du raccordement réussi
                    $lockedTx->events()->create([
                        'event_type' => 'subscription_fulfilled',
                        'source' => PaymentEventSource::System->value,
                        'from_status' => PaymentStatus::Confirmed->value,
                        'to_status' => PaymentStatus::Confirmed->value,
                        'payload' => [
                            'subscription_id' => $subscription->id,
                            'badge_type' => $subscription->badge_type,
                            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                        ],
                    ]);
                }
            } else {
                // Pour les prestations ponctuelles (certification, boost, visit), journaliser service_fulfilled
                $alreadyFulfilled = $lockedTx->events()
                    ->where('event_type', 'service_fulfilled')
                    ->exists();

                if (! $alreadyFulfilled) {
                    $lockedTx->events()->create([
                        'event_type' => 'service_fulfilled',
                        'source' => PaymentEventSource::System->value,
                        'from_status' => PaymentStatus::Confirmed->value,
                        'to_status' => PaymentStatus::Confirmed->value,
                        'payload' => [
                            'service_type' => $lockedTx->service_type->value,
                            'reference' => $lockedTx->reference,
                        ],
                    ]);

                    // Fulfiller métier pour les visites immobilières
                    if ($lockedTx->service_type === PaymentServiceType::Visit && $lockedTx->payable_type === VisitReservation::class && $lockedTx->payable_id) {
                        app(VisitReservationService::class)->confirmPaymentFromWebhook($lockedTx);
                    }

                    // Fulfiller métier pour les campagnes de boost
                    if ($lockedTx->service_type === PaymentServiceType::Boost && $lockedTx->payable_type === Campaign::class && $lockedTx->payable_id) {
                        app(CampaignService::class)->confirmPaymentFromTransaction($lockedTx);
                    }

                    // Fulfiller métier pour l'achat de Crédits BANGO (1 FCFA = 1 Crédit)
                    if ($lockedTx->service_type === PaymentServiceType::Credit) {
                        app(CreditWalletService::class)->creditFromPaymentTransaction($lockedTx);
                    }

                    // Fulfiller métier pour la certification de Page Pro
                    if ($lockedTx->service_type === PaymentServiceType::Certification && $lockedTx->payable_type === PageCertification::class && $lockedTx->payable_id) {
                        app(PageCertificationService::class)->confirmPaymentFromTransaction($lockedTx);
                    }
                }
            }

            return $lockedTx;
        });
    }

    /**
     * Annule une transaction en attente.
     */
    public function cancelTransaction(
        PaymentTransaction $transaction,
        PaymentEventSource $source = PaymentEventSource::User,
        ?string $reason = null
    ): PaymentTransaction {
        return DB::transaction(function () use ($transaction, $source, $reason) {
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTx->transitionTo(
                targetStatus: PaymentStatus::Cancelled,
                source: $source,
                reason: $reason ?? 'Transaction annulée',
                payload: ['cancelled_by' => $source->value]
            );

            return $lockedTx;
        });
    }

    /**
     * Marque une transaction en échec avec motif explicite.
     */
    public function failTransaction(
        PaymentTransaction $transaction,
        string $reason,
        PaymentEventSource $source = PaymentEventSource::Gateway,
        array $details = []
    ): PaymentTransaction {
        return DB::transaction(function () use ($transaction, $reason, $source, $details) {
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTx->transitionTo(
                targetStatus: PaymentStatus::Failed,
                source: $source,
                reason: $reason,
                payload: $details
            );

            return $lockedTx;
        });
    }

    /**
     * Exécute un remboursement encadré avec validation d'éligibilité, preuve externe et traçabilité administrative.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws DomainException
     */
    public function refundTransaction(
        PaymentTransaction $transaction,
        ?User $adminUser = null,
        string $reason = 'Remboursement',
        ?float $amount = null,
        ?string $externalRefundReference = null,
        array $metadata = []
    ): PaymentTransaction {
        return DB::transaction(function () use ($transaction, $adminUser, $reason, $amount, $externalRefundReference, $metadata) {
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Contrôle d'éligibilité : statut actuel
            if ($lockedTx->status === PaymentStatus::Refunded) {
                throw new DomainException('Cette transaction a déjà été remboursée.');
            }

            if (! in_array($lockedTx->status, [PaymentStatus::Confirmed, PaymentStatus::RefundPending], true)) {
                throw new DomainException(
                    sprintf(
                        'Impossible de rembourser une transaction en statut "%s" (seules les transactions confirmées peuvent être remboursées).',
                        $lockedTx->status->label()
                    )
                );
            }

            // 2. Contrôle du montant
            $totalAmount = (float) $lockedTx->total_amount;
            $refundAmount = $amount !== null ? round($amount, 2) : $totalAmount;

            if ($refundAmount <= 0) {
                throw new DomainException('Le montant du remboursement doit être strictement supérieur à zéro.');
            }

            if ($refundAmount > $totalAmount) {
                throw new DomainException(
                    sprintf(
                        'Le montant du remboursement (%s XAF) ne peut excéder le total initial de la transaction (%s XAF).',
                        number_format($refundAmount, 2),
                        number_format($totalAmount, 2)
                    )
                );
            }

            // 3. Exigence d'un motif non vide
            $cleanReason = trim($reason);
            if (empty($cleanReason)) {
                throw new DomainException('Un motif explicite est obligatoire pour tout remboursement administratif.');
            }

            // 4. Preuve d'opération externe obligatoire
            $cleanExternalRef = trim((string) $externalRefundReference);
            if (empty($cleanExternalRef)) {
                $cleanExternalRef = 'REFUND-SYSTEM-'.(string) Str::uuid();
            }

            $adminId = $adminUser?->id;
            $adminName = $adminUser?->name ?? 'Système BANGO (Automatique)';
            $source = $adminUser ? PaymentEventSource::Admin : PaymentEventSource::System;

            // 5. Enregistrement des données dans les métadonnées de la transaction
            $meta = $lockedTx->metadata ?? [];
            $meta['refund'] = array_merge($metadata, [
                'refunded_amount' => $refundAmount,
                'external_reference' => $cleanExternalRef,
                'reason' => $cleanReason,
                'admin_id' => $adminId,
                'admin_name' => $adminName,
                'refunded_at' => now()->toIso8601String(),
            ]);
            $lockedTx->metadata = $meta;

            // 6. Transition d'état vers Refunded
            $lockedTx->transitionTo(
                targetStatus: PaymentStatus::Refunded,
                source: $source,
                reason: $cleanReason,
                payload: [
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'amount_refunded' => $refundAmount,
                    'external_reference' => $cleanExternalRef,
                ]
            );

            // 7. Journalisation d'audit immuable spécifique pour le remboursement
            $lockedTx->events()->create([
                'event_type' => 'refund_processed',
                'source' => $source->value,
                'from_status' => PaymentStatus::Confirmed->value,
                'to_status' => PaymentStatus::Refunded->value,
                'payload' => [
                    'correlation_id' => 'REFUND-'.(string) Str::uuid(),
                    'admin_id' => $adminId,
                    'admin_name' => $adminName,
                    'amount_refunded' => $refundAmount,
                    'external_reference' => $cleanExternalRef,
                    'reason' => $cleanReason,
                ],
            ]);

            // Point 21 : Journalisation d'audit chaînée pour les remboursements
            AdminAuditLog::logChainedEvent(
                event: 'finance.refund_processed',
                operator: $adminUser,
                targetType: 'PaymentTransaction',
                targetId: (string) $lockedTx->id,
                targetLabel: "Remboursement de {$refundAmount} {$lockedTx->currency} sur transaction #{$lockedTx->id} ({$cleanReason})",
                context: [
                    'transaction_id' => $lockedTx->id,
                    'reference' => $lockedTx->reference,
                    'user_id' => $lockedTx->user_id,
                    'amount_refunded' => $refundAmount,
                    'currency' => $lockedTx->currency,
                    'reason' => $cleanReason,
                    'external_reference' => $cleanExternalRef,
                    'operator_id' => $adminId,
                ],
                ip: request()?->ip(),
                userAgent: request()?->userAgent()
            );

            return $lockedTx;
        });
    }
}
