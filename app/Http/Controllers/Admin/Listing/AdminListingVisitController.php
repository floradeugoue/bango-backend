<?php

namespace App\Http\Controllers\Admin\Listing;

use App\Http\Controllers\Controller;
use App\Models\Visit\VisitReservation;
use App\Services\Admin\AdminListingControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminListingVisitController extends Controller
{
    public function __construct(
        protected AdminListingControlService $listingService
    ) {}

    /**
     * B.9. Suivi global des visites réservées avec état du délai de réponse (SLA 18 heures).
     */
    public function visits(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'listing_id', 'overdue_sla', 'q']);
        $perPage = $request->integer('per_page', 20);

        $reservations = $this->listingService->getVisits($filters, $perPage);

        $items = collect($reservations->items())->map(function (VisitReservation $vr) {
            $statusVal = $vr->status instanceof \BackedEnum ? $vr->status->value : (string) $vr->status;
            $isRequested = $statusVal === 'requested';
            $hoursWaiting = $vr->created_at ? (int) $vr->created_at->diffInHours(now()) : 0;
            $isOverdue = $isRequested && $hoursWaiting >= 18;

            return [
                'id' => $vr->id,
                'listing' => $vr->listing ? [
                    'id' => $vr->listing->id,
                    'title' => $vr->listing->title,
                    'city' => $vr->listing->city,
                    'neighbourhood' => $vr->listing->neighbourhood,
                    'price' => (float) $vr->listing->price,
                ] : null,
                'prospect' => $vr->prospect ? [
                    'id' => $vr->prospect->id,
                    'name' => $vr->prospect->name,
                    'email' => $vr->prospect->email,
                    'phone' => $vr->prospect->phone,
                ] : null,
                'owner' => $vr->owner ? [
                    'id' => $vr->owner->id,
                    'name' => $vr->owner->name,
                    'phone' => $vr->owner->phone,
                    'badge' => $vr->owner->badge,
                ] : null,
                'agent' => $vr->agent ? [
                    'id' => $vr->agent->id,
                    'name' => $vr->agent->name,
                ] : null,
                'slot' => $vr->slot ? [
                    'id' => $vr->slot->id,
                    'starts_at' => $vr->slot->starts_at?->toISOString(),
                    'ends_at' => $vr->slot->ends_at?->toISOString(),
                ] : null,
                'status' => $vr->status->value,
                'fee' => (float) $vr->fee,
                'currency' => $vr->currency,
                'hours_waiting' => $hoursWaiting,
                'is_overdue_sla_18h' => $isOverdue,
                'answered_at' => $vr->answered_at?->toISOString(),
                'decline_reason' => $vr->decline_reason,
                'paid_at' => $vr->paid_at?->toISOString(),
                'created_at' => $vr->created_at->toISOString(),
            ];
        });

        return response()->json([
            // Contract format: Page<T>
            'items' => $items,
            'page' => $reservations->currentPage(),
            'perPage' => $reservations->perPage(),
            'total' => $reservations->total(),
            'pageCount' => $reservations->lastPage(),

            // Legacy keys for backward compatibility
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $reservations->currentPage(),
                'per_page' => $reservations->perPage(),
                'total' => $reservations->total(),
                'last_page' => $reservations->lastPage(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->visits($request);
    }
}
