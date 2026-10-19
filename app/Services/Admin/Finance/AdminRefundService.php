<?php

namespace App\Services\Admin\Finance;

use App\Enums\Payment\PaymentStatus;
use App\Models\Auth\Role;
use App\Models\Payment\PaymentRefundRequest;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\PaymentEngineService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminRefundService
{
    public function __construct(
        protected PaymentEngineService $paymentEngineService
    ) {}

    /**
     * 4.11. Opérateurs actuellement habilités à approuver les remboursements.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRefundApprovers(): array
    {
        $eligibleRoles = Role::whereIn('slug', ['admin', 'direction', 'finance'])->pluck('id');

        return User::whereIn('role_id', $eligibleRoles)
            ->where('status', 'active')
            ->get(['id', 'name', 'email', 'role_id', 'status'])
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->slug ?? 'finance',
                    'role_label' => $user->role?->name ?? 'Finance',
                    'effective_permissions' => ['finance:approve', 'finance:action'],
                    'is_active' => $user->status === 'active',
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * 4.12. Création d'une demande de remboursement soumise à double validation.
     * Ne déclenche aucun transfert financier immédiat.
     */
    public function createRefundRequest(
        PaymentTransaction $transaction,
        User $requester,
        float $amount,
        string $reason
    ): PaymentRefundRequest {
        if ($transaction->status !== PaymentStatus::Confirmed) {
            throw new DomainException(
                sprintf('Impossible de demander un remboursement pour une transaction en statut "%s". Seules les transactions confirmées sont éligibles.', $transaction->status->label())
            );
        }

        $totalAmount = (float) $transaction->total_amount;
        $refundAmount = round($amount, 2);

        if ($refundAmount <= 0) {
            throw new DomainException('Le montant du remboursement doit être strictement supérieur à zéro.');
        }

        if ($refundAmount > $totalAmount) {
            throw new DomainException(
                sprintf('Le montant demandé (%s %s) dépasse le total de la transaction (%s %s).', $refundAmount, $transaction->currency, $totalAmount, $transaction->currency)
            );
        }

        // Vérifier s'il existe déjà une demande en attente
        $existingPending = PaymentRefundRequest::where('transaction_id', $transaction->id)
            ->where('status', PaymentRefundRequest::STATUS_PENDING_APPROVAL)
            ->exists();

        if ($existingPending) {
            throw new DomainException('Une demande de remboursement est déjà en attente d\'approbation pour cette transaction.');
        }

        $reference = 'REQ-REF-'.strtoupper(Str::random(10));

        return PaymentRefundRequest::create([
            'reference' => $reference,
            'transaction_id' => $transaction->id,
            'requester_id' => $requester->id,
            'status' => PaymentRefundRequest::STATUS_PENDING_APPROVAL,
            'amount' => $refundAmount,
            'currency' => $transaction->currency,
            'reason' => trim($reason),
            'metadata' => [
                'requester_name' => $requester->name,
                'requester_email' => $requester->email,
                'created_via' => 'admin_finance_double_approval',
            ],
        ]);
    }

    /**
     * 4.13. Décision du second opérateur : Approbation ou Rejet.
     * Invariant strict : Le demandeur initial NE PEUT PAS approuver sa propre demande.
     */
    public function decideRefundRequest(
        PaymentRefundRequest $refundRequest,
        User $decider,
        string $decision,
        ?string $reason = null
    ): PaymentRefundRequest {
        return DB::transaction(function () use ($refundRequest, $decider, $decision, $reason) {
            /** @var PaymentRefundRequest $lockedRequest */
            $lockedRequest = PaymentRefundRequest::where('id', $refundRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isPending()) {
                throw new DomainException(
                    sprintf('Cette demande de remboursement a déjà été traitée (statut actuel : %s).', $lockedRequest->status)
                );
            }

            // RÈGLE ABSOLUE DE DOUBLE VALIDATION : Le demandeur ne peut pas approuver sa propre demande
            if ((int) $lockedRequest->requester_id === (int) $decider->id) {
                throw new DomainException(
                    'Principe des quatre yeux (double approbation) : L\'opérateur ayant initié la demande de remboursement ne peut pas la valider lui-même.'
                );
            }

            $cleanDecision = strtolower(trim($decision));
            if (! in_array($cleanDecision, ['approved', 'rejected'], true)) {
                throw new DomainException('La décision doit être soit "approved", soit "rejected".');
            }

            if ($cleanDecision === 'rejected') {
                $lockedRequest->status = PaymentRefundRequest::STATUS_REJECTED;
                $lockedRequest->approver_id = $decider->id;
                $lockedRequest->decision_reason = $reason ?? 'Rejeté par le superviseur Finance';
                $lockedRequest->decided_at = now();
                $lockedRequest->save();

                return $lockedRequest;
            }

            // Décision : APPROVED -> Exécution effective du remboursement
            /** @var PaymentTransaction $transaction */
            $transaction = PaymentTransaction::where('id', $lockedRequest->transaction_id)
                ->lockForUpdate()
                ->firstOrFail();

            $externalRef = 'REFUND-'.$lockedRequest->reference;

            $this->paymentEngineService->refundTransaction(
                transaction: $transaction,
                adminUser: $decider,
                reason: $lockedRequest->reason,
                amount: (float) $lockedRequest->amount,
                externalRefundReference: $externalRef,
                metadata: [
                    'refund_request_id' => $lockedRequest->id,
                    'refund_request_reference' => $lockedRequest->reference,
                    'requester_id' => $lockedRequest->requester_id,
                    'approver_id' => $decider->id,
                    'decision_reason' => $reason ?? 'Approuvé par le second opérateur Finance',
                ]
            );

            $lockedRequest->status = PaymentRefundRequest::STATUS_APPROVED;
            $lockedRequest->approver_id = $decider->id;
            $lockedRequest->decision_reason = $reason ?? 'Approuvé par le second opérateur Finance';
            $lockedRequest->decided_at = now();
            $lockedRequest->save();

            return $lockedRequest;
        });
    }

    /**
     * 4.14. Retrait d'une demande de remboursement par le demandeur initial.
     */
    public function withdrawRefundRequest(PaymentRefundRequest $refundRequest, User $actor): PaymentRefundRequest
    {
        return DB::transaction(function () use ($refundRequest, $actor) {
            /** @var PaymentRefundRequest $lockedRequest */
            $lockedRequest = PaymentRefundRequest::where('id', $refundRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isPending()) {
                throw new DomainException(
                    sprintf('Seule une demande en attente peut être retirée (statut actuel : %s).', $lockedRequest->status)
                );
            }

            $isRequester = (int) $lockedRequest->requester_id === (int) $actor->id;
            $isAdmin = $actor->isAdmin();

            if (! $isRequester && ! $isAdmin) {
                throw new DomainException('Seul le demandeur initial ou un administrateur peut retirer cette demande.');
            }

            $lockedRequest->status = PaymentRefundRequest::STATUS_WITHDRAWN;
            $lockedRequest->withdrawn_at = now();
            $lockedRequest->save();

            return $lockedRequest;
        });
    }
}
