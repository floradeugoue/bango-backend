<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\RefundDecisionRequest;
use App\Http\Requests\Admin\Finance\RefundRequestCreateRequest;
use App\Models\Payment\PaymentRefundRequest;
use App\Models\Payment\PaymentTransaction;
use App\Services\Admin\Finance\AdminRefundService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRefundController extends Controller
{
    public function __construct(
        protected AdminRefundService $refundService
    ) {}

    /**
     * 4.11. GET /api/admin/payments/refund-approvers
     * Retourner les opérateurs actuellement habilités à approuver les remboursements.
     */
    public function approvers(Request $request): JsonResponse
    {
        $approvers = $this->refundService->getRefundApprovers();

        return response()->json([
            'success' => true,
            'data' => $approvers,
            'message' => 'Opérateurs habilités à l\'approbation récupérés avec succès.',
        ]);
    }

    /**
     * 4.12. POST /api/admin/payments/{id}/refund
     * Créer une demande de remboursement initiée par un premier opérateur.
     * Cette route NE DÉCLENCHE PAS le transfert financier (double validation requise).
     */
    public function createRequest(RefundRequestCreateRequest $request, string $id): JsonResponse
    {
        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::where('id', $id)->orWhere('reference', $id)->first();
        if (! $transaction) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => 'Transaction introuvable.'],
            ], 404);
        }

        try {
            $refundRequest = $this->refundService->createRefundRequest(
                transaction: $transaction,
                requester: $request->user(),
                amount: (float) $request->input('amount'),
                reason: $request->input('reason')
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'REFUND_REQUEST_FAILED', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $refundRequest->id,
                'reference' => $refundRequest->reference,
                'transaction_id' => $refundRequest->transaction_id,
                'transaction_reference' => $transaction->reference,
                'amount' => (float) $refundRequest->amount,
                'currency' => $refundRequest->currency,
                'status' => $refundRequest->status,
                'requester' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                ],
                'second_approval_required' => true,
                'created_at' => $refundRequest->created_at->toIso8601String(),
            ],
            'message' => 'Demande de remboursement initiée avec succès. En attente de la seconde approbation.',
        ], 201);
    }

    /**
     * 4.13. POST /api/admin/payments/{id}/refund/decision
     * Décision du second opérateur : Approuver ou Rejeter.
     * Invariant : Le demandeur initial ne peut pas approuver sa propre demande.
     */
    public function decision(RefundDecisionRequest $request, string $id): JsonResponse
    {
        // $id peut être soit l'id de transaction, soit l'id ou référence de PaymentRefundRequest
        /** @var PaymentRefundRequest|null $refundRequest */
        $refundRequest = PaymentRefundRequest::where('id', $id)
            ->orWhere('reference', $id)
            ->orWhere(function ($q) use ($id) {
                $q->where('transaction_id', $id)
                    ->where('status', PaymentRefundRequest::STATUS_PENDING_APPROVAL);
            })
            ->latest('id')
            ->first();

        if (! $refundRequest) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'REFUND_REQUEST_NOT_FOUND', 'message' => 'Aucune demande de remboursement en attente trouvée pour cet identifiant.'],
            ], 404);
        }

        try {
            $updated = $this->refundService->decideRefundRequest(
                refundRequest: $refundRequest,
                decider: $request->user(),
                decision: $request->input('decision'),
                reason: $request->input('reason')
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DECISION_NOT_ALLOWED', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $updated->id,
                'reference' => $updated->reference,
                'status' => $updated->status,
                'decision' => $updated->status,
                'decision_reason' => $updated->decision_reason,
                'requester_id' => $updated->requester_id,
                'approver_id' => $updated->approver_id,
                'decided_at' => $updated->decided_at?->toIso8601String(),
                'amount' => (float) $updated->amount,
                'currency' => $updated->currency,
            ],
            'message' => $updated->isApproved()
                ? 'Remboursement approuvé et exécuté avec succès par le second opérateur.'
                : 'Demande de remboursement rejetée.',
        ]);
    }

    /**
     * 4.14. POST /api/admin/payments/{id}/refund/withdraw
     * Permettre au demandeur de retirer sa demande en attente.
     */
    public function withdraw(Request $request, string $id): JsonResponse
    {
        /** @var PaymentRefundRequest|null $refundRequest */
        $refundRequest = PaymentRefundRequest::where('id', $id)
            ->orWhere('reference', $id)
            ->orWhere(function ($q) use ($id) {
                $q->where('transaction_id', $id)
                    ->where('status', PaymentRefundRequest::STATUS_PENDING_APPROVAL);
            })
            ->latest('id')
            ->first();

        if (! $refundRequest) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'REFUND_REQUEST_NOT_FOUND', 'message' => 'Demande de remboursement introuvable ou déjà clôturée.'],
            ], 404);
        }

        try {
            $withdrawn = $this->refundService->withdrawRefundRequest($refundRequest, $request->user());
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'WITHDRAW_NOT_ALLOWED', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $withdrawn->id,
                'reference' => $withdrawn->reference,
                'status' => $withdrawn->status,
                'withdrawn_at' => $withdrawn->withdrawn_at?->toIso8601String(),
            ],
            'message' => 'Demande de remboursement retirée avec succès.',
        ]);
    }
}
