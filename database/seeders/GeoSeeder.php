<?php

namespace Database\Seeders;

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\Geo\Operator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GeoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Currencies
        $xaf = Currency::firstOrCreate(
            ['code' => 'XAF'],
            ['name' => 'Franc CFA BEAC', 'symbol' => 'FCFA']
        );

        $xof = Currency::firstOrCreate(
            ['code' => 'XOF'],
            ['name' => 'Franc CFA BCEAO', 'symbol' => 'FCFA']
        );

        $eur = Currency::firstOrCreate(
            ['code' => 'EUR'],
            ['name' => 'Euro', 'symbol' => '€']
        );

        // 2. Countries
        $cameroun = Country::updateOrCreate(
            ['code' => 'CM'],
            [
                'name' => 'Cameroun',
                'name_en' => 'Cameroon',
                'iso2' => 'CM',
                'iso3' => 'CMR',
                'phone_code' => '+237',
                'currency_id' => $xaf->id,
            ]
        );

        $cotedivoire = Country::updateOrCreate(
            ['code' => 'CI'],
            [
                'name' => "Côte d'Ivoire",
                'name_en' => 'Ivory Coast',
                'iso2' => 'CI',
                'iso3' => 'CIV',
                'phone_code' => '+225',
                'currency_id' => $xof->id,
            ]
        );

        $senegal = Country::updateOrCreate(
            ['code' => 'SN'],
            [
                'name' => 'Sénégal',
                'name_en' => 'Senegal',
                'iso2' => 'SN',
                'iso3' => 'SEN',
                'phone_code' => '+221',
                'currency_id' => $xof->id,
            ]
        );

        $gabon = Country::updateOrCreate(
            ['code' => 'GA'],
            [
                'name' => 'Gabon',
                'name_en' => 'Gabon',
                'iso2' => 'GA',
                'iso3' => 'GAB',
                'phone_code' => '+241',
                'currency_id' => $xaf->id,
            ]
        );

        $france = Country::updateOrCreate(
            ['code' => 'FR'],
            [
                'name' => 'France',
                'name_en' => 'France',
                'iso2' => 'FR',
                'iso3' => 'FRA',
                'phone_code' => '+33',
                'currency_id' => $eur->id,
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

        $abidjan = City::firstOrCreate(
            ['slug' => 'abidjan'],
            ['name' => 'Abidjan', 'country_id' => $cotedivoire->id]
        );

        $dakar = City::firstOrCreate(
            ['slug' => 'dakar'],
            ['name' => 'Dakar', 'country_id' => $senegal->id]
        );

        $libreville = City::firstOrCreate(
            ['slug' => 'libreville'],
            ['name' => 'Libreville', 'country_id' => $gabon->id]
        );

        $paris = City::firstOrCreate(
            ['slug' => 'paris'],
            ['name' => 'Paris', 'country_id' => $france->id]
        );

        // 4. Neighbourhoods
        $quartiersDouala = ['Bonapriso', 'Akwa', 'Bonanjo', 'Deido', 'Makepe'];
        foreach ($quartiersDouala as $q) {
            Neighbourhood::firstOrCreate(
                ['slug' => Str::slug($q)],
                ['name' => $q, 'city_id' => $douala->id]
            );
        }

        $quartiersYaounde = ['Bastos', 'Biyem-Assi', 'Mokolo'];
        foreach ($quartiersYaounde as $q) {
            Neighbourhood::firstOrCreate(
                ['slug' => Str::slug($q)],
                ['name' => $q, 'city_id' => $yaounde->id]
            );
        }

        $quartiersAbidjan = ['Cocody', 'Plateau', 'Marcory', 'Yopougon'];
        foreach ($quartiersAbidjan as $q) {
            Neighbourhood::firstOrCreate(
                ['slug' => Str::slug($q)],
                ['name' => $q, 'city_id' => $abidjan->id]
            );
        }

        // 5. Operators
        Operator::firstOrCreate(
            ['code' => 'orange_cm'],
            ['name' => 'Orange Cameroun', 'country_id' => $cameroun->id, 'logo_url' => 'https://example.com/orange.png']
        );

        Operator::firstOrCreate(
            ['code' => 'mtn_cm'],
            ['name' => 'MTN Cameroun', 'country_id' => $cameroun->id, 'logo_url' => 'https://example.com/mtn.png']
        );

        Operator::firstOrCreate(
            ['code' => 'orange_ci'],
            ['name' => 'Orange CI', 'country_id' => $cotedivoire->id, 'logo_url' => 'https://example.com/orange.png']
        );

        Operator::firstOrCreate(
            ['code' => 'mtn_ci'],
            ['name' => 'MTN CI', 'country_id' => $cotedivoire->id, 'logo_url' => 'https://example.com/mtn.png']
        );

        Operator::firstOrCreate(
            ['code' => 'orange_sn'],
            ['name' => 'Orange Sénégal', 'country_id' => $senegal->id, 'logo_url' => 'https://example.com/orange.png']
        );
    }
}
