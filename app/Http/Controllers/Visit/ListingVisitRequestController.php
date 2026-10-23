<?php

namespace App\Http\Controllers\Visit;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Visit\ListingVisitRequestResource;
use App\Http\Resources\Visit\VisitReservationResource;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\Visit\ListingVisitRequest;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Listing\ListingService;
use App\Services\Visit\VisitReservationService;
use App\Services\Visit\VisitSlotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ListingVisitRequestController extends Controller
{
    public function __construct(
        protected ListingService $listingService,
        protected VisitSlotService $slotService,
        protected VisitReservationService $reservationService
    ) {}

    /**
     * Prospect submits a visit request with 1 to 3 date proposals.
     * POST /api/listings/{id}/visit-requests
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $user = $request->user();

        if ($user->id === $listing->owner_id || ($listing->agent_id && $user->id === $listing->agent_id)) {
            throw ValidationException::withMessages([
                'listing' => ['Vous ne pouvez pas demander une visite pour votre propre annonce.'],
            ]);
        }

        $validated = $request->validate([
            'proposals' => ['required', 'array', 'min:1', 'max:3'],
            'proposals.*' => ['required', 'date', 'after:now'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'proposals.required' => 'Veuillez proposer au moins un créneau pour la visite.',
            'proposals.min' => 'Veuillez proposer au moins un créneau pour la visite.',
            'proposals.max' => 'Vous ne pouvez pas proposer plus de 3 créneaux.',
            'proposals.*.date' => 'Chaque créneau proposé doit être une date valide au format ISO 8601.',
            'proposals.*.after' => 'Chaque créneau proposé doit être situé dans le futur.',
        ]);

        // Standardize proposals to ISO 8601 strings
        $normalizedProposals = array_map(function ($dateStr) {
            return Carbon::parse($dateStr)->toIso8601String();
        }, $validated['proposals']);

        $visitRequest = ListingVisitRequest::create([
            'listing_id' => $listing->id,
            'prospect_id' => $user->id,
            'owner_id' => $listing->owner_id,
            'agent_id' => $listing->agent_id,
            'proposals' => $normalizedProposals,
            'message' => $validated['message'] ?? null,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(
            new ListingVisitRequestResource($visitRequest),
            201
        );
    }

    /**
     * Show a visit request (marks viewed_by_landlord_at if accessed by landlord/agent).
     * GET /api/visits/requests/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $visitRequest = ListingVisitRequest::with(['listing', 'reservation'])->findOrFail($id);
        $user = $request->user();

        $isProspect = $user->id === $visitRequest->prospect_id;
        $isLandlordOrAgent = $this->listingService->canManageVisits($user, $visitRequest->listing);
        $isAdmin = $user->isAdmin();

        if (! $isProspect && ! $isLandlordOrAgent && ! $isAdmin) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à consulter cette demande de visite.');
        }

        if ($isLandlordOrAgent && is_null($visitRequest->viewed_by_landlord_at)) {
            $visitRequest->update([
                'viewed_by_landlord_at' => now(),
            ]);
        }

        return response()->json(new ListingVisitRequestResource($visitRequest));
    }

    /**
     * Cancel a visit request by the prospect.
     * POST /api/visits/requests/{id}/cancel
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $visitRequest = ListingVisitRequest::findOrFail($id);
        $user = $request->user();

        if ($user->id !== $visitRequest->prospect_id && ! $user->isAdmin()) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à annuler cette demande de visite.');
        }

        if ($visitRequest->status === 'cancelled') {
            return response()->json([
                'message' => 'Cette demande est déjà annulée.',
                'data' => new ListingVisitRequestResource($visitRequest),
            ]);
        }

        $visitRequest->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Demande de visite annulée avec succès.',
            'data' => new ListingVisitRequestResource($visitRequest),
        ]);
    }

    /**
     * Landlord chooses one of the proposed slots, creating a reservation pending payment.
     * POST /api/landlord/visit-requests/{id}/choose
     */
    public function choose(Request $request, string $id): JsonResponse
    {
        $visitRequest = ListingVisitRequest::with(['listing', 'prospect'])->findOrFail($id);
        $user = $request->user();

        if (! $this->listingService->canManageVisits($user, $visitRequest->listing)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à répondre à cette demande de visite.');
        }

        if ($visitRequest->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => [sprintf('Cette demande ne peut plus être traitée (statut actuel: %s).', $visitRequest->status)],
            ]);
        }

        $proposals = $visitRequest->proposals ?? [];
        $maxIndex = count($proposals) - 1;

        if ($maxIndex < 0) {
            throw ValidationException::withMessages([
                'proposals' => ['Aucun créneau proposé dans cette demande.'],
            ]);
        }

        $validated = $request->validate([
            'proposal_index' => ['required', 'integer', 'min:0', 'max:'.$maxIndex],
        ]);

        $selectedIndex = (int) $validated['proposal_index'];
        $chosenDateStr = $proposals[$selectedIndex];
        $chosenSlotAt = Carbon::parse($chosenDateStr);

        if ($chosenSlotAt->isPast()) {
            throw ValidationException::withMessages([
                'proposal_index' => ['Ce créneau proposé est déjà dépassé.'],
            ]);
        }

        $result = DB::transaction(function () use ($visitRequest, $selectedIndex, $chosenSlotAt) {
            $listing = $visitRequest->listing;
            $prospect = $visitRequest->prospect;

            // 1. Create a VisitSlot for the chosen proposal
            $slot = VisitSlot::create([
                'listing_id' => $listing->id,
                'owner_id' => $listing->owner_id,
                'agent_id' => $listing->agent_id,
                'start_at' => $chosenSlotAt,
                'end_at' => $chosenSlotAt->copy()->addMinutes(30),
                'capacity' => 1,
                'booked_count' => 1,
                'status' => VisitSlotStatus::Open,
            ]);

            // 2. Pricing and quote
            $feeAmount = (float) ($listing->visit_fee ?? 0);
            $currency = $listing->currency ?? 'XAF';
            $quote = [
                'listing_id' => $listing->id,
                'listing_title' => $listing->title,
                'slot_id' => $slot->id,
                'scheduled_at' => $slot->start_at->toIso8601String(),
                'fee_amount' => $feeAmount,
                'currency' => $currency,
                'deposit_required' => $feeAmount > 0,
                'total_amount' => $feeAmount,
                'cancellation_policy' => 'Remboursement intégral en cas d\'annulation plus de 24h avant la visite ou refus du bailleur.',
            ];

            $isPaid = $feeAmount <= 0;

            // 3. Create VisitReservation in pending_payment status (or requested if free) with 15 min lock
            $reservation = VisitReservation::create([
                'listing_id' => $listing->id,
                'slot_id' => $slot->id,
                'prospect_id' => $prospect->id,
                'owner_id' => $listing->owner_id,
                'agent_id' => $listing->agent_id,
                'status' => $isPaid ? VisitReservationStatus::Requested : VisitReservationStatus::PendingPayment,
                'lock_expires_at' => $isPaid ? null : now()->addMinutes(15),
                'fee' => $feeAmount,
                'currency' => $currency,
                'pricing_snapshot' => $quote,
                'message' => $visitRequest->message,
                'prospect_phone' => $prospect->phone,
                'paid_at' => $isPaid ? now() : null,
            ]);

            // 4. Create pending payment transaction if not paid
            $transaction = null;
            if (! $isPaid) {
                $idempotencyKey = 'VISIT-'.$reservation->id.'-'.Str::random(6);
                $transaction = PaymentTransaction::create([
                    'user_id' => $prospect->id,
                    'service_type' => PaymentServiceType::Visit,
                    'payable_type' => VisitReservation::class,
                    'payable_id' => $reservation->id,
                    'provider' => PaymentProvider::Monetbil,
                    'channel' => PaymentChannel::MobileMoney,
                    'currency' => $currency,
                    'amount' => $feeAmount,
                    'fee_amount' => '0.00',
                    'tax_amount' => '0.00',
                    'total_amount' => $feeAmount,
                    'credit_used' => '0.00',
                    'amount_charged' => $feeAmount,
                    'status' => PaymentStatus::Pending,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => [
                        'reservation_id' => $reservation->id,
                        'listing_id' => $listing->id,
                        'slot_id' => $slot->id,
                        'visit_request_id' => $visitRequest->id,
                    ],
                ]);

                $reservation->transaction_id = $transaction->id;
                $reservation->save();
            }

            // 5. Update ListingVisitRequest
            $visitRequest->update([
                'status' => 'chosen',
                'chosen_proposal_index' => $selectedIndex,
                'chosen_slot_at' => $chosenSlotAt,
                'visit_reservation_id' => $reservation->id,
                'viewed_by_landlord_at' => $visitRequest->viewed_by_landlord_at ?? now(),
            ]);

            return [
                'request' => $visitRequest,
                'reservation' => $reservation,
                'transaction' => $transaction,
                'quote' => $quote,
            ];
        });

        return response()->json([
            'message' => 'Créneau retenu avec succès. La réservation est en attente de paiement par le prospect.',
            'data' => new ListingVisitRequestResource($result['request']->fresh()),
            'reservation' => new VisitReservationResource($result['reservation']->load(['listing', 'slot'])),
            'quote' => $result['quote'],
            'transaction' => $result['transaction'] ? [
                'id' => $result['transaction']->id,
                'reference' => $result['transaction']->reference,
                'status' => $result['transaction']->status->value,
                'amount' => (float) $result['transaction']->amount,
                'currency' => $result['transaction']->currency,
            ] : null,
        ]);
    }
}
