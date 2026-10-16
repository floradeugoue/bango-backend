<?php

namespace App\Http\Controllers\Credits;

use App\Http\Controllers\Controller;
use App\Http\Resources\Credits\CreditTransactionResource;
use App\Services\Boost\CreditWalletService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreditTransactionController extends Controller
{
    public function __construct(
        protected CreditWalletService $creditWalletService
    ) {}

    /**
     * Historique paginé et filtrable des mouvements du grand livre de crédits.
     *
     * GET /api/credits/transactions
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $transactions = $this->creditWalletService->getTransactions(
            user: $request->user(),
            perPage: min(100, max(1, (int) $request->input('per_page', 15))),
            type: $request->input('type'),
            source: $request->input('source'),
            startDate: $request->input('start_date'),
            endDate: $request->input('end_date')
        );

        return CreditTransactionResource::collection($transactions);
    }
}
