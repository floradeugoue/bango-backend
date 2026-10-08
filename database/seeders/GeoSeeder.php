<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Geo\Currency;
use App\Models\Geo\Country;
use App\Models\Geo\City;
use App\Models\Geo\Neighbourhood;
use App\Models\Geo\Operator;

class GeoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Currency
        $xaf = Currency::firstOrCreate(
            ['code' => 'XAF'],
            ['name' => 'Franc CFA BEAC', 'symbol' => 'FCFA']
        );

        // 2. Country
        $cameroun = Country::firstOrCreate(
            ['code' => 'CM'],
            [
                'name' => 'Cameroun',
                'iso2' => 'CM',
                'iso3' => 'CMR',
                'phone_code' => '+237',
                'currency_id' => $xaf->id,
            ]
        );

        // 3. Cities
        $douala = City::firstOrCreate(
            ['slug' => 'douala'],
            ['name' => 'Douala', 'country_id' => $cameroun->id]
        );

        $yaounde = City::firstOrCreate(
            ['slug' => 'yaounde'],
            ['name' => 'Yaoundé', 'country_id' => $cameroun->id]
        );

        // 4. Neighbourhoods
        $quartiersDouala = ['Bonapriso', 'Akwa', 'Bonanjo', 'Deido', 'Makepe'];
        foreach ($quartiersDouala as $q) {
            Neighbourhood::firstOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($q)],
                ['name' => $q, 'city_id' => $douala->id]
            );
        }

        $quartiersYaounde = ['Bastos', 'Biyem-Assi', 'Mokolo'];
        foreach ($quartiersYaounde as $q) {
            Neighbourhood::firstOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($q)],
                ['name' => $q, 'city_id' => $yaounde->id]
            );
        }

        // 5. Operators
        Operator::firstOrCreate(
            ['code' => 'orange_cm'],
            ['name' => 'Orange', 'country_id' => $cameroun->id, 'logo_url' => 'https://example.com/orange.png']
        );

        Operator::firstOrCreate(
            ['code' => 'mtn_cm'],
            ['name' => 'MTN', 'country_id' => $cameroun->id, 'logo_url' => 'https://example.com/mtn.png']
        );
    }
}
