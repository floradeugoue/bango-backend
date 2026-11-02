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
use App\Models\Listing\ListingDailyMetric;
use App\Policies\ListingPolicy;
use App\Services\Delegation\DelegationService;
use App\Services\Listing\ListingSearchService;
use App\Services\Listing\ListingService;
use App\Services\Subscription\BadgeResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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

        /** @var Listing|null $listing */
        $listing = Listing::withTrashed()->with(['owner', 'agent', 'media', 'saves'])->find($id);

        if (! $listing) {
            return response()->json([
                'success' => false,
                'message' => 'Cette annonce immobilière n\'existe pas.',
            ], 404);
        }

        // Si l'annonce a été supprimée ou retirée, rendre 410 avec les détails géographiques et la raison (IM-21)
        if ($listing->trashed()) {
            $reason = 'withdrawn';
            if ($listing->status === ListingStatus::Taken) {
                $reason = 'rented';
            } elseif ($listing->withdrawn_by_bango || $listing->status === ListingStatus::Rejected) {
                $reason = 'moderated';
            }

            return response()->json([
                'title' => $listing->title,
                'city' => $listing->city,
                'neighbourhood' => $listing->neighbourhood,
                'mode' => $listing->mode?->value ?? (string) $listing->mode,
                'removed_at' => $listing->deleted_at?->toIso8601String(),
                'reason' => $reason,
            ], 410);
        }

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

            // Synchronisation de la métrique quotidienne et de la provenance de la vue
            $today = now()->startOfDay()->toDateString();
            $source = $request->input('source');
            $sourceColumn = match ($source) {
                'reel' => 'views_reel',
                'search' => 'views_search',
                'link' => 'views_link',
                default => 'views_feed',
            };

            $metric = ListingDailyMetric::where('listing_id', $listing->id)
                ->whereDate('metric_date', $today)
                ->first();

            if (! $metric) {
                try {
                    $metric = ListingDailyMetric::create([
                        'listing_id' => $listing->id,
                        'metric_date' => $today,
                        'views' => 0,
                        'views_feed' => 0,
                        'views_reel' => 0,
                        'views_search' => 0,
                        'views_link' => 0,
                        'video_views' => 0,
                        'favorites' => 0,
                        'contacts' => 0,
                    ]);
                } catch (\Throwable $e) {
                    $metric = ListingDailyMetric::where('listing_id', $listing->id)
                        ->whereDate('metric_date', $today)
                        ->first();
                }
            }

            if ($metric) {
                $updates = [
                    'views' => DB::raw('views + 1'),
                    $sourceColumn => DB::raw("{$sourceColumn} + 1"),
                ];

                if ($request->boolean('is_video') || $request->input('media_type') === 'video') {
                    $updates['video_views'] = DB::raw('video_views + 1');
                }

                ListingDailyMetric::where('id', $metric->id)->update($updates);
            }
        }

        return (new ListingDetailResource($listing))->response();
    }

    /**
     * Création et soumission d'une annonce immobilière (CR-39 à CR-48).
     */
    public function store(StoreListingRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $statusParam = $request->input('status');

        if ($statusParam === 'draft') {
            $listing = $this->listingService->saveDraft($user, $validated);

            return (new ListingDetailResource($listing))
                ->response()
                ->setStatusCode(201);
        }

        // Décision produit : si l'utilisateur possède un badge actif (landlord, agent, double),
        // publier directement (published), sinon envoyer en modération (review).
        $badgeStatus = app(BadgeResolutionService::class)->resolveUserBadges($user);
        $hasActiveBadge = in_array($user->badge, ['landlord', 'agent', 'double'], true)
            || in_array($badgeStatus['badge'] ?? '', ['landlord', 'agent', 'double'], true);

        $submitToReview = ! $hasActiveBadge;

        $listing = $this->listingService->createOrPublishListing(
            $user,
            $validated,
            submitToReview: $submitToReview
        );

        return (new ListingDetailResource($listing))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Mes annonces immobilières (propriétaire / bailleur).
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $perPage = max(1, min(50, $request->integer('per_page', 15)));

        $listings = Listing::where('owner_id', $user->id)
            ->with(['owner', 'photos', 'videos', 'saves'])
            ->latest('created_at')
            ->paginate($perPage);

        return ListingResource::collection($listings);
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

        app(DelegationService::class)->revokeForDeletedListing($listing, $user);

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

    /**
     * Enregistre un partage du bien immobilier avec la même règle d'idempotence que les reels.
     * POST /listings/{id}/share
     */
    public function share(Request $request, string $id): JsonResponse
    {
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'platform' => ['nullable', 'string', 'max:50'],
            'client_mutation_id' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->listingService->recordShare(
            listing: $listing,
            user: $user,
            platform: $validated['platform'] ?? null,
            clientMutationId: $validated['client_mutation_id'] ?? null,
        );

        return response()->json([
            'success' => true,
            'shares_count' => $result['shares_count'],
            'listing_id' => $listing->id,
        ]);
    }
}
