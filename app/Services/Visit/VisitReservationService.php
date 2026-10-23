<?php

namespace App\Services\Visit;

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Visit\VisitDeclineReason;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Exceptions\Payment\PaymentNotFoundException;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Visit\VisitCancelledNotification;
use App\Notifications\Visit\VisitConfirmedNotification;
use App\Notifications\Visit\VisitDeclinedNotification;
use App\Notifications\Visit\VisitProposedNotification;
use App\Notifications\Visit\VisitRequestedNotification;
use App\Services\Boost\CreditWalletService;
use App\Services\Listing\ListingService;
use App\Services\Payment\Gateways\MonetbilGateway;
use App\Services\Payment\PaymentEngineService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VisitReservationService
{
    public function __construct(
        protected ListingService $listingService,
        protected VisitSlotService $slotService,
        protected PaymentEngineService $paymentEngineService,
        protected MonetbilGateway $monetbilGateway,
        protected ?CreditWalletService $creditWalletService = null
    ) {
        $this->creditWalletService ??= app(CreditWalletService::class);
    }

    /**
     * Calcule le devis des frais de visite pour une annonce et un créneau.
     *
     * @return array{
     *     listing_id: string,
     *     listing_title: string,
     *     slot_id: string,
     *     scheduled_at: string,
     *     fee_amount: float,
     *     currency: string,
     *     deposit_required: bool,
     *     total_amount: float,
     *     cancellation_policy: string
     * }
     */
    public function getQuote(Listing $listing, VisitSlot $slot): array
    {
        $fee = (float) ($listing->visit_fee ?? 0);
        $currency = $listing->currency ?? 'XAF';

        return [
            'listing_id' => $listing->id,
            'listing_title' => $listing->title,
            'slot_id' => $slot->id,
            'scheduled_at' => $slot->start_at->toIso8601String(),
            'fee_amount' => $fee,
            'currency' => $currency,
            'deposit_required' => $fee > 0,
            'total_amount' => $fee,
            'cancellation_policy' => 'Remboursement intégral en cas d\'annulation plus de 24h avant la visite ou refus du bailleur.',
        ];
    }

    /**
     * Réserve un créneau de visite avec acquisition atomique d'un verrou de 15 minutes
     * et initialisation d'une transaction PAY dédiée.
     *
     * @param  array{
     *     slot_id: string,
     *     message?: ?string,
     *     channel?: ?string,
     *     operator?: ?string,
     *     phone_number?: ?string,
     *     idempotency_key?: ?string
     * }  $payload
     * @return array{
     *     reservation: VisitReservation,
     *     transaction: ?PaymentTransaction,
     *     quote: array<string, mixed>
     * }
     */
    public function bookVisit(Listing $listing, VisitSlot $slot, User $prospect, array $payload): array
    {
        // 1. Contrôles d'éligibilité et appartenance
        if ($prospect->id === $listing->owner_id || ($listing->agent_id && $prospect->id === $listing->agent_id)) {
            throw ValidationException::withMessages([
                'listing' => ['Vous ne pouvez pas réserver une visite pour votre propre bien.'],
            ]);
        }

        if ($slot->listing_id !== $listing->id) {
            throw ValidationException::withMessages([
                'slot_id' => ['Le créneau sélectionné n\'appartient pas à cette annonce.'],
            ]);
        }

        // 2. Acquisition atomique du créneau et vérification de capacité
        return DB::transaction(function () use ($listing, $slot, $prospect, $payload) {
            /** @var VisitSlot $lockedSlot */
            $lockedSlot = VisitSlot::where('id', $slot->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedSlot->status !== VisitSlotStatus::Open) {
                throw ValidationException::withMessages([
                    'slot_id' => ['Ce créneau n\'est plus ouvert aux réservations.'],
                ]);
            }

            if ($lockedSlot->start_at->isPast()) {
                throw ValidationException::withMessages([
                    'slot_id' => ['Ce créneau est déjà expiré.'],
                ]);
            }

            // Calcul strict des réservations concurrentes actives
            $activeHolds = VisitReservation::where('slot_id', $lockedSlot->id)
                ->where(function ($q) {
                    $q->whereIn('status', [
                        VisitReservationStatus::Requested->value,
                        VisitReservationStatus::Confirmed->value,
                        VisitReservationStatus::Proposed->value,
                    ])->orWhere(function ($sub) {
                        $sub->where('status', VisitReservationStatus::PendingPayment->value)
                            ->where('lock_expires_at', '>', now());
                    });
                })
                ->count();

            if ($activeHolds >= $lockedSlot->capacity) {
                throw ValidationException::withMessages([
                    'slot_id' => ['Ce créneau n\'est plus disponible (capacité atteinte ou en cours de règlement).'],
                ]);
            }

            // Vérifier que le prospect n'a pas déjà un hold actif sur ce créneau
            $hasExistingHold = VisitReservation::where('slot_id', $lockedSlot->id)
                ->where('prospect_id', $prospect->id)
                ->where(function ($q) {
                    $q->whereIn('status', [
                        VisitReservationStatus::Requested->value,
                        VisitReservationStatus::Confirmed->value,
                        VisitReservationStatus::Proposed->value,
                    ])->orWhere(function ($sub) {
                        $sub->where('status', VisitReservationStatus::PendingPayment->value)
                            ->where('lock_expires_at', '>', now());
                    });
                })
                ->exists();

            if ($hasExistingHold) {
                throw ValidationException::withMessages([
                    'slot_id' => ['Vous avez déjà une réservation ou un verrou actif sur ce créneau.'],
                ]);
            }

            $quote = $this->getQuote($listing, $lockedSlot);
            $feeAmount = $quote['fee_amount'];
            $isPaid = $feeAmount <= 0;

            // 3. Création de la réservation avec verrou de 15 minutes si payant
            /** @var VisitReservation $reservation */
            $reservation = VisitReservation::create([
                'listing_id' => $listing->id,
                'slot_id' => $lockedSlot->id,
                'prospect_id' => $prospect->id,
                'owner_id' => $listing->owner_id,
                'agent_id' => $listing->agent_id,
                'status' => $isPaid ? VisitReservationStatus::Requested : VisitReservationStatus::PendingPayment,
                'lock_expires_at' => $isPaid ? null : now()->addMinutes(15),
                'fee_amount' => $feeAmount,
                'currency' => $quote['currency'],
                'pricing_snapshot' => $quote,
                'prospect_message' => $payload['message'] ?? null,
                'prospect_phone' => $payload['phone_number'] ?? $prospect->phone,
                'paid_at' => $isPaid ? now() : null,
            ]);

            if ($isPaid) {
                $this->notifyVisitRequested($reservation);

                return [
                    'reservation' => $reservation,
                    'transaction' => null,
                    'quote' => $quote,
                ];
            }

            // 4. Paiement immédiat par portefeuille Crédit BANGO si demandé
            $isCreditPayment = ($payload['channel'] ?? null) === 'credit_wallet' || ($payload['payment_method'] ?? null) === 'credit';
            if ($isCreditPayment) {
                $creditsNeeded = (int) round($feeAmount);
                app(CreditWalletService::class)->debit(
                    account: $prospect,
                    amount: $creditsNeeded,
                    source: 'visit',
                    reference: 'VISIT-'.$reservation->id,
                    description: sprintf('Paiement visite annonce #%s', $listing->id),
                    idempotencyKey: 'visit_credit_debit_'.$reservation->id,
                    referenceModel: $reservation,
                    metadata: [
                        'listing_id' => $listing->id,
                        'slot_id' => $lockedSlot->id,
                        'reservation_id' => $reservation->id,
                    ]
                );

                $reservation->status = VisitReservationStatus::Requested;
                $reservation->paid_at = now();
                $reservation->payment_reference = 'VISIT-CREDIT-'.$reservation->id;
                $reservation->lock_expires_at = null;
                $reservation->save();

                $this->notifyVisitRequested($reservation);

                return [
                    'reservation' => $reservation,
                    'transaction' => null,
                    'quote' => $quote,
                ];
            }

            // 5. Initialisation de la transaction PAY Mobile Money
            $idempotencyKey = $payload['idempotency_key'] ?? ('VISIT-'.$reservation->id.'-'.Str::random(6));

            $transaction = PaymentTransaction::create([
                'user_id' => $prospect->id,
                'service_type' => PaymentServiceType::Visit,
                'payable_type' => VisitReservation::class,
                'payable_id' => $reservation->id,
                'provider' => PaymentProvider::Monetbil,
                'channel' => isset($payload['channel']) ? (PaymentChannel::tryFrom($payload['channel']) ?? PaymentChannel::MobileMoney) : PaymentChannel::MobileMoney,
                'currency' => $quote['currency'],
                'amount' => $feeAmount,
                'fee_amount' => '0.00',
                'tax_amount' => '0.00',
                'total_amount' => $feeAmount,
                'credit_used' => '0.00',
                'amount_charged' => $feeAmount,
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'reservation_id' => $reservation->id,
                    'listing_id' => $listing->id,
                    'slot_id' => $lockedSlot->id,
                    'operator' => $payload['operator'] ?? null,
                    'phone_number' => $payload['phone_number'] ?? $prospect->phone,
                    'scheduled_at' => $lockedSlot->start_at->toIso8601String(),
                ],
            ]);

            $reservation->payment_transaction_id = $transaction->id;
            $reservation->payment_reference = $transaction->reference;
            $reservation->save();

            // 6. Appel de la passerelle Monetbil pour déclencher le Push USSD sur le téléphone
            $phone = $payload['phone_number'] ?? $payload['phone'] ?? $prospect->phone;
            $operator = $payload['operator'] ?? null;

            $attempt = $transaction->createAttempt(
                provider: PaymentProvider::Monetbil,
                channel: $transaction->channel,
                externalReference: null,
                metadata: [
                    'phone' => $phone,
                    'operator' => $operator,
                ]
            );

            try {
                $initiateResult = $this->monetbilGateway->initiatePayment($transaction, [
                    'phone' => $phone,
                    'operator' => $operator,
                ]);

                if ($initiateResult->isSuccessful) {
                    $attempt->markSuccessful(
                        externalReference: $initiateResult->gatewayReference,
                        metadata: ['ussd_prompt' => $initiateResult->ussdPrompt]
                    );

                    if ($initiateResult->gatewayReference) {
                        $transaction->external_reference = $initiateResult->gatewayReference;
                        $transaction->save();
                    }
                } else {
                    $attempt->markFailed(
                        errorCode: $initiateResult->errorCode ?? 'GATEWAY_ERROR',
                        errorMessage: $initiateResult->errorMessage ?? 'Échec d\'initialisation du paiement.'
                    );
                }
            } catch (\Throwable $e) {
                $attempt->markFailed(
                    errorCode: 'GATEWAY_ERROR',
                    errorMessage: $e->getMessage()
                );
            }

            return [
                'reservation' => $reservation,
                'transaction' => $transaction->fresh(),
                'quote' => $quote,
            ];
        });
    }

    /**
     * Règle une réservation de visite en attente via le portefeuille Crédit BANGO.
     */
    public function payReservationWithCredits(VisitReservation $reservation, User $user): VisitReservation
    {
        if ((int) $reservation->prospect_id !== (int) $user->id) {
            throw new AuthorizationException('Vous ne pouvez pas régler la réservation d\'un tiers.');
        }

        if ($reservation->status !== VisitReservationStatus::PendingPayment) {
            throw ValidationException::withMessages([
                'reservation' => ['Cette réservation n\'est pas en attente de paiement (statut actuel : '.$reservation->status->value.').'],
            ]);
        }

        $feeAmount = (float) $reservation->fee_amount;
        if ($feeAmount <= 0) {
            $reservation->status = VisitReservationStatus::Confirmed;
            $reservation->paid_at = now();
            $reservation->save();

            return $reservation;
        }

        return DB::transaction(function () use ($reservation, $user, $feeAmount) {
            /** @var VisitReservation $lockedReservation */
            $lockedReservation = VisitReservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            /** @var VisitSlot $lockedSlot */
            $lockedSlot = VisitSlot::where('id', $lockedReservation->slot_id)->lockForUpdate()->firstOrFail();

            $creditsNeeded = (int) round($feeAmount);

            app(CreditWalletService::class)->debit(
                account: $user,
                amount: $creditsNeeded,
                source: 'visit',
                reference: 'VISIT-'.$lockedReservation->id,
                description: sprintf('Paiement visite réservation #%s', $lockedReservation->id),
                idempotencyKey: 'visit_credit_debit_'.$lockedReservation->id,
                referenceModel: $lockedReservation,
                metadata: [
                    'reservation_id' => $lockedReservation->id,
                    'listing_id' => $lockedReservation->listing_id,
                    'slot_id' => $lockedReservation->slot_id,
                ]
            );

            $lockedReservation->status = VisitReservationStatus::Requested;
            $lockedReservation->paid_at = now();
            $lockedReservation->payment_reference = 'VISIT-CREDIT-'.$lockedReservation->id;
            $lockedReservation->lock_expires_at = null;
            $lockedReservation->save();

            $this->notifyVisitRequested($lockedReservation);

            return $lockedReservation;
        });
    }

    /**
     * Confirmation de paiement déclenchée par le webhook IPN ou le fulfiller PaymentEngineService.
     */
    public function confirmPaymentFromWebhook(PaymentTransaction $transaction): VisitReservation
    {
        return DB::transaction(function () use ($transaction) {
            /** @var VisitReservation $reservation */
            $reservation = VisitReservation::where('id', $transaction->payable_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($reservation->status, [VisitReservationStatus::Confirmed, VisitReservationStatus::Requested], true)) {
                return $reservation; // Idempotent
            }

            /** @var VisitSlot $slot */
            $slot = VisitSlot::where('id', $reservation->slot_id)->lockForUpdate()->firstOrFail();

            // Gestion du paiement tardif après expiration du verrou
            $now = now();
            $isLockExpired = $reservation->lock_expires_at && $reservation->lock_expires_at->isPast();

            $activeHolds = VisitReservation::where('slot_id', $slot->id)
                ->where('id', '!=', $reservation->id)
                ->where(function ($q) {
                    $q->whereIn('status', [
                        VisitReservationStatus::Requested->value,
                        VisitReservationStatus::Confirmed->value,
                        VisitReservationStatus::Proposed->value,
                    ])->orWhere(function ($sub) {
                        $sub->where('status', VisitReservationStatus::PendingPayment->value)
                            ->where('lock_expires_at', '>', now());
                    });
                })
                ->count();

            // Si le verrou a expiré ET que la capacité est désormais saturée par un autre
            if ($isLockExpired && $activeHolds >= $slot->capacity) {
                // Paiement tardif avec conflit : mise en compensation / remboursement automatique
                $reservation->status = VisitReservationStatus::RefundPending;
                $reservation->paid_at = $now;
                $reservation->lock_expires_at = null;
                $reservation->save();

                $this->paymentEngineService->refundTransaction(
                    transaction: $transaction,
                    reason: 'Paiement reçu après expiration du verrou et créneau saturé (compensation automatique)',
                    amount: (float) $reservation->fee_amount,
                    externalRefundReference: 'REFUND-LATE-'.$reservation->id
                );

                $reservation->status = VisitReservationStatus::Refunded;
                $reservation->refund_amount = $reservation->fee_amount;
                $reservation->refunded_at = now();
                $reservation->refund_channel = 'bango_credit';
                $reservation->save();

                return $reservation;
            }

            // Validation normale du paiement
            $reservation->status = VisitReservationStatus::Requested;
            $reservation->paid_at = $now;
            $reservation->lock_expires_at = null;
            $reservation->save();

            $this->notifyVisitRequested($reservation);

            return $reservation;
        });
    }

    /**
     * Confirmation côté client : interroge et vérifie l'état réel de la transaction côté serveur.
     * Empêche toute confirmation frauduleuse non appuyée par une preuve de paiement.
     */
    public function confirmPaymentFromClient(VisitReservation $reservation, User $user): VisitReservation
    {
        if ($reservation->prospect_id !== $user->id) {
            throw new AuthorizationException('Vous n\'êtes pas le titulaire de cette réservation.');
        }

        if (in_array($reservation->status, [VisitReservationStatus::Confirmed, VisitReservationStatus::Requested], true)) {
            return $reservation;
        }

        /** @var PaymentTransaction|null $transaction */
        $transaction = $reservation->paymentTransaction;

        if (! $transaction) {
            throw new PaymentNotFoundException('Aucune transaction de paiement n\'est associée à cette réservation.');
        }

        // Si la transaction est déjà confirmée en base par le webhook
        if ($transaction->status === PaymentStatus::Confirmed) {
            return $this->confirmPaymentFromWebhook($transaction);
        }

        // Si la transaction a une référence externe Monetbil, synchroniser l'état
        if ($transaction->external_reference) {
            try {
                $statusResult = $this->monetbilGateway->checkStatus($transaction);
                if ($statusResult->status === PaymentStatus::Confirmed) {
                    $this->paymentEngineService->confirmAndFulfill(
                        transaction: $transaction,
                        externalReference: $transaction->external_reference,
                        source: PaymentEventSource::Gateway,
                        fulfillmentDetails: $statusResult->rawResponse
                    );

                    return $this->confirmPaymentFromWebhook($transaction);
                }
            } catch (\Throwable $e) {
                // Échec de synchronisation passerelle, maintien de l'état
            }
        }

        return $reservation;
    }

    /**
     * Traite la réponse du bailleur ou agent mandaté : accept, propose ou decline.
     */
    public function answerVisit(
        VisitReservation $reservation,
        User $actor,
        string $action,
        ?string $proposalSlotId = null,
        ?string $reason = null
    ): VisitReservation {
        if (! $this->listingService->canManageVisits($actor, $reservation->listing)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à gérer les visites pour ce bien (capacité \'visits\' requise).');
        }

        return DB::transaction(function () use ($reservation, $action, $proposalSlotId, $reason) {
            $locked = VisitReservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [VisitReservationStatus::Requested, VisitReservationStatus::Confirmed], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Cette visite ne peut plus être modifiée (statut actuel : '.$locked->status->value.').'],
                ]);
            }

            if ($action === 'accept') {
                if ($locked->status !== VisitReservationStatus::Confirmed) {
                    $locked->status = VisitReservationStatus::Confirmed;
                    $locked->slot->increment('booked_count');
                    $locked->save();
                    $this->notifyVisitConfirmed($locked);
                }

                return $locked;
            }

            if ($action === 'propose') {
                if (! $proposalSlotId) {
                    throw ValidationException::withMessages([
                        'proposal_slot_id' => ['Veuillez indiquer le créneau alternatif proposé.'],
                    ]);
                }

                $newSlot = VisitSlot::where('id', $proposalSlotId)
                    ->where('listing_id', $locked->listing_id)
                    ->first();

                if (! $newSlot || ! $newSlot->hasAvailableCapacity()) {
                    throw ValidationException::withMessages([
                        'proposal_slot_id' => ['Le créneau alternatif proposé n\'est pas disponible.'],
                    ]);
                }

                // Libérer l'ancien créneau si la visite était déjà confirmée
                if ($locked->status === VisitReservationStatus::Confirmed) {
                    $locked->slot->decrement('booked_count');
                }

                $locked->status = VisitReservationStatus::Proposed;
                $locked->proposal_slot_id = $newSlot->id;
                $locked->save();

                $locked->prospect->notify(new VisitProposedNotification($locked, $newSlot));

                return $locked;
            }

            if ($action === 'decline') {
                // Libérer l'ancien créneau si confirmé
                if ($locked->status === VisitReservationStatus::Confirmed) {
                    $locked->slot->decrement('booked_count');
                }

                $declineEnum = VisitDeclineReason::tryFrom((string) $reason) ?? VisitDeclineReason::Unavailable;
                $locked->status = VisitReservationStatus::Declined;
                $locked->decline_reason = $declineEnum;
                $locked->cancellation_reason = $reason;
                $locked->save();

                // Déclencher le remboursement intégral car le refus émane du bailleur
                $isCredit = str_starts_with((string) $locked->payment_reference, 'VISIT-CREDIT-');
                if ($locked->fee_amount > 0 && ($locked->paymentTransaction || $isCredit)) {
                    $this->processRefund($locked, 'Visite déclinée par le gestionnaire', VisitReservationStatus::Declined);
                }

                $locked->prospect->notify(new VisitDeclinedNotification($locked, $reason));

                return $locked;
            }

            throw ValidationException::withMessages([
                'action' => ['Action invalide (valeurs acceptées : accept, propose, decline).'],
            ]);
        });
    }

    /**
     * Réponse du prospect à une proposition de créneau alternatif.
     */
    public function respondToProposal(VisitReservation $reservation, User $prospect, bool $accept): VisitReservation
    {
        if ($reservation->prospect_id !== $prospect->id) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à modifier cette visite.');
        }

        return DB::transaction(function () use ($reservation, $accept) {
            $locked = VisitReservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VisitReservationStatus::Proposed || ! $locked->proposal_slot_id) {
                throw ValidationException::withMessages([
                    'status' => ['Cette visite n\'a pas de proposition en attente de réponse.'],
                ]);
            }

            $proposedSlot = VisitSlot::where('id', $locked->proposal_slot_id)->lockForUpdate()->firstOrFail();

            if ($accept) {
                if (! $proposedSlot->hasAvailableCapacity()) {
                    throw ValidationException::withMessages([
                        'proposal_slot_id' => ['Le créneau proposé n\'est plus disponible.'],
                    ]);
                }

                $locked->slot_id = $proposedSlot->id;
                $locked->proposal_slot_id = null;
                $locked->status = VisitReservationStatus::Confirmed;
                $locked->save();

                $proposedSlot->increment('booked_count');

                $this->notifyVisitConfirmed($locked);

                return $locked;
            }

            // Refus de la proposition par le prospect : annulation avec remboursement
            $locked->status = VisitReservationStatus::Cancelled;
            $locked->cancellation_reason = 'Proposition d\'horaire refusée par le prospect';
            $locked->cancelled_at = now();
            $locked->save();

            $isCredit = str_starts_with((string) $locked->payment_reference, 'VISIT-CREDIT-');
            if ($locked->fee_amount > 0 && ($locked->paymentTransaction || $isCredit)) {
                $this->processRefund($locked, 'Proposition de créneau refusée par le prospect');
            }

            $this->notifyVisitCancelled($locked, 'Proposition refusée par le prospect');

            return $locked;
        });
    }

    /**
     * Annule une visite et applique la politique de remboursement (IM-14 24h).
     */
    public function cancelVisit(VisitReservation $reservation, User $actor, ?string $reason = null): VisitReservation
    {
        $isProspect = $reservation->prospect_id === $actor->id;
        $isManager = $this->listingService->canManageVisits($actor, $reservation->listing);
        $isAdmin = $actor->isAdmin();

        if (! $isProspect && ! $isManager && ! $isAdmin) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à annuler cette visite.');
        }

        return DB::transaction(function () use ($reservation, $isProspect, $isManager, $reason) {
            $locked = VisitReservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [
                VisitReservationStatus::Cancelled,
                VisitReservationStatus::Declined,
                VisitReservationStatus::Expired,
                VisitReservationStatus::Refunded,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Cette visite est déjà clôturée ou annulée.'],
                ]);
            }

            $slot = $locked->slot;
            $wasConfirmed = $locked->status === VisitReservationStatus::Confirmed;

            // Décrémenter la capacité occupée si la visite était confirmée
            if ($wasConfirmed) {
                $slot->decrement('booked_count');
            }

            // Calcul de l'éligibilité au remboursement (IM-14)
            // Si annulé par le bailleur/gestionnaire -> remboursement 100%
            // Si annulé avant confirmation (PendingPayment/Requested/Proposed) -> remboursement 100%
            // Si annulé par le prospect pour une visite confirmée : remboursement si > 24h avant le début
            $isEligibleForRefund = false;
            if ($locked->fee_amount > 0 && $locked->paid_at) {
                if ($isManager || ! $wasConfirmed) {
                    $isEligibleForRefund = true;
                } else {
                    $hoursBeforeVisit = now()->diffInHours($slot->start_at, false);
                    $isEligibleForRefund = $hoursBeforeVisit >= 24;
                }
            }

            $locked->status = VisitReservationStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason ?? ($isProspect ? 'Annulé par le prospect' : 'Annulé par le gestionnaire');
            $locked->lock_expires_at = null;
            $locked->save();

            $isCredit = str_starts_with((string) $locked->payment_reference, 'VISIT-CREDIT-');
            if ($isEligibleForRefund && ($locked->paymentTransaction || $isCredit)) {
                $this->processRefund($locked, $locked->cancellation_reason);
            }

            $this->notifyVisitCancelled($locked, $reason);

            return $locked;
        });
    }

    protected function processRefund(
        VisitReservation $reservation,
        string $reason,
        ?VisitReservationStatus $targetStatus = VisitReservationStatus::Refunded
    ): void {
        $isCredit = str_starts_with((string) $reservation->payment_reference, 'VISIT-CREDIT-');
        $transaction = $reservation->paymentTransaction;

        if (! $transaction && ! $isCredit) {
            return;
        }

        if ($isCredit) {
            $prospect = $reservation->prospect ?? User::find($reservation->prospect_id);
            if ($prospect && $reservation->fee_amount > 0) {
                $this->creditWalletService->credit(
                    account: $prospect,
                    amount: (float) $reservation->fee_amount,
                    type: CreditTransactionType::Deposit,
                    description: sprintf('Remboursement visite annonce #%s', $reservation->listing_id),
                    idempotencyKey: 'REFUND-VISIT-'.$reservation->id,
                    reference: $reservation,
                    metadata: [
                        'reservation_id' => $reservation->id,
                        'listing_id' => $reservation->listing_id,
                        'reason' => $reason,
                    ],
                    source: 'visit_refund',
                    refCode: 'REFUND-VISIT-'.$reservation->id,
                );
            }
        } elseif ($transaction) {
            if ($transaction->status !== PaymentStatus::Refunded) {
                $this->paymentEngineService->refundTransaction(
                    transaction: $transaction,
                    reason: $reason,
                    amount: (float) $reservation->fee_amount,
                    externalRefundReference: 'REFUND-VISIT-'.$reservation->id
                );
            }
        }

        if ($targetStatus !== null) {
            $reservation->status = $targetStatus;
        }
        $reservation->refund_amount = $reservation->fee_amount;
        $reservation->refunded_at = now();
        $reservation->refund_channel = 'bango_credit';
        $reservation->save();
    }

    /**
     * Détecte et expire automatiquement les verrous de 15 minutes des réservations pending_payment.
     */
    public function expirePendingReservations(): int
    {
        $expired = VisitReservation::where('status', VisitReservationStatus::PendingPayment->value)
            ->whereNotNull('lock_expires_at')
            ->where('lock_expires_at', '<=', now())
            ->get();

        $count = 0;
        foreach ($expired as $reservation) {
            $reservation->status = VisitReservationStatus::Expired;
            $reservation->lock_expires_at = null;
            $reservation->save();
            $count++;
        }

        return $count;
    }

    /**
     * Traite les demandes de visite sans réponse après le délai configuré (BL-07 / EDGE) :
     * Décline automatiquement avec le motif no-answer et recrédite le prospect.
     */
    public function settleOverdueUnanswered(): int
    {
        $answerHours = (int) config('visits.answer_hours', 24);
        $deadline = now()->subHours($answerHours);

        $overdue = VisitReservation::where('status', VisitReservationStatus::Requested->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', $deadline)
            ->get();

        $count = 0;
        foreach ($overdue as $reservation) {
            $reservation->status = VisitReservationStatus::Declined;
            $reservation->decline_reason = VisitDeclineReason::NoAnswer->value;
            $reservation->save();

            $isCredit = str_starts_with((string) $reservation->payment_reference, 'VISIT-CREDIT-');
            if ($reservation->fee_amount > 0 && ($reservation->paymentTransaction || $isCredit)) {
                $this->processRefund(
                    $reservation,
                    sprintf('Expiration du délai de réponse bailleur (%dh)', $answerHours),
                    VisitReservationStatus::Declined
                );
            }

            $reservation->prospect->notify(new VisitDeclinedNotification(
                $reservation,
                sprintf('Délai de réponse de %dh dépassé', $answerHours)
            ));
            $count++;
        }

        return $count;
    }

    protected function notifyVisitRequested(VisitReservation $reservation): void
    {
        $reservation->owner->notify(new VisitRequestedNotification($reservation));
        if ($reservation->agent) {
            $reservation->agent->notify(new VisitRequestedNotification($reservation));
        }
    }

    protected function notifyVisitConfirmed(VisitReservation $reservation): void
    {
        $reservation->prospect->notify(new VisitConfirmedNotification($reservation));
        $reservation->owner->notify(new VisitConfirmedNotification($reservation));
        if ($reservation->agent) {
            $reservation->agent->notify(new VisitConfirmedNotification($reservation));
        }
    }

    protected function notifyVisitCancelled(VisitReservation $reservation, ?string $reason): void
    {
        $reservation->prospect->notify(new VisitCancelledNotification($reservation, $reason));
        $reservation->owner->notify(new VisitCancelledNotification($reservation, $reason));
        if ($reservation->agent) {
            $reservation->agent->notify(new VisitCancelledNotification($reservation, $reason));
        }
    }
}
