<?php

namespace App\Http\Controllers\Geo;

use App\Http\Controllers\Controller;
use App\Models\Geo\Currency;
use Illuminate\Http\Request;

class CurrencyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Currency::query();
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $currencies = $query->get()->map(function (Currency $currency) {
            return [
                'id' => $currency->id,
                'name' => $currency->name,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'rate_from_xaf' => (float) ($currency->rate_from_xaf ?? 1.0),
                'round_to' => (int) ($currency->round_to ?? 0),
                'is_active' => (bool) $currency->is_active,
            ];
        });

        return response()->json($currencies);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|size:3|unique:currencies,code',
            'symbol' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $currency = Currency::create($validated);

        return response()->json($currency, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return response()->json(Currency::findOrFail($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $currency = Currency::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|size:3|unique:currencies,code,'.$currency->id,
            'symbol' => 'nullable|string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $currency->update($validated);

        return response()->json($currency);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $currency = Currency::findOrFail($id);
        $currency->delete();

        return response()->json(null, 204);
    }
}
