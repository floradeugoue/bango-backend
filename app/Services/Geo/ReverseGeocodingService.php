<?php

namespace App\Services\Geo;

use App\Models\Geo\City;
use App\Models\Geo\Neighbourhood;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReverseGeocodingService
{
    public const ATTRIBUTION = '© contributeurs OpenStreetMap';

    /**
     * Effectue un géocodage inverse d'une position GPS (lat, lng)
     * pour identifier le quartier (suburb, quarter, neighbourhood) et la ville.
     *
     * @return array{
     *     neighbourhood: ?string,
     *     city: ?string,
     *     country_code: ?string,
     *     resolved_neighbourhood_id: ?int,
     *     attribution: string
     * }
     */
    public function reverseGeocode(float $latitude, float $longitude, ?City $preferredCity = null): array
    {
        $roundedLat = round($latitude, 4);
        $roundedLng = round($longitude, 4);
        $cacheKey = "geo_reverse_{$roundedLat}_{$roundedLng}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $driver = config('geo.reverse_driver', env('GEO_REVERSE_DRIVER', 'nominatim'));

        $result = $driver === 'photon'
            ? $this->queryPhoton($latitude, $longitude)
            : $this->queryNominatim($latitude, $longitude);

        // Tentative de résolution de l'ID du quartier en base locale si possible
        $resolvedNeighbourhoodId = null;
        if (! empty($result['neighbourhood'])) {
            $nQuery = Neighbourhood::query()
                ->where('is_active', true)
                ->where('status', 'verified')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($result['neighbourhood'], 'UTF-8')]);

            if ($preferredCity) {
                $nQuery->where('city_id', $preferredCity->id);
            }

            $neighbourhoodModel = $nQuery->first();
            $resolvedNeighbourhoodId = $neighbourhoodModel?->id;
        }

        $output = [
            'neighbourhood' => $result['neighbourhood'],
            'city' => $result['city'],
            'country_code' => $result['country_code'],
            'resolved_neighbourhood_id' => $resolvedNeighbourhoodId,
            'attribution' => self::ATTRIBUTION,
        ];

        // Mettre en cache pour 30 jours
        Cache::put($cacheKey, $output, now()->addDays(30));

        return $output;
    }

    /**
     * Interroge Nominatim (OpenStreetMap).
     *
     * @return array{neighbourhood: ?string, city: ?string, country_code: ?string}
     */
    protected function queryNominatim(float $latitude, float $longitude): array
    {
        $baseUrl = config('geo.nominatim_url', env('GEO_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/reverse'));

        try {
            $response = Http::timeout(6)
                ->withHeaders([
                    'User-Agent' => 'BANGO-Backend/1.0 (contact@bangoapp.com)',
                    'Accept-Language' => 'fr,en',
                ])
                ->get($baseUrl, [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'zoom' => 18,
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                Log::warning("Nominatim reverse geocode returned status {$response->status()}");

                return ['neighbourhood' => null, 'city' => null, 'country_code' => null];
            }

            $data = $response->json();
            $address = $data['address'] ?? [];

            $neighbourhood = $address['suburb']
                ?? ($address['quarter']
                ?? ($address['neighbourhood']
                ?? ($address['city_district']
                ?? ($address['residential'] ?? null))));

            $city = $address['city']
                ?? ($address['town']
                ?? ($address['municipality']
                ?? ($address['village'] ?? null)));

            $countryCode = isset($address['country_code']) ? strtoupper($address['country_code']) : null;

            return [
                'neighbourhood' => $neighbourhood ? trim($neighbourhood) : null,
                'city' => $city ? trim($city) : null,
                'country_code' => $countryCode,
            ];
        } catch (\Throwable $e) {
            Log::warning('Erreur lors du géocodage inverse Nominatim: '.$e->getMessage());

            return ['neighbourhood' => null, 'city' => null, 'country_code' => null];
        }
    }

    /**
     * Interroge Photon (Komoot/OSM).
     *
     * @return array{neighbourhood: ?string, city: ?string, country_code: ?string}
     */
    protected function queryPhoton(float $latitude, float $longitude): array
    {
        $baseUrl = config('geo.photon_url', env('GEO_PHOTON_URL', 'https://photon.komoot.io/reverse'));

        try {
            $response = Http::timeout(6)
                ->withHeaders([
                    'User-Agent' => 'BANGO-Backend/1.0 (contact@bangoapp.com)',
                ])
                ->get($baseUrl, [
                    'lat' => $latitude,
                    'lon' => $longitude,
                ]);

            if (! $response->successful()) {
                return ['neighbourhood' => null, 'city' => null, 'country_code' => null];
            }

            $data = $response->json();
            $features = $data['features'] ?? [];
            if (empty($features)) {
                return ['neighbourhood' => null, 'city' => null, 'country_code' => null];
            }

            $properties = $features[0]['properties'] ?? [];

            $neighbourhood = $properties['district']
                ?? ($properties['suburb']
                ?? ($properties['neighbourhood']
                ?? ($properties['locality'] ?? null)));

            $city = $properties['city'] ?? ($properties['town'] ?? null);
            $countryCode = isset($properties['countrycode']) ? strtoupper($properties['countrycode']) : null;

            return [
                'neighbourhood' => $neighbourhood ? trim($neighbourhood) : null,
                'city' => $city ? trim($city) : null,
                'country_code' => $countryCode,
            ];
        } catch (\Throwable $e) {
            Log::warning('Erreur lors du géocodage inverse Photon: '.$e->getMessage());

            return ['neighbourhood' => null, 'city' => null, 'country_code' => null];
        }
    }
}
