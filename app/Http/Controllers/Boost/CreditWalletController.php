<?php

namespace App\Http\Controllers\Boost;

use App\Enums\Boost\CreditTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boost\DepositCreditRequest;
use App\Http\Resources\Boost\CreditTransactionResource;
use App\Http\Resources\Boost\CreditWalletResource;
use App\Models\Admin\AdminAuditLog;
use App\Models\User;
use App\Services\Boost\CreditWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreditWalletController extends Controller
{
    public function __construct(
        protected CreditWalletService $walletService
    ) {}

    /**
     * Consultation du solde et informations du portefeuille Crédit BANGO.
     *
     * GET /api/boost/wallet
     */
    public function show(Request $request): CreditWalletResource
    {
        $wallet = $this->walletService->getOrCreateWallet($request->user());
        $wallet->load(['transactions' => fn ($q) => $q->orderByDesc('created_at')->take(10)]);

        return new CreditWalletResource($wallet);
    }

    /**
     * Historique paginé des écritures comptables du grand livre (ledger).
     *
     * GET /api/boost/wallet/transactions
     */
    public function transactions(Request $request): AnonymousResourceCollection
    {
        $transactions = $this->walletService->getTransactions(
            user: $request->user(),
            perPage: (int) $request->input('per_page', 20)
        );

        return CreditTransactionResource::collection($transactions);
    }

    /**
     * Dépôt / recharge directe de crédits BANGO.
     * STRICTEMENT RÉSERVÉ AU BACK-OFFICE avec motif obligatoire et journal d'audit.
     *
     * POST /api/boost/wallet/deposit
     */
    public function deposit(DepositCreditRequest $request): JsonResponse
    {
        $operator = $request->user();

        // 1. Refus strict pour les utilisateurs standards non-opérateurs
        if (! $operator || ! $operator->isInternalOperator()) {
            return response()->json([
                'message' => 'Les dépôts directs de crédits sans passerelle de paiement sont strictement réservés au back-office avec motif obligatoire.',
                'code' => 'DIRECT_DEPOSIT_FORBIDDEN',
            ], 403);
        }

        // 2. Motif obligatoire pour la traçabilité financière
        $reason = $request->input('reason');
        if (empty($reason) || strlen(trim($reason)) < 5) {
            return response()->json([
                'message' => 'Un motif administratif détaillé (au moins 5 caractères) est obligatoire pour tout dépôt direct de crédits.',
                'code' => 'REASON_REQUIRED',
            ], 422);
        }

        $targetUser = $request->filled('user_id')
            ? User::findOrFail($request->input('user_id'))
            : $operator;

        $transaction = $this->walletService->credit(
            account: $targetUser,
            amount: (float) $request->input('amount'),
            type: CreditTransactionType::Deposit,
            description: "Recharge administrative : {$reason}",
            idempotencyKey: $request->input('idempotency_key')
        );

        AdminAuditLog::logChainedEvent(
            event: 'finance.wallet_manual_credit',
            operator: $operator,
            targetType: 'User',
            targetId: (string) $targetUser->id,
            targetLabel: "Crédit manuel de {$request->input('amount')} crédits BANGO à {$targetUser->name} ({$targetUser->email})",
            context: [
                'amount' => (float) $request->input('amount'),
                'reason' => $reason,
                'target_user_id' => $targetUser->id,
                'balance_before' => $transaction->balance_before,
                'balance_after' => $transaction->balance_after,
            ]
        );

        return (new CreditTransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Endpoint d'administration pour recharger manuellement un portefeuille utilisateur.
     *
     * POST /api/admin/finance/wallets/{user}/deposit
     */
    public function adminDeposit(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $operator = $request->user();

        $transaction = $this->walletService->credit(
            account: $user,
            amount: (float) $validated['amount'],
            type: CreditTransactionType::Deposit,
            description: "Recharge manuelle par l'administration : {$validated['reason']}",
            idempotencyKey: $validated['idempotency_key'] ?? null
        );

        AdminAuditLog::logChainedEvent(
            event: 'finance.wallet_manual_credit',
            operator: $operator,
            targetType: 'User',
            targetId: (string) $user->id,
            targetLabel: "Crédit manuel de {$validated['amount']} crédits BANGO à {$user->name} ({$user->email})",
            context: [
                'amount' => (float) $validated['amount'],
                'reason' => $validated['reason'],
                'target_user_id' => $user->id,
                'balance_before' => $transaction->balance_before,
                'balance_after' => $transaction->balance_after,
            ]
        );

        return (new CreditTransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }
}
