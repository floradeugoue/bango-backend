<?php

namespace App\Http\Controllers\Listing;

use App\Http\Controllers\Controller;
use App\Services\Listing\ListingSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingMapController extends Controller
{
    public function __construct(
        protected ListingSearchService $searchService
    ) {}

    /**
     * Recherche d'annonces sur la carte avec clustering adaptatif (IM-02).
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user() ?? $request->user('api');

        // Validation des coordonnées géographiques si fournies
        $request->validate([
            'north' => ['nullable', 'numeric', 'between:-85,85'],
            'south' => ['nullable', 'numeric', 'between:-85,85'],
            'east' => ['nullable', 'numeric', 'between:-180,180'],
            'west' => ['nullable', 'numeric', 'between:-180,180'],
            'zoom' => ['nullable', 'integer', 'between:1,22'],
        ]);

        $bounds = [];
        if ($request->filled(['north', 'south', 'east', 'west'])) {
            $bounds = [
                'north' => (float) $request->input('north'),
                'south' => (float) $request->input('south'),
                'east' => (float) $request->input('east'),
                'west' => (float) $request->input('west'),
            ];
        }

        $zoom = (int) $request->input('zoom', 13);
        $result = $this->searchService->mapSearch($bounds, $request->except(['north', 'south', 'east', 'west', 'zoom']), $zoom, $viewer);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
