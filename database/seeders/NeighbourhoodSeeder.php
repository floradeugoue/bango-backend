<?php

namespace Database\Seeders;

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Services\Geo\NeighbourhoodCoordinatesCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class NeighbourhoodSeeder extends Seeder
{
    /**
     * Liste complete des quartiers de reference de Douala.
     *
     * @return array<int, string>
     */
    public static function doualaNeighbourhoods(): array
    {
        return [
            '4 Étages',
            '7 Dragons',
            'Aéroport',
            'Akwa',
            'Akwa Nord',
            'An 2000',
            'Ancien Abattoir',
            'Asquini',
            'Babimbi',
            'Babinga',
            'Babylone',
            'Badem Badem',
            'Bali',
            'Bangue',
            'Base navale',
            'Bassong',
            'Beedi',
            'Bekoko',
            'Bépanda',
            'Bépanda Mission',
            'Bépanda Omnisport',
            'Bépanda Petit Marché',
            'Bépanda Yonyong',
            'Bépélé',
            'Besseke',
            'Bessengue',
            'Bilongue',
            'Boadibo',
            'Bobongo',
            'Bodjongo',
            'Bois des Singes',
            'Bomono',
            'Bonabassem',
            'Bonabéri',
            'Bonabo',
            'Bonadibong',
            'Bonadiwoto',
            'Bonadoumbe',
            'Bonaissem',
            'Bonakouamouang',
            'Bonamatoumbe',
            'Bonambappe',
            'Bonamikano',
            'Bonamikengue',
            'Bonamouang',
            'Bonamoussadi',
            'Bonamouti',
            'Bonamoutongo',
            'Bonangando',
            'Bonangang',
            'Bonanjo',
            'Bonapriso',
            'Bonassama',
            'Bonateki',
            'Bonatone',
            'Bonendale',
            'Bonépoupa',
            'Bonewonda',
            'Boulevard Leclerc',
            'Brazzaville',
            'Brocante',
            'Camp Berteau',
            'Camp Sonel Ndokotti',
            'Camp Yabassi',
            'Carrefour Combi',
            'Carrefour des Billes',
            'Carrefour des Immeubles',
            'Champ de Tir',
            'Cité Berge',
            'Cité de la Paix',
            'Cité des Palmiers',
            'Cité SIC',
            'Congo',
            'Dakar',
            'Deïdo',
            'Denver',
            'Deux Églises',
            'Diboum',
            'Forêt Bar',
            'Gare Bassa',
            'Gendarmerie du Pont',
            'Génie militaire',
            'Grand Hangar',
            'Grand Moulin',
            'Grand Village',
            'Haute Tension',
            'Hôpital Général',
            'Indien',
            'Japoma',
            'Japoma Iboutou',
            'Kambo',
            'Kotto',
            'Kotto Village',
            'Lendi',
            'Logbaba',
            'Logbessou',
            'Logpom',
            'Mabanda',
            'Madagascar',
            'Maetur',
            'Maetur Bakoko',
            'Makéa',
            'Makepe',
            'Makepe Missoke',
            'Makepe Petit Pays',
            'Malangue',
            'Mandjap',
            'Marché Central',
            'Marché PK13',
            'Marché Saker',
            'Massoumbou',
            'Mbanga Bakoko',
            'Mbanga Pongo',
            'Mbangue',
            'Mbedi',
            'Mboko',
            'Mboppi',
            'Minkwelle',
            'Mongo',
            'Ndobo',
            'Ndogbati',
            'Ndogbong',
            'Ndogmbe',
            'Ndog-Passi',
            'Ndogsimbi',
            'Ndokoti',
            'Ndokoti',
            'New-Bell',
            'New Bell Bamiléké',
            'New Town',
            'Ngodi',
            'Ngodi Bakoko',
            'Ngonsoua',
            'Nguangue',
            'Ngwelle',
            'Nkane',
            'Nkolbong',
            'Nkonguih',
            'Nyalla',
            'Nylon',
            'Omnisport',
            'Oyack',
            'Parcours Vita',
            'Parking Dangote',
            'Petit Terrain',
            'PK10',
            'PK11',
            'PK12',
            'PK13',
            'PK14',
            'PK15',
            'PK16',
            'PK17',
            'PK18',
            'PK19',
            'PK20',
            'PK21',
            'PK22',
            'PK23',
            'PK24',
            'PK25',
            'PK26',
            'PK27',
            'PK28',
            'PK29',
            'PK30',
            'PK31',
            'PK32',
            'PK33',
            'PK34',
            'PK35',
            'PK36',
            'PK37',
            'PK38',
            'PK39',
            'PK40',
            'PK5',
            'PK8',
            'PK9',
            'Place de l\'UDEAC',
            'Plateau',
            'Rond-point 4e',
            'Rond-point Deido',
            'Rue de la Joie',
            'Sainte-Monique',
            'Saint-Tropez',
            'Saker',
            'Santa Barbara',
            'Shanghai',
            'Soboum',
            'Sodiko',
            'Stade Japoma',
            'Tianli',
            'Trois Cocotiers',
            'Vallée Bessengue',
            'Village',
            'Yansoki',
            'Yassa',
            'Yatchika',
            'Yato',
            'Yonlong',
            'Yonyong',
            'Youpwe',
            'ZI Bonabéri',
            'ZI Dibamba',
            'ZI Logbaba',
            'Zone portuaire',
            'Zone portuaire aval',
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $douala = City::where('slug', 'douala')->first();

        if (! $douala) {
            $cameroun = Country::where('code', 'CM')->first();
            $douala = City::updateOrCreate(
                ['slug' => 'douala'],
                ['name' => 'Douala', 'country_id' => $cameroun?->id, 'lat' => 4.0511, 'lng' => 9.7679, 'is_active' => true]
            );
        }

        foreach (self::doualaNeighbourhoods() as $name) {
            $baseSlug = Str::slug($name);
            $existing = Neighbourhood::where('slug', $baseSlug)->first();
            $slug = ($existing && $existing->city_id !== $douala->id)
                ? Str::slug('douala-'.$name)
                : $baseSlug;

            $coords = NeighbourhoodCoordinatesCatalog::lookup('douala', $name)
                ?? NeighbourhoodCoordinatesCatalog::lookup('douala', $slug)
                ?? ['lat' => 4.0511, 'lng' => 9.7679];

            Neighbourhood::updateOrCreate(
                [
                    'city_id' => $douala->id,
                    'slug' => $slug,
                ],
                [
                    'name' => $name,
                    'lat' => $coords['lat'] ?? 4.0511,
                    'lng' => $coords['lng'] ?? 9.7679,
                    'is_active' => true,
                ]
            );
        }
    }
}
