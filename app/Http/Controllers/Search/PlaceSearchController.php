<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Models\Geo\City;
use App\Models\Geo\Neighbourhood;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlaceSearchController extends Controller
{
    /**
     * Recherche de lieux contextuels (quartiers et villes réels de BANGO).
     */
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $queryLower = mb_strtolower($query, 'UTF-8');

        $places = [];

        // 1. Recherche par quartier
        $neighbourhoods = Neighbourhood::with('city')
            ->when($queryLower !== '', function ($q) use ($queryLower) {
                $q->where(DB::raw('LOWER(name)'), 'like', "%{$queryLower}%");
            })
            ->limit(15)
            ->get();

        foreach ($neighbourhoods as $n) {
            $cityName = $n->city?->name ?? '';
            $places[] = [
                'id' => "n_{$n->id}",
                'label' => $n->name,
                'detail' => $cityName ? "Quartier, {$cityName}" : 'Quartier',
                'city' => $cityName,
                'neighbourhood' => $n->name,
            ];
        }

        // 2. Recherche par ville si la liste n'est pas encore saturée
        if (count($places) < 20) {
            $cities = City::query()
                ->when($queryLower !== '', function ($q) use ($queryLower) {
                    $q->where(DB::raw('LOWER(name)'), 'like', "%{$queryLower}%");
                })
                ->limit(10)
                ->get();

            foreach ($cities as $c) {
                $places[] = [
                    'id' => "c_{$c->id}",
                    'label' => $c->name,
                    'detail' => 'Ville',
                    'city' => $c->name,
                    'neighbourhood' => null,
                ];
            }
        }

        return response()->json([
            'data' => $places,
        ]);
    }
}
