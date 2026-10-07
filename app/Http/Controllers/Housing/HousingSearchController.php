<?php

namespace App\Http\Controllers\Housing;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\HousingSearch;

class HousingSearchController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $search = $request->user()->housingSearch;
        
        if (!$search) {
            return response()->json(null);
        }

        return response()->json([
            'intent' => $search->intent?->value,
            'types' => $search->types,
            'budget' => $search->budget,
            'currency' => $search->currency,
            'city' => $search->city,
            'neighbourhoods' => $search->neighbourhoods,
            'place' => $search->place,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'intent' => 'nullable|in:active,browsing,no',
            'types' => 'nullable|array',
            'budget' => 'nullable|array|size:2',
            'currency' => 'nullable|string',
            'city' => 'nullable|string',
            'neighbourhoods' => 'nullable|array',
            'place' => 'nullable|string',
        ]);

        $search = $request->user()->housingSearch()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->only(['intent', 'types', 'budget', 'currency', 'city', 'neighbourhoods', 'place'])
        );

        return response()->json(['success' => true]);
    }
}
