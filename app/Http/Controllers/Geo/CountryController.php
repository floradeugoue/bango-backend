<?php

namespace App\Http\Controllers\Geo;

use App\Http\Controllers\Controller;
use App\Models\Geo\Country;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Country::with('currency');
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
            'name' => 'required|string',
            'name_en' => 'sometimes|nullable|string',
            'code' => 'required|string|unique:countries,code',
            'iso2' => 'required|string|size:2|unique:countries,iso2',
            'iso3' => 'required|string|size:3|unique:countries,iso3',
            'phone_code' => 'sometimes|nullable|string',
            'currency_id' => 'required|exists:currencies,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $country = Country::create($validated);

        return response()->json($country->load('currency'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return response()->json(Country::with(['currency', 'cities', 'operators'])->findOrFail($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $country = Country::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'name_en' => 'sometimes|nullable|string',
            'code' => 'sometimes|string|unique:countries,code,'.$country->id,
            'iso2' => 'sometimes|string|size:2|unique:countries,iso2,'.$country->id,
            'iso3' => 'sometimes|string|size:3|unique:countries,iso3,'.$country->id,
            'phone_code' => 'sometimes|nullable|string',
            'currency_id' => 'sometimes|exists:currencies,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $country->update($validated);

        return response()->json($country->load('currency'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $country = Country::findOrFail($id);
        $country->delete();

        return response()->json(null, 204);
    }
}
