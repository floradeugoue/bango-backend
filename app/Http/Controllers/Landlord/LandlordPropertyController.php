<?php

namespace App\Http\Controllers\Landlord;

use App\Enums\Delegation\DelegationStatus;
use App\Enums\Listing\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\UpdateLandlordListingStatusRequest;
use App\Http\Requests\Landlord\UpdatePropertySlotsRequest;
use App\Http\Resources\Landlord\LandlordPropertyResource;
use App\Http\Resources\Visit\VisitReservationResource;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Visit\VisitReservation;
use App\Services\Listing\ListingService;
use App\Services\Visit\VisitSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LandlordPropertyController extends Controller
{
    public function __construct(
        protected ListingService $listingService,
        protected VisitSlotService $slotService
    ) {}

    /**
     * Tableau de bord des biens du bailleur (BL-01, BL-18).
     * GET /api/landlord/properties
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $landlord = $request->user();
        $perPage = $request->integer('per_page', 15);

        $paginator = $this->listingService->getLandlordProperties(
            $landlord,
            $request->query(),
            $perPage
        );

        return LandlordPropertyResource::collection($paginator);
    }

    /**
     * Changement de statut d'un bien (BL-18 : published, reserved, taken).
     * PATCH /api/landlord/properties/{id}/status
     */
    public function updateStatus(UpdateLandlordListingStatusRequest $request, string $id): JsonResponse
    {
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);
        $newStatus = ListingStatus::from($request->validated('status'));

        $updated = $this->listingService->updateListingStatus(
            $listing,
            $request->user(),
            $newStatus,
            $request->validated('reason')
        );

        return (new LandlordPropertyResource($updated))->response();
    }

    /**
     * Configuration globale ou synchronisation des créneaux de visite (BL-04 / BL-08).
     * PUT /api/landlord/properties/{id}/slots
     */
    public function updateSlots(UpdatePropertySlotsRequest $request, string $id): JsonResponse
    {
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        $result = $this->slotService->updateSlots(
            $listing,
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'message' => 'Créneaux de visite mis à jour avec succès.',
            'data' => $result,
        ]);
    }

    /**
     * Demandes de visite entrantes sur les biens du bailleur (BL-07).
     * GET /api/landlord/visit-requests
     */
    public function visitRequests(Request $request): AnonymousResourceCollection
    {
        $landlord = $request->user();

        $delegatedListingIds = Delegation::where('agent_id', $landlord->id)
            ->where('status', DelegationStatus::Active->value)
            ->get()
            ->filter(fn (Delegation $d) => $d->isOperational() && $d->hasCapability('visits'))
            ->pluck('listing_id');

        $query = VisitReservation::where(function ($q) use ($landlord, $delegatedListingIds) {
            $q->where('owner_id', $landlord->id)
                ->orWhere('agent_id', $landlord->id)
                ->orWhereIn('listing_id', $delegatedListingIds);
        })
            ->with(['listing', 'slot', 'proposalSlot', 'prospect', 'paymentTransaction'])
            ->latest('created_at');

        if ($listingId = $request->query('listing_id')) {
            $query->where('listing_id', $listingId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $reservations = $query->paginate($request->integer('per_page', 20));

        return VisitReservationResource::collection($reservations);
    }
}
