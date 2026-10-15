<?php

namespace App\Http\Controllers\Listing;

use App\Enums\Listing\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\StoreListingRequest;
use App\Http\Requests\Listing\UpdateListingDetailsRequest;
use App\Http\Requests\Listing\UpdateListingStatusRequest;
use App\Http\Resources\Listing\ListingDetailResource;
use App\Http\Resources\Listing\ListingResource;
use App\Models\Listing\Listing;
use App\Policies\ListingPolicy;
use App\Services\Listing\ListingSearchService;
use App\Services\Listing\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListingController extends Controller
{
    public function __construct(
        protected ListingService $listingService,
        protected ListingSearchService $searchService
    ) {}

    /**
     * Feed immobilier vertical et recherche filtrée (IM-00a).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $viewer = $request->user() ?? $request->user('api');
        $perPage = max(1, min(50, $request->integer('per_page', 15)));

        $paginator = $this->searchService->searchListings($request->all(), $perPage, $viewer);

        return ListingResource::collection($paginator);
    }

    /**
     * Compteur dynamique des résultats correspondant aux filtres actifs (IM-03).
     */
    public function count(Request $request): JsonResponse
    {
        $viewer = $request->user() ?? $request->user('api');
        $total = $this->searchService->countListings($request->all(), $viewer);

        return response()->json([
            'success' => true,
            'count' => $total,
            'total' => $total,
        ]);
    }

    /**
     * Consultation de la fiche détaillée d'une annonce (IM-05).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $viewer = $request->user() ?? $request->user('api');

        /** @var Listing $listing */
        $listing = Listing::with(['owner', 'agent', 'media', 'saves'])->findOrFail($id);

        // Contrôle d'accès et de visibilité selon la policy
        if (! (new ListingPolicy($this->listingService))->view($viewer, $listing)) {
            return response()->json([
                'success' => false,
                'message' => 'Cette annonce immobilière n\'est pas accessible ou a été retirée.',
            ], 404);
        }

        // Incrémenter le compteur de vues uniquement pour les visiteurs tiers
        if (! $viewer || $viewer->id !== $listing->owner_id) {
            $listing->increment('views_count');
        }

        return (new ListingDetailResource($listing))->response();
    }

    /**
     * Création et soumission d'une annonce immobilière (CR-39 à CR-48).
     */
    public function store(StoreListingRequest $request): JsonResponse
    {
        $user = $request->user();

        $listing = $this->listingService->createOrPublishListing(
            $user,
            $request->validated(),
            submitToReview: true
        );

        return (new ListingDetailResource($listing))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Modification des détails (texte, prix, charges) d'une annonce (CR-48).
     */
    public function update(UpdateListingDetailsRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        $updated = $this->listingService->updateListingDetails($listing, $user, $request->validated());

        return (new ListingDetailResource($updated))->response();
    }

    /**
     * Retrait / suppression douce d'une annonce (EDGE-03).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        if (! $this->listingService->canManage($user, $listing)) {
            return response()->json([
                'success' => false,
                'message' => 'Action non autorisée.',
            ], 403);
        }

        $listing->delete();

        return response()->json([
            'success' => true,
            'message' => 'Annonce retirée avec succès.',
        ]);
    }

    /**
     * Changement d'état d'un bien (BL-18 : published, reserved, taken).
     */
    public function updateStatus(UpdateListingStatusRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        $newStatus = ListingStatus::from($request->input('status'));
        $updated = $this->listingService->updateListingStatus(
            $listing,
            $user,
            $newStatus,
            $request->input('reason')
        );

        return (new ListingDetailResource($updated))->response();
    }
}
