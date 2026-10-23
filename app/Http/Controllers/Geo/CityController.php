<?php

namespace App\Http\Controllers\Geo;

use App\Http\Controllers\Controller;
use App\Models\Geo\City;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CityController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = City::with('country');

        if ($request->has('country_id')) {
            $query->where('country_id', $request->country_id);
        } elseif ($request->has('country_code')) {
            $query->whereHas('country', fn ($q) => $q->where('code', $request->country_code)->orWhere('iso2', $request->country_code));
        } elseif ($request->has('iso2')) {
            $query->whereHas('country', fn ($q) => $q->where('iso2', $request->iso2));
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
            'country_id' => 'required|exists:countries,id',
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'is_active' => 'sometimes|boolean',
        ]);

        // Idempotence : si la ville existe déjà pour ce pays
        $existing = City::where('country_id', $validated['country_id'])
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($validated['name'])])
            ->first();

        if ($existing) {
            if ($request->filled('lat') || $request->filled('lng')) {
                $existing->update($request->only(['lat', 'lng']));
            }

            return response()->json($existing->load('country'), 200);
        }

        $slug = ! empty($validated['slug']) ? $validated['slug'] : Str::slug($validated['name']);
        $baseSlug = $slug;
        $counter = 1;
        while (City::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }
        $validated['slug'] = $slug;

        $city = City::create($validated);

        return response()->json($city->load('country'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return response()->json(City::with(['country', 'neighbourhoods'])->findOrFail($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $city = City::findOrFail($id);
        $validated = $request->validate([
            'country_id' => 'sometimes|exists:countries,id',
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:255|unique:cities,slug,'.$city->id,
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'is_active' => 'sometimes|boolean',
        ]);

        $city->update($validated);

        return response()->json($city->load('country'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $city = City::findOrFail($id);
        $city->delete();

        return response()->json(null, 204);
    }
}
