<?php

namespace App\Http\Controllers\Admin\Listing;

use App\Http\Controllers\Controller;
use App\Models\Listing\Listing;
use App\Services\Admin\AdminListingControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminListingControlController extends Controller
{
    public function __construct(
        protected AdminListingControlService $listingService
    ) {}

    /**
     * B.1. Liste des annonces immobilières avec filtres, compteurs et pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status', 'mode', 'kind', 'city', 'neighbourhood',
            'agent_visited', 'verified_owner', 'min_price', 'max_price', 'q',
        ]);
        $perPage = $request->integer('per_page', 20);

        $listings = $this->listingService->getListings($filters, $perPage);

        $items = collect($listings->items())->map(function (Listing $l) {
            return [
                'id' => $l->id,
                'title' => $l->title,
                'mode' => $l->mode?->value ?? $l->mode,
                'kind' => $l->kind?->value ?? $l->kind,
                'status' => $l->status?->value ?? $l->status,
                'price' => (float) $l->price,
                'charges' => (float) $l->charges,
                'currency' => $l->currency,
                'city' => $l->city,
                'neighbourhood' => $l->neighbourhood,
                'bedrooms' => $l->bedrooms,
                'showers' => $l->showers,
                'area' => (float) $l->area,
                'agent_visited' => (bool) $l->agent_visited,
                'owner' => [
                    'id' => $l->owner->id,
                    'name' => $l->owner->name,
                    'badge' => $l->owner->badge,
                    'is_verified' => (bool) $l->owner->is_verified,
                ],
                'agent' => $l->agent ? [
                    'id' => $l->agent->id,
                    'name' => $l->agent->name,
                ] : null,
                'page' => $l->page ? [
                    'id' => $l->page->id,
                    'name' => $l->page->name,
                ] : null,
                'photo' => $l->mainPhoto()?->file_path,
                'has_active_boost' => $l->campaigns->contains(fn ($c) => $c->status === 'active'),
                'created_at' => $l->created_at->toISOString(),
                'published_at' => $l->published_at?->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $listings->currentPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
                'last_page' => $listings->lastPage(),
            ],
        ]);
    }

    /**
     * B.1 bis. Fiche détaillée d'une annonce immobilière.
     */
    public function show(string $ref): JsonResponse
    {
        $listing = $this->listingService->getListingDetail($ref);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'mode' => $listing->mode?->value ?? $listing->mode,
                'kind' => $listing->kind?->value ?? $listing->kind,
                'status' => $listing->status?->value ?? $listing->status,
                'price' => (float) $listing->price,
                'charges' => (float) $listing->charges,
                'deposit_months' => $listing->deposit_months,
                'advance_months' => $listing->advance_months,
                'visit_fee' => (float) $listing->visit_fee,
                'currency' => $listing->currency,
                'city' => $listing->city,
                'neighbourhood' => $listing->neighbourhood,
                'location' => $listing->location,
                'address' => $listing->address,
                'lat' => (float) $listing->lat,
                'lng' => (float) $listing->lng,
                'bedrooms' => $listing->bedrooms,
                'showers' => $listing->showers,
                'area' => (float) $listing->area,
                'amenities' => $listing->amenities ?? [],
                'agent_visited' => (bool) $listing->agent_visited,
                'rejection_reason' => $listing->rejection_reason,
                'owner' => [
                    'id' => $listing->owner->id,
                    'name' => $listing->owner->name,
                    'email' => $listing->owner->email,
                    'phone' => $listing->owner->phone,
                    'badge' => $listing->owner->badge,
                    'is_verified' => (bool) $listing->owner->is_verified,
                ],
                'agent' => $listing->agent ? [
                    'id' => $listing->agent->id,
                    'name' => $listing->agent->name,
                    'phone' => $listing->agent->phone,
                ] : null,
                'page' => $listing->page ? [
                    'id' => $listing->page->id,
                    'name' => $listing->page->name,
                ] : null,
                'media' => $listing->media->map(fn ($m) => [
                    'id' => $m->id,
                    'type' => $m->type,
                    'file_path' => $m->file_path,
                    'room' => $m->room,
                    'order' => $m->order,
                ]),
                'campaigns' => $listing->campaigns->map(fn ($c) => [
                    'id' => $c->id,
                    'objective' => $c->objective?->value ?? $c->objective,
                    'status' => $c->status?->value ?? $c->status,
                    'budget_spent' => (float) $c->budget_spent,
                ]),
                'merges' => $listing->merges->map(fn ($m) => [
                    'secondary_listing_id' => $m->secondary_listing_id,
                    'reason' => $m->reason,
                    'operator_name' => $m->operator?->name,
                    'created_at' => $m->created_at->toISOString(),
                ]),
                'agent_certifications' => $listing->agentCertifications->map(fn ($ac) => [
                    'agent_name' => $ac->agent?->name,
                    'certified_at' => $ac->certified_at->toISOString(),
                    'notes' => $ac->notes,
                ]),
                'review_logs' => $listing->reviewLogs->map(fn ($rl) => [
                    'action' => $rl->action,
                    'from_status' => $rl->from_status,
                    'to_status' => $rl->to_status,
                    'reason' => $rl->reason,
                    'operator_name' => $rl->operator?->name,
                    'created_at' => $rl->created_at->toISOString(),
                ]),
                'published_at' => $listing->published_at?->toISOString(),
                'created_at' => $listing->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * B.4. Contrôle de publication (approve, reject, request_changes).
     */
    public function review(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject,request_changes,draft'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing = Listing::findOrFail($ref);

        $updated = $this->listingService->review(
            listing: $listing,
            operator: $request->user(),
            decision: $validated['decision'],
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Contrôle d\'annonce enregistré avec succès.',
            'data' => [
                'id' => $updated->id,
                'status' => $updated->status->value,
                'rejection_reason' => $updated->rejection_reason,
                'published_at' => $updated->published_at?->toISOString(),
            ],
        ]);
    }

    /**
     * B.4 bis. Traitement groupé d'annonces.
     */
    public function bulkReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refs' => ['required', 'array', 'min:1'],
            'refs.*' => ['required', 'string'],
            'decision' => ['required', 'string', 'in:approve,reject,request_changes'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $results = $this->listingService->bulkReview(
            refs: $validated['refs'],
            operator: $request->user(),
            decision: $validated['decision'],
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Traitement groupé exécuté.',
            'data' => $results,
        ]);
    }

    /**
     * B.5. Certification de visite sur place par un agent.
     */
    public function agentVisit(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'evidence' => ['nullable', 'array'],
        ]);

        $listing = Listing::findOrFail($ref);

        $certification = $this->listingService->certifyAgentVisit(
            listing: $listing,
            operator: $request->user(),
            data: $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Certification de visite sur place enregistrée avec succès.',
            'data' => [
                'listing_id' => $listing->id,
                'agent_visited' => true,
                'certified_at' => $certification->certified_at->toISOString(),
                'notes' => $certification->notes,
            ],
        ]);
    }

    public function certifyAgentVisit(Request $request, string $ref): JsonResponse
    {
        return $this->agentVisit($request, $ref);
    }

    /**
     * B.6. Dépublication / suspension d'une annonce.
     */
    public function unpublish(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing = Listing::findOrFail($ref);

        $updated = $this->listingService->unpublish(
            listing: $listing,
            operator: $request->user(),
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Annonce dépubliée avec succès.',
            'data' => [
                'id' => $updated->id,
                'status' => $updated->status->value,
                'rejection_reason' => $updated->rejection_reason,
            ],
        ]);
    }

    /**
     * B.6 bis. Republication d'une annonce dépubliée ou corrigée.
     */
    public function republish(Request $request, ?string $ref = null): JsonResponse
    {
        $listingId = $ref ?? $request->input('ref') ?? $request->input('id');

        if (empty($listingId)) {
            return response()->json(['message' => 'Référence d\'annonce obligatoire.'], 422);
        }

        $listing = Listing::findOrFail($listingId);

        $updated = $this->listingService->republish(
            listing: $listing,
            operator: $request->user(),
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Annonce republiée avec succès.',
            'data' => [
                'id' => $updated->id,
                'status' => $updated->status->value,
                'published_at' => $updated->published_at?->toISOString(),
            ],
        ]);
    }

    /**
     * B.7. Marquage d'un bien comme loué / occupé.
     */
    public function rented(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $listing = Listing::findOrFail($ref);

        $updated = $this->listingService->markAsRented(
            listing: $listing,
            operator: $request->user(),
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Bien marqué comme loué avec succès.',
            'data' => [
                'id' => $updated->id,
                'status' => $updated->status->value,
                'taken_at' => $updated->taken_at?->toISOString(),
            ],
        ]);
    }

    public function markRented(Request $request, string $ref): JsonResponse
    {
        return $this->rented($request, $ref);
    }

    /**
     * B.8. Données géographiques pour la cartographie administrative.
     */
    public function map(Request $request): JsonResponse
    {
        $filters = $request->only(['city', 'status', 'kind']);
        $markers = $this->listingService->getMapListings($filters);

        return response()->json([
            'success' => true,
            'data' => $markers,
            'count' => $markers->count(),
        ]);
    }
}
