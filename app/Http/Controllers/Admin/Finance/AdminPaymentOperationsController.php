<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\CloseIncidentRequest;
use App\Http\Requests\Admin\Finance\CompensatoryCreditRequest;
use App\Models\Payment\PaymentTransaction;
use App\Services\Admin\Finance\AdminFinanceService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentOperationsController extends Controller
{
    public function __construct(
        protected AdminFinanceService $financeService
    ) {}

    /**
     * 4.1. GET /api/admin/payments
     * Fournir une liste paginée des transactions financières avec filtres et compteurs.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status', 'provider', 'service_type', 'currency', 'user_id',
            'reference', 'from', 'to', 'q', 'sort_by', 'sort_order',
        ]);
        $perPage = $request->integer('per_page', 20);

        $result = $this->financeService->getTransactions($filters, $perPage);
        $paginator = $result['paginator'];

        return response()->json([
            // Contract format: Page<T>
            'items' => $paginator->items(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'pageCount' => $paginator->lastPage(),

            // Legacy keys for backward compatibility
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'status_counts' => $result['status_counts'],
            ],
        ]);
    }

    /**
     * 4.2. GET /api/admin/payments/{id}
     * Retourner le détail d'une transaction financière avec chronologie expurgée des secrets.
     */
    public function show(string $id): JsonResponse
    {
        $detail = $this->financeService->getTransactionDetail($id);

        if (! $detail) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TRANSACTION_NOT_FOUND',
                    'message' => 'Transaction introuvable.',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $detail,
            'message' => 'Détails de la transaction récupérés avec succès.',
        ]);
    }

    /**
     * 4.3. GET /api/admin/payments/credits
     * Exposer le grand livre des Crédits BANGO et les soldes actifs.
     */
    public function credits(Request $request): JsonResponse
    {
        $filters = $request->only(['type', 'source', 'user_id', 'from', 'to', 'q']);
        $perPage = $request->integer('per_page', 20);

        $paginator = $this->financeService->getCreditsLedger($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 4.4. GET /api/admin/payments/operators
     * Monitoring des providers de paiement existants (Monetbil, MTN MoMo, Orange Money).
     */
    public function operators(Request $request): JsonResponse
    {
        $monitoring = $this->financeService->getOperatorsMonitoring();

        return response()->json([
            'success' => true,
            'data' => $monitoring,
        ]);
    }

    /**
     * 4.8. GET /api/admin/payments/subscriptions
     * Supervision des abonnements de badges et périodes de grâce.
     */
    public function subscriptions(Request $request): JsonResponse
    {
        $filters = $request->only(['state', 'badge_type', 'user_id', 'in_grace', 'q']);
        $perPage = $request->integer('per_page', 20);

        $paginator = $this->financeService->getSubscriptions($filters, $perPage);

        $items = collect($paginator->items())->map(function ($sub) {
            $inGrace = $sub->state === 'grace' && $sub->grace_until && $sub->grace_until->isFuture();

            return [
                'id' => $sub->id,
                'user' => $sub->user ? [
                    'id' => $sub->user->id,
                    'name' => $sub->user->name,
                    'email' => $sub->user->email,
                    'phone' => $sub->user->phone,
                    'badge' => $sub->user->badge,
                ] : null,
                'product' => $sub->product ? [
                    'code' => $sub->product->code,
                    'name' => $sub->product->name,
                ] : null,
                'price' => $sub->price ? [
                    'cadence' => $sub->price->cadence,
                    'amount' => (float) ($sub->price->price_amount ?? $sub->price->amount),
                    'price_amount' => (float) ($sub->price->price_amount ?? $sub->price->amount),
                    'currency' => $sub->price->currency,
                ] : null,
                'badge_type' => $sub->badge_type,
                'state' => $sub->state,
                'current_period_start' => $sub->current_period_start?->toIso8601String(),
                'current_period_end' => $sub->current_period_end?->toIso8601String(),
                'next_renewal' => $sub->next_renewal?->toIso8601String(),
                'auto_renew' => (bool) $sub->auto_renew,
                'is_in_grace' => $inGrace,
                'grace_until' => $sub->grace_until?->toIso8601String(),
                'failed_attempts' => $sub->failed_attempts,
                'canceled_at' => $sub->canceled_at?->toIso8601String(),
                'cancel_reason' => $sub->cancel_reason,
                'payment_reference' => $sub->payment_reference,
                'created_at' => $sub->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 4.9. POST /api/admin/payments/{id}/close
     * Clôturer un incident de paiement sans mouvement financier.
     */
    public function close(CloseIncidentRequest $request, string $id): JsonResponse
    {
        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::where('id', $id)->orWhere('reference', $id)->first();
        if (! $transaction) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => 'Transaction introuvable.'],
            ], 404);
        }

        $result = $this->financeService->closeIncident($transaction, $request->user(), $request->input('reason'));

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Incident de paiement clôturé avec succès sans mouvement financier.',
        ]);
    }

    /**
     * 4.10. POST /api/admin/payments/{id}/credit
     * Émission manuelle compensatoire de Crédits BANGO.
     */
    public function credit(CompensatoryCreditRequest $request, string $id): JsonResponse
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
            $creditTx = $this->financeService->issueCompensatoryCredit(
                transaction: $transaction,
                operator: $request->user(),
                amount: (int) $request->input('amount'),
                reason: $request->input('reason'),
                idempotencyKey: $request->input('idempotency_key')
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CREDIT_ISSUANCE_FAILED', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'credit_transaction_id' => $creditTx->id,
                'reference' => $creditTx->reference,
                'amount' => $creditTx->amount,
                'balance_after' => $creditTx->balance_after,
                'type' => $creditTx->type->value,
                'user_id' => $creditTx->user_id,
                'created_at' => $creditTx->created_at?->toIso8601String(),
            ],
            'message' => 'Crédit compensatoire émis avec succès dans le grand livre officiel.',
        ]);
    }

    /**
     * 4.15. POST /api/admin/payments/{id}/check
     * Déclencher un rapprochement ciblé d'une transaction auprès du provider.
     */
    public function check(Request $request, string $id): JsonResponse
    {
        /** @var PaymentTransaction|null $transaction */
        $transaction = PaymentTransaction::where('id', $id)->orWhere('reference', $id)->first();
        if (! $transaction) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'TRANSACTION_NOT_FOUND', 'message' => 'Transaction introuvable.'],
            ], 404);
        }

        $result = $this->financeService->checkTransaction($transaction);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Rapprochement ciblé exécuté avec succès.',
        ]);
    }

    /**
     * 4.16. POST /api/admin/payments/resync
     * Rapprochement groupé et contrôlé des transactions éligibles.
     */
    public function resync(Request $request): JsonResponse
    {
        $stats = $this->financeService->resyncTransactions();

        return response()->json([
            'success' => true,
            'data' => $stats,
            'message' => 'Resynchronisation groupée terminée avec succès.',
        ]);
    }

    /**
     * 4.17. GET /api/admin/payments/{id}/receipt
     * Fournir le reçu officiel d'une transaction avec signature HMAC-SHA256.
     */
    public function receipt(Request $request, string $id): JsonResponse
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
            $receipt = $this->financeService->getTransactionReceipt($transaction);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'RECEIPT_NOT_AVAILABLE', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $receipt,
            'message' => 'Reçu officiel généré avec signature cryptographique d\'intégrité.',
        ]);
    }
}
