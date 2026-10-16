<?php

namespace App\Http\Controllers\Boost;

use App\Enums\Boost\CreditTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Boost\DepositCreditRequest;
use App\Http\Resources\Boost\CreditTransactionResource;
use App\Http\Resources\Boost\CreditWalletResource;
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
     * Dépôt / recharge de crédits BANGO.
     *
     * POST /api/boost/wallet/deposit
     */
    public function deposit(DepositCreditRequest $request): JsonResponse
    {
        $transaction = $this->walletService->credit(
            account: $request->user(),
            amount: $request->input('amount'),
            type: CreditTransactionType::Deposit,
            description: $request->input('description', 'Recharge directe de crédits BANGO'),
            idempotencyKey: $request->input('idempotency_key')
        );

        return (new CreditTransactionResource($transaction))
            ->response()
            ->setStatusCode(201);
    }
}
