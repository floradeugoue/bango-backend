<?php

namespace App\Http\Controllers\Listing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Listing\ListingAlertResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListingAlertController extends Controller
{
    /**
     * Liste des alertes de recherche de l'utilisateur connecté.
     * GET /api/listings/alerts
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $alerts = $request->user()
            ->listingAlerts()
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return ListingAlertResource::collection($alerts);
    }

    /**
     * Créer une alerte de recherche.
     * POST /api/listings/alerts
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', 'in:rent,sale,flatshare'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:100'],
            'neighbourhoods' => ['nullable', 'array'],
            'neighbourhoods.*' => ['string', 'max:100'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['string', 'max:50'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'bedrooms' => ['nullable', 'array'],
            'bedrooms.*' => ['integer', 'min:0'],
            'showers' => ['nullable', 'array'],
            'showers.*' => ['integer', 'min:0'],
            'area_min' => ['nullable', 'numeric', 'min:0'],
            'area_max' => ['nullable', 'numeric', 'min:0'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:50'],
            'verified_only' => ['nullable', 'boolean'],
            'agent_visited_only' => ['nullable', 'boolean'],
            'utilities_included_only' => ['nullable', 'boolean'],
            'frequency' => ['nullable', 'string', 'in:instant,daily,weekly'],
            'push' => ['nullable', 'boolean'],
            'email' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'disabled_at' => ['nullable', 'date'],
        ]);

        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }

        if (isset($validated['enabled']) && ! $validated['enabled'] && ! isset($validated['disabled_at'])) {
            $validated['disabled_at'] = now();
        } elseif (isset($validated['enabled']) && $validated['enabled']) {
            $validated['disabled_at'] = null;
        }

        $alert = $request->user()->listingAlerts()->create($validated);

        return response()->json(
            new ListingAlertResource($alert),
            201
        );
    }

    /**
     * Mettre à jour une alerte de recherche.
     * PATCH /api/listings/alerts/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $alert = $request->user()->listingAlerts()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', 'in:rent,sale,flatshare'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:100'],
            'neighbourhoods' => ['nullable', 'array'],
            'neighbourhoods.*' => ['string', 'max:100'],
            'kinds' => ['nullable', 'array'],
            'kinds.*' => ['string', 'max:50'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'bedrooms' => ['nullable', 'array'],
            'bedrooms.*' => ['integer', 'min:0'],
            'showers' => ['nullable', 'array'],
            'showers.*' => ['integer', 'min:0'],
            'area_min' => ['nullable', 'numeric', 'min:0'],
            'area_max' => ['nullable', 'numeric', 'min:0'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', 'max:50'],
            'verified_only' => ['nullable', 'boolean'],
            'agent_visited_only' => ['nullable', 'boolean'],
            'utilities_included_only' => ['nullable', 'boolean'],
            'frequency' => ['nullable', 'string', 'in:instant,daily,weekly'],
            'push' => ['nullable', 'boolean'],
            'email' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
            'disabled_at' => ['nullable', 'date'],
        ]);

        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }

        if (array_key_exists('enabled', $validated)) {
            if (! $validated['enabled']) {
                $validated['disabled_at'] = $validated['disabled_at'] ?? now();
            } else {
                $validated['disabled_at'] = null;
            }
        }

        $alert->update($validated);

        return response()->json(new ListingAlertResource($alert->fresh()));
    }

    /**
     * Supprimer une alerte de recherche.
     * DELETE /api/listings/alerts/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $alert = $request->user()->listingAlerts()->findOrFail($id);
        $alert->delete();

        return response()->json([
            'message' => 'Alerte de recherche supprimée avec succès.',
        ]);
    }
}
