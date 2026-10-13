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
            'slug' => 'nullable|string|max:100|unique:neighbourhoods,slug',
            'is_active' => 'sometimes|boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

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
            'slug' => 'sometimes|string|max:100|unique:neighbourhoods,slug,'.$neighbourhood->id,
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
