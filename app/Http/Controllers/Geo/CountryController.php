<?php

namespace App\Http\Controllers\Geo;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(\App\Models\Geo\Country::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'code' => 'required|string',
            'iso2' => 'required|string',
            'iso3' => 'required|string',
            'currency_id' => 'required|exists:currencies,id',
            'is_active' => 'sometimes|boolean'
        ]);

        $country = \App\Models\Geo\Country::create($validated);

        return response()->json($country, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return response()->json(\App\Models\Geo\Country::findOrFail($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $country = \App\Models\Geo\Country::findOrFail($id);
        $country->update($request->all());
        return response()->json($country);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $country = \App\Models\Geo\Country::findOrFail($id);
        $country->delete();
        return response()->json(null, 204);
    }
}
