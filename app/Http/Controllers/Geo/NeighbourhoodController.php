<?php

namespace App\Http\Controllers\Geo;

use App\Http\Controllers\Controller;
use App\Models\Geo\Neighbourhood;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NeighbourhoodController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Neighbourhood::with('city');

        if ($request->has('city_id')) {
            $query->where('city_id', $request->city_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json($query->get());
    }

    /**
     * Store a newly created resource in storage.
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
        ]);

        // Idempotence : si le quartier existe déjà pour cette ville
        $existing = Neighbourhood::where('city_id', $validated['city_id'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($validated['name'])])
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
}
