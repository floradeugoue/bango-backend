<?php

namespace App\Console\Commands\Geo;

use App\Models\Geo\Neighbourhood;
use App\Services\Geo\NeighbourhoodCoordinatesCatalog;
use App\Services\Geo\NeighbourhoodVerificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class BackfillNeighbourhoodCoordinatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geo:backfill-neighbourhood-coords
                            {--city= : Filtrer par ID, nom ou slug de ville}
                            {--only-active-cities : Ne traiter que les villes actives (villes ouvertes)}
                            {--nominatim : Interroger OpenStreetMap Nominatim pour les quartiers absents du catalogue statique}
                            {--limit=0 : Nombre maximal de quartiers à traiter (0 = illimité)}
                            {--sleep=1100 : Délai en millisecondes entre deux appels Nominatim}
                            {--dry-run : Simuler sans modifier la base de données}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Renseigne les coordonnées lat/lng manquantes des quartiers (catalogue statique puis Nominatim optionnel)';

    /**
     * Execute the console command.
     */
    public function handle(NeighbourhoodVerificationService $verificationService): int
    {
        $cityFilter = $this->option('city');
        $onlyActiveCities = (bool) $this->option('only-active-cities');
        $useNominatim = (bool) $this->option('nominatim');
        $limit = max(0, (int) $this->option('limit'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Neighbourhood::query()
            ->with(['city.country'])
            ->where(function ($q) {
                $q->whereNull('lat')->orWhereNull('lng');
            })
            ->orderBy('id');

        if ($onlyActiveCities) {
            $query->whereHas('city', function ($q) {
                $q->where('is_active', true)
                    ->whereHas('country', fn ($cq) => $cq->where('is_active', true));
            });
        }

        if ($cityFilter !== null && trim((string) $cityFilter) !== '') {
            $needle = trim((string) $cityFilter);
            $query->whereHas('city', function ($q) use ($needle) {
                if (is_numeric($needle)) {
                    $q->where('id', (int) $needle);
                } else {
                    $q->whereRaw('LOWER(name) = ?', [mb_strtolower($needle, 'UTF-8')])
                        ->orWhere('slug', $needle);
                }
            });
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $neighbourhoods = $query->get();

        if ($neighbourhoods->isEmpty()) {
            $this->info('Aucun quartier sans coordonnées lat/lng à traiter.');

            return self::SUCCESS;
        }

        $this->info("Quartiers sans coordonnées à traiter : {$neighbourhoods->count()}");

        $updatedCatalog = 0;
        $updatedNominatim = 0;
        $unresolved = 0;
        $nominatimCalls = 0;

        foreach ($neighbourhoods as $neighbourhood) {
            $city = $neighbourhood->city;
            if (! $city) {
                $unresolved++;

                continue;
            }

            $coords = NeighbourhoodCoordinatesCatalog::lookup($city->name, $neighbourhood->name)
                ?? NeighbourhoodCoordinatesCatalog::lookup($city->slug, $neighbourhood->slug)
                ?? NeighbourhoodCoordinatesCatalog::lookup($city->slug, $neighbourhood->name);

            $source = 'catalog';

            if ($coords === null && $useNominatim) {
                if ($nominatimCalls > 0 && $sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                $nominatimCalls++;
                $coords = $this->geocodeWithNominatim($neighbourhood, $verificationService);
                $source = 'nominatim';
            }

            if ($coords === null) {
                $unresolved++;

                continue;
            }

            if (! $dryRun) {
                $neighbourhood->update([
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                ]);
            }

            if ($source === 'catalog') {
                $updatedCatalog++;
            } else {
                $updatedNominatim++;
            }
        }

        $modeLabel = $dryRun ? ' [DRY-RUN]' : '';
        $this->info("Terminé{$modeLabel} — Catalogue : {$updatedCatalog} | Nominatim : {$updatedNominatim} | Non résolus : {$unresolved}");

        return self::SUCCESS;
    }

    /**
     * Géocode un quartier via OpenStreetMap Nominatim en vérifiant sa cohérence avec la ville parente.
     *
     * @return array{lat: float, lng: float}|null
     */
    protected function geocodeWithNominatim(
        Neighbourhood $neighbourhood,
        NeighbourhoodVerificationService $verificationService
    ): ?array {
        $city = $neighbourhood->city;
        if (! $city) {
            return null;
        }

        $countryName = $city->country?->name ?? '';
        $countryIso2 = $city->country?->iso2 ? strtolower($city->country->iso2) : null;
        $queryParts = array_filter([$neighbourhood->name, $city->name, $countryName]);

        $params = [
            'q' => implode(', ', $queryParts),
            'format' => 'json',
            'limit' => 1,
        ];

        if ($countryIso2) {
            $params['countrycodes'] = $countryIso2;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'User-Agent' => (string) config('geo.geocoding.user_agent', 'BangoBackend/1.0 (+https://bango.app)'),
                    'Accept-Language' => 'fr,en',
                ])
                ->get((string) config('geo.geocoding.nominatim_url', 'https://nominatim.openstreetmap.org/search'), $params);

            if (! $response->successful()) {
                return null;
            }

            $first = $response->json('0');
            if (! is_array($first) || ! isset($first['lat'], $first['lon'])) {
                return null;
            }

            $lat = round((float) $first['lat'], 7);
            $lng = round((float) $first['lon'], 7);

            // Garde-fou : rejeter un homonyme géocodé trop loin du centre de la ville parente
            if ($city->lat !== null && $city->lng !== null) {
                $maxCityDistance = (float) config('geo.geocoding.max_city_distance_km', 40.0);
                $distanceFromCity = $verificationService->haversineDistanceKm(
                    $lat,
                    $lng,
                    (float) $city->lat,
                    (float) $city->lng
                );

                if ($distanceFromCity > $maxCityDistance) {
                    return null;
                }
            }

            return [
                'lat' => $lat,
                'lng' => $lng,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
