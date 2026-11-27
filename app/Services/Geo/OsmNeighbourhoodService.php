<?php

namespace App\Services\Geo;

use App\Models\Geo\City;
use App\Models\Geo\Neighbourhood;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OsmNeighbourhoodService
{
    public const OSM_ATTRIBUTION = '© contributeurs OpenStreetMap';

    /**
     * URL du serveur Overpass API (configurable via .env).
     */
    protected function getOverpassUrl(): string
    {
        return config('geo.overpass_url', env('OSM_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'));
    }

    /**
     * Récupère les quartiers d'une ville depuis OpenStreetMap si nécessaire,
     * les insère en base et retourne la collection complète.
     */
    public function syncNeighbourhoodsForCity(City $city): Collection
    {
        // Si les quartiers ont déjà été récupérés depuis OSM pour cette ville, retourner ceux en base
        if ($city->osm_fetched_at !== null) {
            return $city->neighbourhoods()->where('is_active', true)->where('status', 'verified')->get();
        }

        // Si la ville a déjà au moins un quartier actif vérifié, on marque osm_fetched_at et on retourne
        $existingCount = $city->neighbourhoods()->where('is_active', true)->where('status', 'verified')->count();
        if ($existingCount > 0) {
            $city->update(['osm_fetched_at' => now()]);

            return $city->neighbourhoods()->where('is_active', true)->where('status', 'verified')->get();
        }

        try {
            $osmNeighbourhoods = $this->queryOverpass($city);

            foreach ($osmNeighbourhoods as $item) {
                $name = trim($item['name']);
                if (empty($name)) {
                    continue;
                }

                $existing = Neighbourhood::where('city_id', $city->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name, 'UTF-8')])
                    ->first();

                if (! $existing) {
                    $slug = Str::slug($name);
                    $baseSlug = $slug;
                    $counter = 1;
                    while (Neighbourhood::where('slug', $slug)->exists()) {
                        $slug = "{$baseSlug}-{$counter}";
                        $counter++;
                    }

                    Neighbourhood::create([
                        'city_id' => $city->id,
                        'name' => $name,
                        'slug' => $slug,
                        'lat' => $item['lat'] ?? null,
                        'lng' => $item['lng'] ?? null,
                        'is_active' => true,
                        'status' => 'verified',
                        'source' => 'osm',
                        'osm_id' => $item['osm_id'] ?? null,
                    ]);
                }
            }

            $city->update(['osm_fetched_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning("OsmNeighbourhoodService: Erreur lors de la récupération OSM pour la ville {$city->name}: ".$e->getMessage());
        }

        return $city->neighbourhoods()->where('is_active', true)->where('status', 'verified')->get();
    }

    /**
     * Interroge Overpass API pour trouver les quartiers d'une ville.
     *
     * @return array<array{name: string, lat: ?float, lng: ?float, osm_id: string}>
     */
    public function queryOverpass(City $city): array
    {
        $overpassUrl = $this->getOverpassUrl();
        $lat = $city->lat;
        $lng = $city->lng;

        if ($lat !== null && $lng !== null) {
            // Requête par rayon autour des coordonnées de la ville
            $query = <<<OVERPASS
[out:json][timeout:12];
(
  node["place"~"suburb|quarter|neighbourhood"](around:25000,{$lat},{$lng});
  way["place"~"suburb|quarter|neighbourhood"](around:25000,{$lat},{$lng});
  relation["place"~"suburb|quarter|neighbourhood"](around:25000,{$lat},{$lng});
);
out center;
OVERPASS;
        } else {
            // Requête par nom de ville
            $escapedName = addslashes($city->name);
            $query = <<<OVERPASS
[out:json][timeout:12];
area["name"="{$escapedName}"]["boundary"="administrative"]->.searchArea;
(
  node["place"~"suburb|quarter|neighbourhood"](area.searchArea);
  way["place"~"suburb|quarter|neighbourhood"](area.searchArea);
  relation["place"~"suburb|quarter|neighbourhood"](area.searchArea);
);
out center;
OVERPASS;
        }

        $response = Http::timeout(10)
            ->withHeaders(['User-Agent' => 'BANGO-Backend/1.0 (contact@bangoapp.com)'])
            ->asForm()
            ->post($overpassUrl, ['data' => $query]);

        if (! $response->successful()) {
            Log::warning("Overpass API a répondu avec le statut {$response->status()} pour {$city->name}");

            return [];
        }

        $data = $response->json();
        $elements = $data['elements'] ?? [];
        $results = [];
        $seenNames = [];

        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = $tags['name:fr'] ?? ($tags['name'] ?? ($tags['name:en'] ?? null));

            if (! $name || isset($seenNames[mb_strtolower($name, 'UTF-8')])) {
                continue;
            }

            $seenNames[mb_strtolower($name, 'UTF-8')] = true;

            $itemLat = $el['lat'] ?? ($el['center']['lat'] ?? null);
            $itemLng = $el['lon'] ?? ($el['center']['lon'] ?? null);
            $osmId = ($el['type'] ?? 'node').'/'.($el['id'] ?? uniqid());

            $results[] = [
                'name' => $name,
                'lat' => $itemLat ? (float) $itemLat : null,
                'lng' => $itemLng ? (float) $itemLng : null,
                'osm_id' => $osmId,
            ];
        }

        return $results;
    }
}
