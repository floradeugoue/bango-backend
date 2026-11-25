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

    /**
     * Rembourse en argent sur le moyen de paiement d'origine le solde de crédits BANGO
     * (à la demande de l'utilisateur ou avant la suppression du compte).
     *
     * POST /api/credits/wallet/refund
     */
    public function refund(Request $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->creditWalletService->refundBalanceBeforeAccountDeletion(
            user: $user,
            throwOnValidation: true
        );

        if ($result === null) {
            return response()->json([
                'message' => 'Aucun solde de crédit disponible à rembourser.',
                'data' => [
                    'refunded' => false,
                    'amount' => 0.0,
                ],
            ], 422);
        }

        return response()->json([
            'message' => 'Votre solde de crédit a été remboursé en argent sur votre moyen de paiement d\'origine.',
            'data' => $result,
        ], 200);
    }
}
