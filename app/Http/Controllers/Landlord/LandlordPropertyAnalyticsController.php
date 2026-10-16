<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Listing\Listing;
use App\Services\Listing\ListingAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandlordPropertyAnalyticsController extends Controller
{
    public function __construct(
        protected ListingAnalyticsService $analyticsService
    ) {}

    /**
     * Retourne la série chronologique de performance sur 30 jours pour un bien du bailleur.
     *
     * GET /api/landlord/properties/{id}/analytics
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        // Contrôle d'accès : propriétaire, agent assigné ou admin
        $isAuthorized = (int) $listing->owner_id === (int) $user->id
            || (int) $listing->agent_id === (int) $user->id
            || $user->isAdmin();

        if (! $isAuthorized) {
            abort(403, 'Accès non autorisé aux statistiques de ce bien immobilier.');
        }

        $days = $request->integer('days', 30);
        $analytics = $this->analyticsService->getPropertyAnalytics($listing, $days);

        return response()->json([
            'data' => $analytics,
        ]);
    }
}
