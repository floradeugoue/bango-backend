<?php

namespace App\Http\Controllers\Visit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visit\AnswerVisitRequest;
use App\Http\Requests\Visit\CreateCustomSlotRequest;
use App\Http\Resources\Visit\VisitReservationResource;
use App\Models\Listing\Listing;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Listing\ListingService;
use App\Services\Visit\VisitReservationService;
use App\Services\Visit\VisitSlotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class LandlordVisitController extends Controller
{
    public function __construct(
        protected ListingService $listingService,
        protected VisitSlotService $slotService,
        protected VisitReservationService $reservationService
    ) {}

    /**
     * Consulter tous les créneaux d'un bien géré (BL-04).
     * GET /api/landlord/properties/{id}/slots
     */
    public function slots(Request $request, string $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        if (! $this->listingService->canManageVisits($request->user(), $listing)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à gérer les créneaux de cette annonce (capacité \'visits\' requise).');
        }

        $slots = VisitSlot::where('listing_id', $listing->id)
            ->where('start_at', '>=', now()->startOfDay())
            ->orderBy('start_at')
            ->get();

        return response()->json([
            'data' => $slots->map(fn (VisitSlot $s) => [
                'id' => $s->id,
                'start_at' => $s->start_at->toIso8601String(),
                'end_at' => $s->end_at->toIso8601String(),
                'capacity' => $s->capacity,
                'booked_count' => $s->booked_count,
                'status' => $s->status->value,
                'available' => $s->hasAvailableCapacity() && $s->start_at->isFuture(),
            ]),
        ]);
    }

    /**
     * Créer un créneau personnalisé pour un bien (BL-04).
     * POST /api/landlord/properties/{id}/slots
     */
    public function createSlot(CreateCustomSlotRequest $request, string $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);

        $slot = $this->slotService->createCustomSlot(
            listing: $listing,
            actor: $request->user(),
            startAt: Carbon::parse($request->validated('start_at')),
            endAt: Carbon::parse($request->validated('end_at')),
            capacity: (int) $request->validated('capacity', 1)
        );

        return response()->json([
            'message' => 'Créneau créé avec succès.',
            'data' => [
                'id' => $slot->id,
                'start_at' => $slot->start_at->toIso8601String(),
                'end_at' => $slot->end_at->toIso8601String(),
                'capacity' => $slot->capacity,
                'status' => $slot->status->value,
            ],
        ], 201);
    }

    /**
     * Fermer un créneau (BL-04).
     * DELETE /api/landlord/properties/{id}/slots/{slotId}
     */
    public function closeSlot(Request $request, string $id, string $slotId): JsonResponse
    {
        $slot = VisitSlot::where('listing_id', $id)
            ->where('id', $slotId)
            ->firstOrFail();

        $closed = $this->slotService->closeSlot($slot, $request->user());

        return response()->json([
            'message' => 'Créneau fermé avec succès.',
            'data' => [
                'id' => $closed->id,
                'status' => $closed->status->value,
            ],
        ]);
    }

    /**
     * Consulter les demandes et visites d'un bien (BL-07).
     * GET /api/landlord/properties/{id}/visit-requests
     */
    public function visitRequests(Request $request, string $id): AnonymousResourceCollection
    {
        $listing = Listing::findOrFail($id);

        if (! $this->listingService->canManageVisits($request->user(), $listing)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à consulter les visites de cette annonce (capacité \'visits\' requise).');
        }

        $query = VisitReservation::where('listing_id', $listing->id)
            ->with(['listing', 'slot', 'proposalSlot', 'prospect', 'paymentTransaction'])
            ->latest('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $reservations = $query->paginate($request->integer('per_page', 20));

        return VisitReservationResource::collection($reservations);
    }

    /**
     * Accepter, proposer une alternative ou décliner une visite (BL-07 / AG-03).
     * POST /api/landlord/visits/{id}/answer
     */
    public function answer(AnswerVisitRequest $request, string $id): JsonResponse
    {
        $reservation = VisitReservation::findOrFail($id);

        $answered = $this->reservationService->answerVisit(
            reservation: $reservation,
            actor: $request->user(),
            action: $request->validated('action'),
            proposalSlotId: $request->validated('proposal_slot_id'),
            reason: $request->validated('reason')
        );

        return response()->json([
            'message' => 'Réponse enregistrée avec succès.',
            'data' => new VisitReservationResource($answered),
        ]);
    }
}
