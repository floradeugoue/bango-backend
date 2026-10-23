<?php

namespace App\Http\Controllers\Listing;

use App\Enums\Listing\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Listing\ListingResource;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingSave;
use App\Services\Listing\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListingSaveController extends Controller
{
    public function __construct(
        protected ListingService $listingService
    ) {}

    /**
     * Liste paginée des annonces sauvegardées par l'utilisateur connecté (IM-16).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $perPage = max(1, min(50, $request->integer('per_page', 15)));

        // Inclure les biens avec leur statut (published, reserved, taken) ; n'exclure que les supprimés/invalides
        $paginator = $user->savedListings()
            ->whereIn('listings.status', [
                ListingStatus::Published->value,
                ListingStatus::Reserved->value,
                ListingStatus::Taken->value,
            ])
            ->with(['owner', 'agent', 'photos', 'videos', 'saves'])
            ->orderByDesc('listing_saves.created_at')
            ->paginate($perPage);

        return ListingResource::collection($paginator);
    }

    /**
     * Sauvegarder ou basculer l'état favori d'une annonce (idempotent).
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        $action = $request->input('action', 'save');
        $saved = $this->listingService->saveListing($listing, $user, $action);

        return response()->json([
            'success' => true,
            'saved' => $saved,
            'saves_count' => $listing->fresh()->saves_count,
            'message' => $saved ? 'Annonce ajoutée à vos favoris.' : 'Annonce retirée de vos favoris.',
        ]);
    }

    /**
     * Retrait explicite d'une annonce des favoris.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        $existing = ListingSave::where('user_id', $user->id)
            ->where('listing_id', $listing->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $listing->decrement('saves_count');
        }

        return response()->json([
            'success' => true,
            'saved' => false,
            'saves_count' => $listing->fresh()->saves_count,
            'message' => 'Annonce retirée de vos favoris.',
        ]);
    }
}
