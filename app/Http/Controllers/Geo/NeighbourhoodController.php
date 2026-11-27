<?php

namespace App\Http\Controllers\Geo;

use App\Http\Controllers\Controller;
use App\Models\Geo\City;
use App\Models\Geo\Neighbourhood;
use App\Services\Geo\OsmNeighbourhoodService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NeighbourhoodController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, OsmNeighbourhoodService $osmService)
    {
        $cityId = $request->query('city_id');
        $city = null;

        if ($cityId) {
            $city = City::find($cityId);
            if ($city) {
                // Déclenchement automatique OSM à la demande si la ville n'a pas encore de quartiers (Point 2)
                $osmService->syncNeighbourhoodsForCity($city);
            }
        }

        $query = Neighbourhood::with('city');

        if ($cityId) {
            $query->where('city_id', $cityId);
        }

        // Par défaut pour l'application publique : seulement les quartiers vérifiés
        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        } else {
            $query->where(function ($q) {
                $q->where('status', 'verified')->orWhereNull('status');
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $query->orderBy('name');

        $response = response()->json($query->get());
        $response->header('X-Attribution', OsmNeighbourhoodService::OSM_ATTRIBUTION);

        return $response;
    }

    /**
     * Store a newly created resource in storage.
     * Supporte la création par un admin ou la saisie d'un « Autre quartier » par un utilisateur (Point 2).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'city_id' => 'required|exists:cities,id',
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'is_active' => 'sometimes|boolean',
            'status' => 'sometimes|in:verified,pending,rejected',
        ]);

        $user = $request->user();
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();

        // Si saisi par un utilisateur dans l'app (« Autre quartier ») -> statut pending à valider
        $isPendingUserSubmission = ! $isAdmin && ($request->input('source') === 'user' || ! $isAdmin);
        $status = $isPendingUserSubmission ? 'pending' : ($validated['status'] ?? 'verified');
        $isActive = $status === 'verified' ? (bool) ($validated['is_active'] ?? true) : false;
        $source = $isPendingUserSubmission ? 'user' : ($request->input('source') ?? 'manual');

        // Idempotence : si le quartier existe déjà pour cette ville
        $existing = Neighbourhood::where('city_id', $validated['city_id'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($validated['name'], 'UTF-8')])
            ->first();

        if ($existing) {
            if ($request->filled('lat') || $request->filled('lng')) {
                $existing->update($request->only(['lat', 'lng']));
            }

            return response()->json($existing->load('city'), 200);
        }

        $slug = ! empty($validated['slug']) ? $validated['slug'] : Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 1;
        while (Neighbourhood::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }
        $validated['slug'] = $slug;
        $validated['status'] = $status;
        $validated['source'] = $source;
        $validated['is_active'] = $isActive;
        $validated['submitted_by_user_id'] = $user?->id;

        $neighbourhood = Neighbourhood::create($validated);

        return response()->json($neighbourhood->load('city'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return response()->json(Neighbourhood::with('city')->findOrFail($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $neighbourhood = Neighbourhood::findOrFail($id);
        $validated = $request->validate([
            'city_id' => 'sometimes|exists:cities,id',
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:255|unique:neighbourhoods,slug,'.$neighbourhood->id,
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'is_active' => 'sometimes|boolean',
        ]);

        $neighbourhood->update($validated);

        return response()->json($neighbourhood->load('city'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $neighbourhood = Neighbourhood::findOrFail($id);
        $neighbourhood->delete();

        return response()->json(null, 204);
    }

    /**
     * Liste des quartiers soumis par les utilisateurs en attente de validation (Back-Office).
     */
    public function pending(Request $request)
    {
        $query = Neighbourhood::with(['city.country', 'submittedByUser:id,name,handle,email'])
            ->where('status', 'pending');

        if ($request->has('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        $items = $query->latest()->paginate((int) $request->query('per_page', 25));

        return response()->json($items);
    }

    /**
     * Valide un quartier soumis et l'ajoute à la liste officielle (Back-Office).
     */
    public function approve(string $id)
    {
        $neighbourhood = Neighbourhood::findOrFail($id);
        $neighbourhood->update([
            'status' => 'verified',
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Le quartier '{$neighbourhood->name}' a été validé avec succès.",
            'neighbourhood' => $neighbourhood->load('city'),
        ]);
    }

    /**
     * Rejette un quartier soumis (Back-Office).
     */
    public function reject(string $id)
    {
        $neighbourhood = Neighbourhood::findOrFail($id);
        $neighbourhood->update([
            'status' => 'rejected',
            'is_active' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Le quartier '{$neighbourhood->name}' a été rejeté.",
            'neighbourhood' => $neighbourhood->load('city'),
        ]);
    }
}
