<?php

namespace App\Http\Controllers\Visit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visit\BookVisitRequest;
use App\Http\Requests\Visit\CancelVisitRequest;
use App\Http\Requests\Visit\RespondToProposalRequest;
use App\Http\Resources\Visit\VisitReceiptResource;
use App\Http\Resources\Visit\VisitReservationResource;
use App\Models\Listing\Listing;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Payment\ReceiptPdfService;
use App\Services\Visit\VisitReservationService;
use App\Services\Visit\VisitSlotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProspectVisitController extends Controller
{
    public function __construct(
        protected VisitSlotService $slotService,
        protected VisitReservationService $reservationService,
        protected ?ReceiptPdfService $pdfService = null
    ) {
        $this->pdfService ??= app(ReceiptPdfService::class);
    }

    /**
     * Consulter les créneaux disponibles pour une annonce.
     * GET /api/listings/{id}/slots
     */
    public function slots(Request $request, string $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $days = $this->slotService->getAvailableDays($listing, $request->user());

        return response()->json([
            'data' => $days,
        ]);
    }

    /**
     * Poser une réservation avec verrou temporaire de 15 minutes et transaction PAY.
     * POST /api/listings/{id}/visits
     */
    public function book(BookVisitRequest $request, string $id): JsonResponse
    {
        $listing = Listing::findOrFail($id);
        $slot = VisitSlot::findOrFail($request->validated('slot_id'));

        $result = $this->reservationService->bookVisit(
            listing: $listing,
            slot: $slot,
            prospect: $request->user(),
            payload: $request->validated()
        );

        return response()->json([
            'message' => 'Réservation initiée avec succès. Veuillez finaliser le règlement dans les 15 minutes.',
            'data' => new VisitReservationResource($result['reservation']),
            'transaction' => $result['transaction'] ? [
                'id' => $result['transaction']->id,
                'reference' => $result['transaction']->reference,
                'status' => $result['transaction']->status->value,
                'amount' => (float) $result['transaction']->amount,
                'currency' => $result['transaction']->currency,
            ] : null,
            'quote' => $result['quote'],
        ], 201);
    }

    /**
     * Confirmation post-paiement côté client avec vérification de la preuve côté serveur.
     * POST /api/visits/{id}/confirm
     */
    public function confirm(Request $request, string $id): JsonResponse
    {
        $reservation = VisitReservation::findOrFail($id);

        $confirmed = $this->reservationService->confirmPaymentFromClient($reservation, $request->user());

        return response()->json([
            'message' => $confirmed->status->value === 'confirmed'
                ? 'Visite confirmée avec succès. L\'adresse exacte vous a été transmise.'
                : 'Paiement en cours de vérification.',
            'data' => new VisitReservationResource($confirmed),
        ]);
    }

    /**
     * Historique des visites du prospect connecté.
     * GET /api/visits/mine
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $status = $request->query('status');

        $query = VisitReservation::where('prospect_id', $request->user()->id)
            ->with(['listing', 'slot', 'proposalSlot', 'paymentTransaction'])
            ->latest('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        $visits = $query->paginate($request->integer('per_page', 15));

        return VisitReservationResource::collection($visits);
    }

    /**
     * Détails d'une réservation.
     * GET /api/visits/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $reservation = VisitReservation::with(['listing', 'slot', 'proposalSlot', 'paymentTransaction'])->findOrFail($id);

        $user = $request->user();
        $isAuthorized = $user->id === $reservation->prospect_id
            || $user->id === $reservation->owner_id
            || ($reservation->agent_id && $user->id === $reservation->agent_id)
            || $user->isAdmin();

        if (! $isAuthorized) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à consulter cette réservation.');
        }

        return response()->json([
            'data' => new VisitReservationResource($reservation),
        ]);
    }

    /**
     * Annuler une visite.
     * POST /api/visits/{id}/cancel
     */
    public function cancel(CancelVisitRequest $request, string $id): JsonResponse
    {
        $reservation = VisitReservation::findOrFail($id);

        $cancelled = $this->reservationService->cancelVisit(
            reservation: $reservation,
            actor: $request->user(),
            reason: $request->validated('reason')
        );

        return response()->json([
            'message' => 'Visite annulée avec succès.',
            'data' => new VisitReservationResource($cancelled),
        ]);
    }

    /**
     * Répondre à une proposition de créneau alternatif par le bailleur.
     * POST /api/visits/{id}/proposal/respond
     */
    public function respondToProposal(RespondToProposalRequest $request, string $id): JsonResponse
    {
        $reservation = VisitReservation::findOrFail($id);

        $updated = $this->reservationService->respondToProposal(
            reservation: $reservation,
            prospect: $request->user(),
            accept: (bool) $request->validated('accept')
        );

        return response()->json([
            'message' => $updated->status->value === 'confirmed'
                ? 'Proposition acceptée. Votre visite est confirmée.'
                : 'Proposition refusée. Le remboursement a été initié.',
            'data' => new VisitReservationResource($updated),
        ]);
    }

    /**
     * Reçu officiel de visite.
     * GET /api/visits/{id}/receipt
     */
    public function receipt(Request $request, string $id): JsonResponse
    {
        $reservation = VisitReservation::with(['listing', 'slot', 'prospect', 'paymentTransaction'])->findOrFail($id);

        $user = $request->user();
        if ($user->id !== $reservation->prospect_id && ! $user->isAdmin()) {
            throw new AuthorizationException('Accès refusé au reçu.');
        }

        return response()->json([
            'data' => new VisitReceiptResource($reservation),
        ]);
    }

    /**
     * Téléchargement du reçu officiel de visite au format PDF signé (PAY-23).
     * GET /api/visits/{id}/receipt.pdf
     */
    public function receiptPdf(Request $request, string $id): Response
    {
        $reservation = VisitReservation::with(['listing', 'slot', 'prospect', 'paymentTransaction'])->findOrFail($id);

        $user = $request->user();
        if ($user->id !== $reservation->prospect_id && ! $user->isAdmin()) {
            throw new AuthorizationException('Accès refusé au reçu de visite.');
        }

        $pdfBinary = $this->pdfService->generateVisitReceiptPdf($reservation);
        $filename = "recu-visite-{$reservation->id}.pdf";

        return response($pdfBinary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
