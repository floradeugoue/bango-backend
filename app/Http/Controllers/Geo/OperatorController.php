<?php

namespace App\Http\Controllers\Geo;

use App\Http\Controllers\Controller;
use App\Models\Geo\Operator;
use Illuminate\Http\Request;

class OperatorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Operator::with('country');

        if ($request->has('country_id')) {
            $query->where('country_id', $request->country_id);
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
            'code' => 'required|string|max:50|unique:operators,code',
            'logo_url' => 'nullable|string|max:255',
            'prefixes' => 'nullable|array',
            'prefixes.*' => 'string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $existing = Operator::where('code', $validated['code'])->first();
        if ($existing) {
            $existing->update($validated);

            return response()->json($existing->load('country'), 200);
        }

        $operator = Operator::create($validated);

        return response()->json($operator->load('country'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        return response()->json(Operator::with('country')->findOrFail($id));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $operator = Operator::findOrFail($id);
        $validated = $request->validate([
            'country_id' => 'sometimes|exists:countries,id',
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:50|unique:operators,code,'.$operator->id,
            'logo_url' => 'nullable|string|max:255',
            'prefixes' => 'nullable|array',
            'prefixes.*' => 'string|max:10',
            'is_active' => 'sometimes|boolean',
        ]);

        $operator->update($validated);

        return response()->json($operator->load('country'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $operator = Operator::findOrFail($id);
        $operator->delete();

        return response()->json(null, 204);
    }
}
