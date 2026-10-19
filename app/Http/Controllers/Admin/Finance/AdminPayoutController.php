<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Models\Payment\LandlordPayout;
use App\Services\Admin\Finance\AdminPayoutService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPayoutController extends Controller
{
    public function __construct(
        protected AdminPayoutService $payoutService
    ) {}

    /**
     * 4.5. GET /api/admin/payments/payouts
     * Liste paginée des reversements aux bailleurs.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'landlord_id', 'reference', 'from', 'to', 'q']);
        $perPage = $request->integer('per_page', 20);

        $paginator = $this->payoutService->getPayouts($filters, $perPage);

        $items = collect($paginator->items())->map(function (LandlordPayout $payout) {
            return [
                'id' => (string) $payout->id,
                'reference' => $payout->reference,
                'landlord' => $payout->landlord ? [
                    'id' => $payout->landlord->id,
                    'name' => $payout->landlord->name,
                    'email' => $payout->landlord->email,
                    'phone' => $payout->landlord->phone,
                    'badge' => $payout->landlord->badge,
                ] : null,
                'visit_reservation' => $payout->visitReservation ? [
                    'id' => $payout->visitReservation->id,
                    'listing_title' => $payout->visitReservation->listing?->title,
                    'city' => $payout->visitReservation->listing?->city,
                ] : null,
                'amount' => (float) $payout->amount,
                'currency' => $payout->currency,
                'status' => $payout->status,
                'scheduled_at' => $payout->scheduled_at?->toIso8601String(),
                'executed_at' => $payout->executed_at?->toIso8601String(),
                'provider' => $payout->provider,
                'channel' => $payout->channel,
                'external_reference' => $payout->external_reference,
                'attempts_count' => $payout->attempts_count,
                'last_error' => $payout->last_error,
                'created_at' => $payout->created_at->toIso8601String(),
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
     * 4.6. POST /api/admin/payments/{id}/payout/retry
     * Relance d'un reversement échoué ou bloqué.
     */
    public function retry(Request $request, string $id): JsonResponse
    {
        /** @var LandlordPayout|null $payout */
        $payout = LandlordPayout::where('id', $id)->orWhere('reference', $id)->first();
        if (! $payout) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'PAYOUT_NOT_FOUND', 'message' => 'Reversement introuvable.'],
            ], 404);
        }

        try {
            $updated = $this->payoutService->retryPayout($payout, $request->user());
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'PAYOUT_RETRY_FAILED', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (string) $updated->id,
                'reference' => $updated->reference,
                'status' => $updated->status,
                'attempts_count' => $updated->attempts_count,
                'executed_at' => $updated->executed_at?->toIso8601String(),
                'external_reference' => $updated->external_reference,
            ],
            'message' => 'Reversement relancé et exécuté avec succès.',
        ]);
    }
}
