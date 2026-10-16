<?php

namespace App\Http\Controllers\Credits;

use App\Http\Controllers\Controller;
use App\Http\Resources\Credits\CreditWalletResource;
use App\Services\Boost\CreditWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditWalletController extends Controller
{
    public function __construct(
        protected CreditWalletService $creditWalletService
    ) {}

    /**
     * Consultation du portefeuille Crédit BANGO de l'utilisateur authentifié.
     *
     * GET /api/credits/wallet
     */
    public function show(Request $request): JsonResponse
    {
        $wallet = $this->creditWalletService->getOrCreateWallet($request->user());
        $wallet->load(['transactions' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id')->take(5)]);

        return (new CreditWalletResource($wallet))
            ->response()
            ->setStatusCode(200);
    }
}
