<?php

use App\Services\Geo\NeighbourhoodCoordinatesCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $rows = DB::table('neighbourhoods')
            ->join('cities', 'cities.id', '=', 'neighbourhoods.city_id')
            ->where(function ($query) {
                $query->whereNull('neighbourhoods.lat')
                    ->orWhereNull('neighbourhoods.lng');
            })
            ->select([
                'neighbourhoods.id',
                'neighbourhoods.name as neighbourhood_name',
                'neighbourhoods.slug as neighbourhood_slug',
                'cities.name as city_name',
                'cities.slug as city_slug',
            ])
            ->get();

        foreach ($rows as $row) {
            $coords = NeighbourhoodCoordinatesCatalog::lookup($row->city_name, $row->neighbourhood_name)
                ?? NeighbourhoodCoordinatesCatalog::lookup($row->city_slug, $row->neighbourhood_slug)
                ?? NeighbourhoodCoordinatesCatalog::lookup($row->city_slug, $row->neighbourhood_name);

            if ($coords === null) {
                continue;
            }

            DB::table('neighbourhoods')
                ->where('id', $row->id)
                ->update([
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Les coordonnées renseignées proviennent du référentiel géographique vérifié ;
     * les supprimer au rollback détruirait potentiellement des saisies administratives.
     */
    public function down(): void
    {
        // Intentionnellement vide pour éviter une perte de données géographiques.
    }
};
